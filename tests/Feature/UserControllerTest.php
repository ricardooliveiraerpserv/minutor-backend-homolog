<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\TestHelpers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;

class UserControllerTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    protected User $admin;
    protected User $manager;
    protected User $consultant;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Criar papéis e permissões necessários
        Role::create(['name' => 'Administrator']);
        Role::create(['name' => 'Project Manager']);
        Role::create(['name' => 'Consultant']);
        
        // Permissões de usuários 
        $userPermissions = [
            'users.view', 'users.view_all', 'users.create', 'users.update',
            'users.update_own_profile', 'users.delete', 'users.manage_roles', 'users.reset_password'
        ];
        
        foreach ($userPermissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // Criar usuários de teste
        $this->admin = User::factory()->create(['name' => 'Admin User', 'email' => 'admin@test.com']);
        $this->manager = User::factory()->create(['name' => 'Manager User', 'email' => 'manager@test.com']);
        $this->consultant = User::factory()->create(['name' => 'Consultant User', 'email' => 'consultant@test.com']);
        $this->regularUser = User::factory()->create(['name' => 'Regular User', 'email' => 'regular@test.com']);

        // Atribuir papéis
        $this->admin->assignRole('Administrator');
        $this->manager->assignRole('Project Manager');
        $this->consultant->assignRole('Consultant');
        
        // Dar permissões específicas ao manager
        $this->manager->givePermissionTo(['users.view', 'users.view_all', 'users.create', 'users.update', 'users.delete', 'users.manage_roles', 'users.reset_password']);
        
        // Dar permissão básica ao consultant
        $this->consultant->givePermissionTo(['users.view', 'users.update_own_profile']);
    }

    /** @test */
    public function index_returns_poui_format()
    {
        Sanctum::actingAs($this->admin);
        
        User::factory()->count(3)->create();

        $response = $this->getJson($this->apiUrl('/users'));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'hasNext',
                'items' => [
                    '*' => [
                        'id', 'name', 'email', 'created_at', 'updated_at',
                        'roles' => [
                            '*' => ['id', 'name', 'guard_name']
                        ]
                    ]
                ]
            ]);
    }

    /** @test */
    public function index_supports_poui_pagination()
    {
        Sanctum::actingAs($this->admin);
        
        User::factory()->count(25)->create();

        $response = $this->getJson($this->apiUrl('/users?pageSize=10&page=2'));

        $response->assertStatus(200)
            ->assertJsonPath('hasNext', true);
        
        $this->assertCount(10, $response->json('items'));
    }

    /** @test */
    public function index_supports_search()
    {
        Sanctum::actingAs($this->admin);
        
        User::factory()->create(['name' => 'João Silva', 'email' => 'joao@test.com']);
        User::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@test.com']);

        $response = $this->getJson($this->apiUrl('/users?search=João'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertEquals('João Silva', $items[0]['name']);
    }

    /** @test */
    public function index_filters_by_role()
    {
        Sanctum::actingAs($this->admin);
        
        $response = $this->getJson($this->apiUrl('/users?role=Administrator'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertGreaterThan(0, count($items));
        $this->assertEquals('Administrator', $items[0]['roles'][0]['name']);
    }

    /** @test */
    public function index_filters_own_profile_for_regular_user()
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->getJson($this->apiUrl('/users'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertEquals($this->regularUser->id, $items[0]['id']);
    }

    /** @test */
    public function admin_can_see_all_users()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson($this->apiUrl('/users'));

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertGreaterThan(1, count($items));
    }

    /** @test */
    public function store_creates_user_with_correct_data()
    {
        Sanctum::actingAs($this->admin);
        
        $userData = [
            'name' => 'Novo Usuario',
            'email' => 'novo@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [Role::where('name', 'Consultant')->first()->id]
        ];

        $response = $this->postJson($this->apiUrl('/users'), $userData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id', 'name', 'email', 'created_at', 'updated_at',
                'roles' => [
                    '*' => ['id', 'name', 'guard_name']
                ]
            ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Novo Usuario',
            'email' => 'novo@test.com'
        ]);

        $user = User::where('email', 'novo@test.com')->first();
        $this->assertTrue($user->hasRole('Consultant'));
    }

    /** @test */
    public function store_validation_fails_with_invalid_data()
    {
        Sanctum::actingAs($this->admin);
        
        $userData = [
            'name' => '',
            'email' => 'invalid-email',
            'password' => '123', // muito curta
        ];

        $response = $this->postJson($this->apiUrl('/users'), $userData);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'code', 'type', 'message', 'detailMessage', 'details'
            ]);
    }

    /** @test */
    public function store_prevents_duplicate_email()
    {
        Sanctum::actingAs($this->admin);
        
        $userData = [
            'name' => 'Usuario Duplicado',
            'email' => $this->admin->email, // email já existe
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->apiUrl('/users'), $userData);

        $response->assertStatus(422);
    }

    /** @test */
    public function show_returns_user_details()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson($this->apiUrl("/users/{$this->consultant->id}"));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id', 'name', 'email', 'created_at', 'updated_at',
                'roles' => [
                    '*' => ['id', 'name', 'guard_name']
                ]
            ])
            ->assertJsonPath('id', $this->consultant->id)
            ->assertJsonPath('name', $this->consultant->name);
    }

    /** @test */
    public function show_denies_access_to_other_users_for_regular_user()
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->getJson($this->apiUrl("/users/{$this->consultant->id}"));

        $response->assertStatus(403)
            ->assertJsonStructure([
                'code', 'type', 'message', 'detailMessage'
            ]);
    }

    /** @test */
    public function update_modifies_user_data()
    {
        Sanctum::actingAs($this->admin);
        
        $updateData = [
            'name' => 'Nome Atualizado',
            'email' => 'novo.email@test.com'
        ];

        $response = $this->putJson($this->apiUrl("/users/{$this->consultant->id}"), $updateData);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Nome Atualizado')
            ->assertJsonPath('email', 'novo.email@test.com');

        $this->assertDatabaseHas('users', [
            'id' => $this->consultant->id,
            'name' => 'Nome Atualizado',
            'email' => 'novo.email@test.com'
        ]);
    }

    /** @test */
    public function update_allows_user_to_update_own_profile()
    {
        Sanctum::actingAs($this->consultant);
        
        $updateData = [
            'name' => 'Meu Nome Atualizado'
        ];

        $response = $this->putJson($this->apiUrl("/users/{$this->consultant->id}"), $updateData);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Meu Nome Atualizado');
    }

    /** @test */
    public function update_prevents_unauthorized_user_modification()
    {
        Sanctum::actingAs($this->regularUser);
        
        $updateData = [
            'name' => 'Tentativa de Hack'
        ];

        $response = $this->putJson($this->apiUrl("/users/{$this->consultant->id}"), $updateData);

        $response->assertStatus(403);
    }

    /** @test */
    public function update_manages_user_roles()
    {
        Sanctum::actingAs($this->admin);
        
        $managerRoleId = Role::where('name', 'Project Manager')->first()->id;
        $updateData = [
            'name' => 'Usuário Promovido',
            'roles' => [$managerRoleId]
        ];

        $response = $this->putJson($this->apiUrl("/users/{$this->consultant->id}"), $updateData);

        $response->assertStatus(200);
        
        $this->consultant->refresh();
        $this->assertTrue($this->consultant->hasRole('Project Manager'));
        $this->assertFalse($this->consultant->hasRole('Consultant'));
    }

    /** @test */
    public function destroy_deletes_user()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson($this->apiUrl("/users/{$this->consultant->id}"));

        $response->assertStatus(204);
        $this->assertDatabaseMissing('users', ['id' => $this->consultant->id]);
    }

    /** @test */
    public function destroy_prevents_self_deletion()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson("/api/v1/users/{$this->admin->id}");

        $response->assertStatus(422)
            ->assertJsonPath('code', 'CANNOT_DELETE_SELF');
    }

    /** @test */
    public function destroy_prevents_deletion_of_last_admin()
    {
        // Remover papel de admin de outros usuários para garantir apenas um admin
        User::role('Administrator')->where('id', '!=', $this->admin->id)->get()->each(function($user) {
            $user->syncRoles([]);
        });
        
        // Verificar que há apenas um admin
        $this->assertEquals(1, User::role('Administrator')->count());
        
        // Criar um usuário não-admin para tentar deletar o último admin
        $regularUser = User::factory()->create();
        $regularUser->givePermissionTo('users.delete'); // Dar permissão específica
        
        Sanctum::actingAs($regularUser);
        
        // Tentar deletar o último admin deve falhar
        $response = $this->deleteJson("/api/v1/users/{$this->admin->id}");
        $response->assertStatus(422)
            ->assertJsonPath('code', 'CANNOT_DELETE_LAST_ADMIN');
        
        // Verificar que o admin ainda existe
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    /** @test */
    public function reset_password_generates_temporary_password()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/users/{$this->consultant->id}/reset-password");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success', 'message', 'temporary_password'
            ])
            ->assertJsonPath('success', true);

        $temporaryPassword = $response->json('temporary_password');
        $this->assertNotEmpty($temporaryPassword);
        
        // Verificar se a senha foi alterada
        $this->consultant->refresh();
        $this->assertTrue(Hash::check($temporaryPassword, $this->consultant->password));
    }

    /** @test */
    public function profile_returns_current_user_data()
    {
        Sanctum::actingAs($this->consultant);

        $response = $this->getJson($this->apiUrl('/users/profile'));

        $response->assertStatus(200)
            ->assertJsonPath('id', $this->consultant->id)
            ->assertJsonPath('name', $this->consultant->name)
            ->assertJsonPath('email', $this->consultant->email)
            ->assertJsonStructure([
                'id', 'name', 'email', 'created_at', 'updated_at',
                'roles' => [
                    '*' => ['id', 'name', 'guard_name']
                ]
            ]);
    }

    /** @test */
    public function update_profile_allows_self_modification()
    {
        Sanctum::actingAs($this->consultant);
        
        $updateData = [
            'name' => 'Meu Perfil Atualizado',
            'email' => 'meu.novo.email@test.com'
        ];

        $response = $this->putJson($this->apiUrl('/users/profile'), $updateData);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Meu Perfil Atualizado')
            ->assertJsonPath('email', 'meu.novo.email@test.com');
    }

    /** @test */
    public function update_profile_requires_current_password_for_password_change()
    {
        Sanctum::actingAs($this->consultant);
        
        $updateData = [
            'current_password' => 'password', // senha padrão da factory
            'password' => 'novasenha123',
            'password_confirmation' => 'novasenha123'
        ];

        $response = $this->putJson($this->apiUrl('/users/profile'), $updateData);

        $response->assertStatus(200);
        
        $this->consultant->refresh();
        $this->assertTrue(Hash::check('novasenha123', $this->consultant->password));
    }

    /** @test */
    public function update_profile_fails_with_wrong_current_password()
    {
        Sanctum::actingAs($this->consultant);
        
        $updateData = [
            'current_password' => 'senhaerrada',
            'password' => 'novasenha123',
            'password_confirmation' => 'novasenha123'
        ];

        $response = $this->putJson($this->apiUrl('/users/profile'), $updateData);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_CURRENT_PASSWORD');
    }

    /** @test */
    public function unauthenticated_user_cannot_access_endpoints()
    {
        $endpoints = [
            ['method' => 'get', 'url' => $this->apiUrl('/users')],
            ['method' => 'post', 'url' => $this->apiUrl('/users')],
            ['method' => 'get', 'url' => '/api/v1/users/1'],
            ['method' => 'put', 'url' => '/api/v1/users/1'],
            ['method' => 'delete', 'url' => '/api/v1/users/1'],
            ['method' => 'get', 'url' => $this->apiUrl('/users/profile')],
            ['method' => 'put', 'url' => $this->apiUrl('/users/profile')],
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->{$endpoint['method'] . 'Json'}($endpoint['url']);
            $response->assertStatus(401);
        }
    }

    /** @test */
    public function user_without_permissions_cannot_manage_users()
    {
        Sanctum::actingAs($this->regularUser);
        
        $userData = [
            'name' => 'Usuário Não Autorizado',
            'email' => 'nao.autorizado@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->apiUrl('/users'), $userData);
        $response->assertStatus(403);

        $response = $this->deleteJson($this->apiUrl("/users/{$this->consultant->id}"));
        $response->assertStatus(403);
    }

    /** @test */
    public function index_supports_poui_ordering()
    {
        Sanctum::actingAs($this->admin);
        
        User::factory()->create(['name' => 'Alpha User', 'created_at' => now()->subDays(2)]);
        User::factory()->create(['name' => 'Beta User', 'created_at' => now()->subDays(1)]);

        // Ordenação ascendente por nome
        $response = $this->getJson($this->apiUrl('/users?order=name'));
        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertEquals('Admin User', $items[0]['name']); // Primeiro alfabeticamente

        // Ordenação descendente por data de criação
        $response = $this->getJson($this->apiUrl('/users?order=-created_at'));
        $response->assertStatus(200);
        $items = $response->json('items');
        // O mais recente deve vir primeiro
    }
}
