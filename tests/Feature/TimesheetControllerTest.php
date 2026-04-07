<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\TestHelpers;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class TimesheetControllerTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    protected User $user;
    protected User $approver;
    protected User $admin;
    protected Customer $customer;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Criar papéis e permissões necessários
        Role::create(['name' => 'Administrator']);
        Permission::create(['name' => 'hours.view']);
        Permission::create(['name' => 'hours.create']);
        Permission::create(['name' => 'hours.update_own']);
        Permission::create(['name' => 'hours.delete_own']);
        Permission::create(['name' => 'hours.approve']);
        Permission::create(['name' => 'hours.reject']);
        
        $this->user = User::factory()->create();
        $this->approver = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->project = Project::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => Project::STATUS_STARTED
        ]);
        
        // Configurar roles e permissões
        $this->admin->assignRole('Administrator');
        $this->user->givePermissionTo(['hours.view', 'hours.create', 'hours.update_own', 'hours.delete_own']);
        $this->approver->givePermissionTo(['hours.view', 'hours.approve', 'hours.reject']);
        
        // Adicionar aprovador ao projeto
        $this->project->approvers()->attach($this->approver->id);
    }

    public function test_index_returns_poui_format()
    {
        Sanctum::actingAs($this->user);
        
        Timesheet::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id
        ]);

        $response = $this->getJson($this->apiUrl('/timesheets'));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'hasNext',
                'items' => [
                    '*' => [
                        'id', 'user_id', 'customer_id', 'project_id',
                        'date', 'start_time', 'end_time', 'effort_minutes',
                        'observation', 'ticket', 'status', 'created_at'
                    ]
                ]
            ]);
    }

    public function test_index_supports_poui_pagination()
    {
        Sanctum::actingAs($this->user);
        
        Timesheet::factory()->count(25)->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id
        ]);

        // Primeira página
        $response = $this->getJson($this->apiUrl('/timesheets?page=1&pageSize=10'));
        $response->assertStatus(200)
            ->assertJson(['hasNext' => true])
            ->assertJsonCount(10, 'items');

        // Segunda página
        $response = $this->getJson($this->apiUrl('/timesheets?page=2&pageSize=10'));
        $response->assertStatus(200)
            ->assertJson(['hasNext' => true])
            ->assertJsonCount(10, 'items');

        // Terceira página (última)
        $response = $this->getJson($this->apiUrl('/timesheets?page=3&pageSize=10'));
        $response->assertStatus(200)
            ->assertJson(['hasNext' => false])
            ->assertJsonCount(5, 'items');
    }

    public function test_index_supports_poui_ordering()
    {
        Sanctum::actingAs($this->user);
        
        Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'date' => '2024-01-10'
        ]);
        
        Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'date' => '2024-01-15'
        ]);

        // Ordenação ascendente
        $response = $this->getJson($this->apiUrl('/timesheets?order=date'));
        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertEquals('2024-01-10', $items[0]['date']);
        $this->assertEquals('2024-01-15', $items[1]['date']);

        // Ordenação descendente
        $response = $this->getJson($this->apiUrl('/timesheets?order=-date'));
        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertEquals('2024-01-15', $items[0]['date']);
        $this->assertEquals('2024-01-10', $items[1]['date']);
    }

    public function test_index_supports_search()
    {
        Sanctum::actingAs($this->user);
        
        Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'observation' => 'Desenvolvimento de API'
        ]);
        
        Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'observation' => 'Correção de bug'
        ]);

        $response = $this->getJson($this->apiUrl('/timesheets?search=API'));
        $response->assertStatus(200)
            ->assertJsonCount(1, 'items');
        
        $item = $response->json('items.0');
        $this->assertStringContainsString('API', $item['observation']);
    }

    public function test_index_filters_by_own_timesheets_for_regular_user()
    {
        $otherUser = User::factory()->create();
        $otherUser->givePermissionTo('hours.view');
        
        Sanctum::actingAs($this->user);
        
        // Timesheet próprio
        Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id
        ]);
        
        // Timesheet de outro usuário
        Timesheet::factory()->create([
            'user_id' => $otherUser->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id
        ]);

        $response = $this->getJson($this->apiUrl('/timesheets'));
        $response->assertStatus(200)
            ->assertJsonCount(1, 'items');
    }

    public function test_admin_can_see_all_timesheets()
    {
        Sanctum::actingAs($this->admin);
        
        Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id
        ]);
        
        Timesheet::factory()->create([
            'user_id' => $this->approver->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id
        ]);

        $response = $this->getJson($this->apiUrl('/timesheets'));
        $response->assertStatus(200)
            ->assertJsonCount(2, 'items');
    }

    public function test_store_creates_timesheet_with_correct_data()
    {
        Sanctum::actingAs($this->user);

        $data = [
            'project_id' => $this->project->id,
            'date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'observation' => 'Desenvolvimento de feature',
            'ticket' => 'TICKET-123'
        ];

        $response = $this->postJson($this->apiUrl('/timesheets'), $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id', 'user_id', 'customer_id', 'project_id',
                    'date', 'start_time', 'end_time', 'effort_minutes',
                    'effort_hours', 'status', 'status_display'
                ],
                'message'
            ]);

        $this->assertDatabaseHas('timesheets', [
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'date' => '2024-01-15',
            'effort_minutes' => 480, // 8 horas
            'status' => Timesheet::STATUS_PENDING
        ]);
    }

    public function test_store_validation_fails_with_poui_error_format()
    {
        Sanctum::actingAs($this->user);

        $data = [
            'project_id' => 999, // ID inexistente
            'date' => '2025-12-31', // Data futura
            'start_time' => '17:00',
            'end_time' => '09:00', // Horário final antes do inicial
        ];

        $response = $this->postJson($this->apiUrl('/timesheets'), $data);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'code',
                'type',
                'message',
                'detailMessage',
                'details'
            ])
            ->assertJson([
                'code' => 'VALIDATION_FAILED',
                'type' => 'error'
            ]);
    }

    public function test_store_prevents_timesheet_on_inactive_project()
    {
        $inactiveProject = Project::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => Project::STATUS_FINISHED
        ]);

        Sanctum::actingAs($this->user);

        $data = [
            'project_id' => $inactiveProject->id,
            'date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '17:00'
        ];

        $response = $this->postJson($this->apiUrl('/timesheets'), $data);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 'INACTIVE_PROJECT',
                'type' => 'error'
            ]);
    }

    public function test_store_prevents_duplicate_timesheet()
    {
        Sanctum::actingAs($this->user);

        // Criar primeiro apontamento
        $data = [
            'project_id' => $this->project->id,
            'date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'observation' => 'Primeiro apontamento'
        ];

        $response = $this->postJson($this->apiUrl('/timesheets'), $data);
        $response->assertStatus(201);

        // Tentar criar apontamento duplicado
        $duplicateData = [
            'project_id' => $this->project->id,
            'date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'observation' => 'Apontamento duplicado'
        ];

        $response = $this->postJson($this->apiUrl('/timesheets'), $duplicateData);
        $response->assertStatus(422)
            ->assertJson([
                'code' => 'DUPLICATE_TIMESHEET',
                'type' => 'error',
                'message' => 'Apontamento duplicado'
            ]);
    }

    public function test_store_prevents_overlapping_timesheet()
    {
        Sanctum::actingAs($this->user);

        // Criar primeiro apontamento
        $data = [
            'project_id' => $this->project->id,
            'date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '12:00',
            'observation' => 'Primeiro apontamento'
        ];

        $response = $this->postJson($this->apiUrl('/timesheets'), $data);
        $response->assertStatus(201);

        // Tentar criar apontamento com sobreposição
        $overlappingData = [
            'project_id' => $this->project->id,
            'date' => '2024-01-15',
            'start_time' => '11:00',
            'end_time' => '14:00',
            'observation' => 'Apontamento com sobreposição'
        ];

        $response = $this->postJson($this->apiUrl('/timesheets'), $overlappingData);
        $response->assertStatus(422)
            ->assertJson([
                'code' => 'OVERLAPPING_TIMESHEET',
                'type' => 'error',
                'message' => 'Sobreposição de horários'
            ]);
    }

    public function test_store_allows_timesheet_after_rejected_one()
    {
        Sanctum::actingAs($this->user);

        // Criar apontamento rejeitado
        $rejectedTimesheet = Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '12:00',
            'status' => Timesheet::STATUS_REJECTED
        ]);

        // Tentar criar apontamento com mesmo horário (deve ser permitido pois o anterior foi rejeitado)
        $data = [
            'project_id' => $this->project->id,
            'date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '12:00',
            'observation' => 'Novo apontamento após rejeição'
        ];

        $response = $this->postJson($this->apiUrl('/timesheets'), $data);
        $response->assertStatus(201);
    }

    public function test_show_returns_timesheet_details()
    {
        Sanctum::actingAs($this->user);
        
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id
        ]);

        $response = $this->getJson("/api/v1/timesheets/{$timesheet->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id', 'user', 'customer', 'project',
                    'date', 'start_time', 'end_time', 'effort_hours'
                ]
            ]);
    }

    public function test_show_denies_access_to_other_users_timesheet()
    {
        $otherUser = User::factory()->create();
        $otherUser->givePermissionTo('hours.view');
        
        $timesheet = Timesheet::factory()->create([
            'user_id' => $otherUser->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/v1/timesheets/{$timesheet->id}");
        $response->assertStatus(403);
    }

    public function test_update_modifies_pending_timesheet()
    {
        Sanctum::actingAs($this->user);
        
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'status' => Timesheet::STATUS_PENDING
        ]);

        $data = [
            'observation' => 'Atualização da observação',
            'ticket' => 'TICKET-456'
        ];

        $response = $this->putJson("/api/v1/timesheets/{$timesheet->id}", $data);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'message'
            ]);

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'observation' => 'Atualização da observação',
            'ticket' => 'TICKET-456'
        ]);
    }

    public function test_update_prevents_editing_approved_timesheet()
    {
        Sanctum::actingAs($this->user);
        
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'status' => Timesheet::STATUS_APPROVED
        ]);

        $data = ['observation' => 'Tentativa de edição'];

        $response = $this->putJson("/api/v1/timesheets/{$timesheet->id}", $data);
        $response->assertStatus(422);
    }

    public function test_destroy_deletes_pending_timesheet()
    {
        Sanctum::actingAs($this->user);
        
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'status' => Timesheet::STATUS_PENDING
        ]);

        $response = $this->deleteJson("/api/v1/timesheets/{$timesheet->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message'
            ]);

        $this->assertSoftDeleted('timesheets', ['id' => $timesheet->id]);
    }

    public function test_approve_timesheet_by_project_approver()
    {
        Sanctum::actingAs($this->approver);
        
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'status' => Timesheet::STATUS_PENDING
        ]);

        $response = $this->postJson("/api/v1/timesheets/{$timesheet->id}/approve");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'message'
            ]);

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => Timesheet::STATUS_APPROVED,
            'reviewed_by' => $this->approver->id
        ]);
    }

    public function test_reject_timesheet_with_reason()
    {
        Sanctum::actingAs($this->approver);
        
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'status' => Timesheet::STATUS_PENDING
        ]);

        $data = ['reason' => 'Horário incompatível'];

        $response = $this->postJson("/api/v1/timesheets/{$timesheet->id}/reject", $data);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'message'
            ]);

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => Timesheet::STATUS_REJECTED,
            'reviewed_by' => $this->approver->id,
            'rejection_reason' => 'Horário incompatível'
        ]);
    }

    public function test_non_approver_cannot_approve_timesheet()
    {
        Sanctum::actingAs($this->user);
        
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'status' => Timesheet::STATUS_PENDING
        ]);

        $response = $this->postJson("/api/v1/timesheets/{$timesheet->id}/approve");
        $response->assertStatus(403);
    }

    public function test_admin_can_approve_any_timesheet()
    {
        Sanctum::actingAs($this->admin);
        
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'customer_id' => $this->customer->id,
            'status' => Timesheet::STATUS_PENDING
        ]);

        $response = $this->postJson("/api/v1/timesheets/{$timesheet->id}/approve");
        $response->assertStatus(200);

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => Timesheet::STATUS_APPROVED,
            'reviewed_by' => $this->admin->id
        ]);
    }

    public function test_unauthenticated_user_cannot_access_endpoints()
    {
        $response = $this->getJson($this->apiUrl('/timesheets'));
        $response->assertStatus(401);

        $response = $this->postJson($this->apiUrl('/timesheets'), []);
        $response->assertStatus(401);
    }

    public function test_user_without_permissions_cannot_create_timesheet()
    {
        $userWithoutPermission = User::factory()->create();
        Sanctum::actingAs($userWithoutPermission);

        $data = [
            'project_id' => $this->project->id,
            'date' => '2024-01-15',
            'start_time' => '09:00',
            'end_time' => '17:00'
        ];

        $response = $this->postJson($this->apiUrl('/timesheets'), $data);
        $response->assertStatus(403);
    }
} 