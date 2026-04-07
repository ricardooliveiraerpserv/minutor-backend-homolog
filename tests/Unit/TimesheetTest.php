<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

class TimesheetTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $approver;
    protected Customer $customer;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Criar papéis necessários para os testes
        Role::create(['name' => 'Administrator']);
        
        $this->user = User::factory()->create();
        $this->approver = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->project = Project::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => Project::STATUS_STARTED
        ]);
        
        // Adicionar o aprovador ao projeto
        $this->project->approvers()->attach($this->approver->id);
    }

    public function test_timesheet_can_be_created()
    {
        $timesheet = Timesheet::create([
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'project_id' => $this->project->id,
            'date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'observation' => 'Desenvolvimento de feature',
            'ticket' => 'TICKET-123'
        ]);

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'status' => Timesheet::STATUS_PENDING
        ]);
    }

    public function test_effort_is_calculated_automatically()
    {
        $timesheet = new Timesheet([
            'start_time' => '09:00',
            'end_time' => '17:00'
        ]);

        $timesheet->calculateEffort();

        // 8 horas = 480 minutos
        $this->assertEquals(480, $timesheet->effort_minutes);
    }

    public function test_effort_calculation_handles_overnight_work()
    {
        $timesheet = new Timesheet([
            'start_time' => '22:00',
            'end_time' => '06:00'
        ]);

        $timesheet->calculateEffort();

        // 8 horas (22:00 até 06:00 do dia seguinte) = 480 minutos
        $this->assertEquals(480, $timesheet->effort_minutes);
    }

    public function test_effort_hours_accessor_returns_formatted_time()
    {
        $timesheet = new Timesheet();
        $timesheet->effort_minutes = 485; // 8 horas e 5 minutos
        
        $this->assertEquals('8:05', $timesheet->effort_hours);
    }

    public function test_effort_hours_accessor_handles_zero_minutes()
    {
        $timesheet = new Timesheet();
        $timesheet->effort_minutes = 0;
        
        $this->assertEquals('0:00', $timesheet->effort_hours);
    }

    public function test_status_display_accessor_returns_portuguese()
    {
        $timesheet = new Timesheet(['status' => Timesheet::STATUS_PENDING]);
        $this->assertEquals('Pendente', $timesheet->status_display);

        $timesheet->status = Timesheet::STATUS_APPROVED;
        $this->assertEquals('Aprovado', $timesheet->status_display);

        $timesheet->status = Timesheet::STATUS_REJECTED;
        $this->assertEquals('Rejeitado', $timesheet->status_display);
    }

    public function test_timesheet_relationships()
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'project_id' => $this->project->id,
        ]);

        $this->assertEquals($this->user->id, $timesheet->user->id);
        $this->assertEquals($this->customer->id, $timesheet->customer->id);
        $this->assertEquals($this->project->id, $timesheet->project->id);
    }

    public function test_can_be_edited_only_when_pending()
    {
        $timesheet = Timesheet::factory()->create(['status' => Timesheet::STATUS_PENDING]);
        $this->assertTrue($timesheet->canBeEdited());

        $timesheet->status = Timesheet::STATUS_APPROVED;
        $this->assertFalse($timesheet->canBeEdited());

        $timesheet->status = Timesheet::STATUS_REJECTED;
        $this->assertFalse($timesheet->canBeEdited());
    }

    public function test_can_be_approved_only_when_pending()
    {
        $timesheet = Timesheet::factory()->create(['status' => Timesheet::STATUS_PENDING]);
        $this->assertTrue($timesheet->canBeApproved());

        $timesheet->status = Timesheet::STATUS_APPROVED;
        $this->assertFalse($timesheet->canBeApproved());

        $timesheet->status = Timesheet::STATUS_REJECTED;
        $this->assertFalse($timesheet->canBeApproved());
    }

    public function test_project_approver_can_approve_timesheet()
    {
        $timesheet = Timesheet::factory()->create([
            'project_id' => $this->project->id,
            'status' => Timesheet::STATUS_PENDING
        ]);

        $this->assertTrue($timesheet->canBeApprovedBy($this->approver));
    }

    public function test_non_approver_cannot_approve_timesheet()
    {
        $nonApprover = User::factory()->create();
        $timesheet = Timesheet::factory()->create([
            'project_id' => $this->project->id,
            'status' => Timesheet::STATUS_PENDING
        ]);

        $this->assertFalse($timesheet->canBeApprovedBy($nonApprover));
    }

    public function test_admin_can_approve_any_timesheet()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        
        $timesheet = Timesheet::factory()->create([
            'project_id' => $this->project->id,
            'status' => Timesheet::STATUS_PENDING
        ]);

        $this->assertTrue($timesheet->canBeApprovedBy($admin));
    }

    public function test_approve_method_updates_status_and_metadata()
    {
        $timesheet = Timesheet::factory()->create([
            'project_id' => $this->project->id,
            'status' => Timesheet::STATUS_PENDING
        ]);

        $result = $timesheet->approve($this->approver);

        $this->assertTrue($result);
        $this->assertEquals(Timesheet::STATUS_APPROVED, $timesheet->status);
        $this->assertEquals($this->approver->id, $timesheet->reviewed_by);
        $this->assertNotNull($timesheet->reviewed_at);
        $this->assertNull($timesheet->rejection_reason);
    }

    public function test_reject_method_updates_status_and_reason()
    {
        $timesheet = Timesheet::factory()->create([
            'project_id' => $this->project->id,
            'status' => Timesheet::STATUS_PENDING
        ]);

        $reason = 'Horário não compatível com o projeto';
        $result = $timesheet->reject($this->approver, $reason);

        $this->assertTrue($result);
        $this->assertEquals(Timesheet::STATUS_REJECTED, $timesheet->status);
        $this->assertEquals($this->approver->id, $timesheet->reviewed_by);
        $this->assertNotNull($timesheet->reviewed_at);
        $this->assertEquals($reason, $timesheet->rejection_reason);
    }

    public function test_cannot_approve_already_processed_timesheet()
    {
        $timesheet = Timesheet::factory()->create([
            'project_id' => $this->project->id,
            'status' => Timesheet::STATUS_APPROVED
        ]);

        $result = $timesheet->approve($this->approver);
        $this->assertFalse($result);
    }

    public function test_scopes_work_correctly()
    {
        $user2 = User::factory()->create();
        $project2 = Project::factory()->create();
        
        // Criar timesheets com dados específicos para testar scopes
        Timesheet::factory()->create([
            'user_id' => $this->user->id, 
            'project_id' => $this->project->id,
            'status' => Timesheet::STATUS_PENDING
        ]);
        
        Timesheet::factory()->create([
            'user_id' => $user2->id, 
            'project_id' => $this->project->id,
            'status' => Timesheet::STATUS_APPROVED
        ]);
        
        Timesheet::factory()->create([
            'user_id' => $user2->id,
            'project_id' => $project2->id, 
            'status' => Timesheet::STATUS_REJECTED
        ]);

        // Testes de scopes
        $this->assertEquals(1, Timesheet::forUser($this->user->id)->count());
        $this->assertEquals(2, Timesheet::forProject($this->project->id)->count());
        $this->assertEquals(1, Timesheet::pending()->count());
        $this->assertEquals(1, Timesheet::approved()->count());
        $this->assertEquals(1, Timesheet::rejected()->count());
    }

    public function test_period_scope_filters_by_date_range()
    {
        Timesheet::factory()->create(['date' => '2024-01-10']);
        Timesheet::factory()->create(['date' => '2024-01-15']);
        Timesheet::factory()->create(['date' => '2024-01-20']);

        $timesheets = Timesheet::inPeriod('2024-01-12', '2024-01-18')->get();
        
        $this->assertCount(1, $timesheets);
        $this->assertEquals('2024-01-15', $timesheets->first()->date->format('Y-m-d'));
    }

    public function test_get_statuses_returns_all_statuses()
    {
        $statuses = Timesheet::getStatuses();
        
        $this->assertArrayHasKey(Timesheet::STATUS_PENDING, $statuses);
        $this->assertArrayHasKey(Timesheet::STATUS_APPROVED, $statuses);
        $this->assertArrayHasKey(Timesheet::STATUS_REJECTED, $statuses);
        $this->assertEquals('Pendente', $statuses[Timesheet::STATUS_PENDING]);
    }
} 