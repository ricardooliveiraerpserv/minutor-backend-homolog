<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Expense;
use App\Models\User;
use App\Models\Project;
use App\Models\ExpenseCategory;
use Spatie\Permission\Models\Role;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $approver;
    protected User $admin;
    protected Project $project;
    protected ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        // Criar usuários e papéis para testes
        $adminRole = Role::create(['name' => 'Administrador']);
        $consultantRole = Role::create(['name' => 'Consultor']);

        $this->user = User::factory()->create();
        $this->user->assignRole($consultantRole);

        $this->approver = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole($adminRole);

        // Criar projeto e categoria
        $this->project = Project::factory()->create();
        $this->category = ExpenseCategory::factory()->create();

        // Fazer o approver ser aprovador do projeto
        $this->project->approvers()->attach($this->approver->id);
    }

    /** @test */
    public function expense_can_be_created()
    {
        $expense = Expense::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'expense_category_id' => $this->category->id,
        ]);

        $this->assertInstanceOf(Expense::class, $expense);
        $this->assertEquals($this->user->id, $expense->user_id);
        $this->assertEquals($this->project->id, $expense->project_id);
        $this->assertEquals(Expense::STATUS_PENDING, $expense->status);
    }

    /** @test */
    public function expense_relationships()
    {
        $expense = Expense::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'expense_category_id' => $this->category->id,
        ]);

        $this->assertInstanceOf(User::class, $expense->user);
        $this->assertInstanceOf(Project::class, $expense->project);
        $this->assertInstanceOf(ExpenseCategory::class, $expense->category);
        $this->assertEquals($this->user->id, $expense->user->id);
        $this->assertEquals($this->project->id, $expense->project->id);
    }

    /** @test */
    public function status_display_accessor_returns_portuguese()
    {
        $expense = Expense::factory()->create(['status' => Expense::STATUS_PENDING]);
        $this->assertEquals('Pendente', $expense->status_display);

        $expense->status = Expense::STATUS_APPROVED;
        $this->assertEquals('Aprovado', $expense->status_display);

        $expense->status = Expense::STATUS_REJECTED;
        $this->assertEquals('Rejeitado', $expense->status_display);

        $expense->status = Expense::STATUS_ADJUSTMENT_REQUESTED;
        $this->assertEquals('Ajuste Solicitado', $expense->status_display);
    }

    /** @test */
    public function expense_type_display_accessor_returns_portuguese()
    {
        $expense = Expense::factory()->create(['expense_type' => Expense::TYPE_CORPORATE_CARD]);
        $this->assertEquals('Cartão Corporativo', $expense->expense_type_display);

        $expense->expense_type = Expense::TYPE_REIMBURSEMENT;
        $this->assertEquals('Reembolso', $expense->expense_type_display);
    }

    /** @test */
    public function payment_method_display_accessor_returns_portuguese()
    {
        $expense = Expense::factory()->create(['payment_method' => Expense::PAYMENT_CASH]);
        $this->assertEquals('Dinheiro', $expense->payment_method_display);

        $expense->payment_method = Expense::PAYMENT_PIX;
        $this->assertEquals('PIX', $expense->payment_method_display);
    }

    /** @test */
    public function formatted_amount_accessor_returns_brazilian_currency()
    {
        $expense = Expense::factory()->create(['amount' => 78.90]);
        $this->assertEquals('R$ 78,90', $expense->formatted_amount);

        $expense->amount = 1234.56;
        $this->assertEquals('R$ 1.234,56', $expense->formatted_amount);
    }

    /** @test */
    public function can_be_edited_only_when_pending_or_adjustment_requested()
    {
        $expense = Expense::factory()->pending()->create();
        $this->assertTrue($expense->canBeEdited());

        $expense = Expense::factory()->adjustmentRequested()->create();
        $this->assertTrue($expense->canBeEdited());

        $expense = Expense::factory()->approved()->create();
        $this->assertFalse($expense->canBeEdited());

        $expense = Expense::factory()->rejected()->create();
        $this->assertFalse($expense->canBeEdited());
    }

    /** @test */
    public function can_be_approved_only_when_pending_or_adjustment_requested()
    {
        $expense = Expense::factory()->pending()->create();
        $this->assertTrue($expense->canBeApproved());

        $expense = Expense::factory()->adjustmentRequested()->create();
        $this->assertTrue($expense->canBeApproved());

        $expense = Expense::factory()->approved()->create();
        $this->assertFalse($expense->canBeApproved());

        $expense = Expense::factory()->rejected()->create();
        $this->assertFalse($expense->canBeApproved());
    }

    /** @test */
    public function project_approver_can_approve_expense()
    {
        $expense = Expense::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $this->assertTrue($expense->canBeApprovedBy($this->approver));
    }

    /** @test */
    public function non_approver_cannot_approve_expense()
    {
        $nonApprover = User::factory()->create();
        $expense = Expense::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $this->assertFalse($expense->canBeApprovedBy($nonApprover));
    }

    /** @test */
    public function admin_can_approve_any_expense()
    {
        $expense = Expense::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $this->assertTrue($expense->canBeApprovedBy($this->admin));
    }

    /** @test */
    public function approve_method_updates_status_and_metadata()
    {
        $expense = Expense::factory()->pending()->create([
            'project_id' => $this->project->id,
        ]);

        $result = $expense->approve($this->approver, true);

        $this->assertTrue($result);
        $this->assertEquals(Expense::STATUS_APPROVED, $expense->status);
        $this->assertEquals($this->approver->id, $expense->reviewed_by);
        $this->assertNotNull($expense->reviewed_at);
        $this->assertTrue($expense->charge_client);
        $this->assertNull($expense->rejection_reason);
    }

    /** @test */
    public function reject_method_updates_status_and_reason()
    {
        $expense = Expense::factory()->pending()->create([
            'project_id' => $this->project->id,
        ]);

        $reason = 'Comprovante ilegível';
        $result = $expense->reject($this->approver, $reason);

        $this->assertTrue($result);
        $this->assertEquals(Expense::STATUS_REJECTED, $expense->status);
        $this->assertEquals($this->approver->id, $expense->reviewed_by);
        $this->assertNotNull($expense->reviewed_at);
        $this->assertEquals($reason, $expense->rejection_reason);
        $this->assertFalse($expense->charge_client);
    }

    /** @test */
    public function request_adjustment_method_updates_status_and_reason()
    {
        $expense = Expense::factory()->pending()->create([
            'project_id' => $this->project->id,
        ]);

        $reason = 'Favor corrigir a data';
        $result = $expense->requestAdjustment($this->approver, $reason);

        $this->assertTrue($result);
        $this->assertEquals(Expense::STATUS_ADJUSTMENT_REQUESTED, $expense->status);
        $this->assertEquals($this->approver->id, $expense->reviewed_by);
        $this->assertNotNull($expense->reviewed_at);
        $this->assertEquals($reason, $expense->rejection_reason);
    }

    /** @test */
    public function cannot_approve_already_processed_expense()
    {
        $expense = Expense::factory()->approved()->create([
            'project_id' => $this->project->id,
        ]);

        $result = $expense->approve($this->approver);
        $this->assertFalse($result);
    }

    /** @test */
    public function scopes_work_correctly()
    {
        $otherUser = User::factory()->create();
        $otherProject = Project::factory()->create();

        Expense::factory()->create(['user_id' => $this->user->id]);
        Expense::factory()->create(['user_id' => $otherUser->id]);
        Expense::factory()->create(['project_id' => $this->project->id]);
        Expense::factory()->create(['project_id' => $otherProject->id]);

        // Scope forUser
        $userExpenses = Expense::forUser($this->user->id)->get();
        $this->assertEquals(1, $userExpenses->count());

        // Scope forProject
        $projectExpenses = Expense::forProject($this->project->id)->get();
        $this->assertEquals(1, $projectExpenses->count());
    }

    /** @test */
    public function period_scope_filters_by_date_range()
    {
        Expense::factory()->create(['expense_date' => '2024-01-15']);
        Expense::factory()->create(['expense_date' => '2024-02-15']);
        Expense::factory()->create(['expense_date' => '2024-03-15']);

        $expenses = Expense::inPeriod('2024-01-01', '2024-02-28')->get();
        $this->assertEquals(2, $expenses->count());
    }

    /** @test */
    public function get_statuses_returns_all_statuses()
    {
        $statuses = Expense::getStatuses();
        
        $this->assertArrayHasKey(Expense::STATUS_PENDING, $statuses);
        $this->assertArrayHasKey(Expense::STATUS_APPROVED, $statuses);
        $this->assertArrayHasKey(Expense::STATUS_REJECTED, $statuses);
        $this->assertArrayHasKey(Expense::STATUS_ADJUSTMENT_REQUESTED, $statuses);
        $this->assertEquals('Pendente', $statuses[Expense::STATUS_PENDING]);
    }

    /** @test */
    public function get_expense_types_returns_all_types()
    {
        $types = Expense::getExpenseTypes();
        
        $this->assertArrayHasKey(Expense::TYPE_CORPORATE_CARD, $types);
        $this->assertArrayHasKey(Expense::TYPE_REIMBURSEMENT, $types);
        $this->assertEquals('Cartão Corporativo', $types[Expense::TYPE_CORPORATE_CARD]);
    }

    /** @test */
    public function get_payment_methods_returns_all_methods()
    {
        $methods = Expense::getPaymentMethods();
        
        $this->assertArrayHasKey(Expense::PAYMENT_CASH, $methods);
        $this->assertArrayHasKey(Expense::PAYMENT_PIX, $methods);
        $this->assertEquals('Dinheiro', $methods[Expense::PAYMENT_CASH]);
        $this->assertEquals('PIX', $methods[Expense::PAYMENT_PIX]);
    }
}
