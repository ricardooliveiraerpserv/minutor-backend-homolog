<?php

namespace Tests;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

trait TestHelpers
{
    /**
     * Gerar URL da API com prefixo v1
     */
    protected function apiUrl(string $endpoint): string
    {
        return '/api/v1' . $endpoint;
    }

    /**
     * Criar usuário administrador
     */
    protected function createAdminUser(): User
    {
        $admin = User::factory()->create([
            'name' => 'Test Admin',
            'email' => 'admin@test-' . uniqid() . '.local'
        ]);
        
        $adminRole = Role::findByName('Administrator', 'web');
        $admin->assignRole($adminRole);
        
        return $admin;
    }

    /**
     * Criar usuário gestor de projetos
     */
    protected function createManagerUser(): User
    {
        $manager = User::factory()->create([
            'name' => 'Test Manager',
            'email' => 'manager@test-' . uniqid() . '.local'
        ]);
        
        $managerRole = Role::findByName('Project Manager', 'web');
        $manager->assignRole($managerRole);
        
        return $manager;
    }

    /**
     * Criar usuário consultor
     */
    protected function createConsultantUser(): User
    {
        $consultant = User::factory()->create([
            'name' => 'Test Consultant',
            'email' => 'consultant@test-' . uniqid() . '.local'
        ]);
        
        $consultantRole = Role::findByName('Consultant', 'web');
        $consultant->assignRole($consultantRole);
        
        return $consultant;
    }

    /**
     * Criar usuário sem roles
     */
    protected function createBasicUser(): User
    {
        return User::factory()->create([
            'name' => 'Test Basic User',
            'email' => 'basic@test-' . uniqid() . '.local'
        ]);
    }

    /**
     * Configurar dados de teste para roles e permissões
     */
    protected function seedRolesAndPermissions(): void
    {
        // Verificar se já existem, senão executar seeders
        if (Role::count() === 0) {
            $this->artisan('db:seed', ['--class' => 'PermissionSeeder']);
            $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
        }
    }

    /**
     * Criar token de autenticação para usuário
     */
    protected function createTokenFor(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    /**
     * Headers de autenticação para requisições
     */
    protected function authHeaders(User $user): array
    {
        $token = $this->createTokenFor($user);
        
        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];
    }

    /**
     * Verificar estrutura de resposta de sucesso PO-UI
     */
    protected function assertSuccessResponse($response, $expectedKeys = [], $statusCode = 200): void
    {
        $response->assertStatus($statusCode);
                
        if (!empty($expectedKeys)) {
            $response->assertJsonStructure($expectedKeys);
        }
    }

    /**
     * Verificar resposta de coleção PO-UI
     */
    protected function assertPoUiCollectionResponse($response, $statusCode = 200): void
    {
        $response->assertStatus($statusCode)
                ->assertJsonStructure([
                    'hasNext',
                    'items'
                ]);
    }

    /**
     * Verificar resposta de item único PO-UI
     */
    protected function assertPoUiItemResponse($response, array $expectedKeys = [], $statusCode = 200): void
    {
        $response->assertStatus($statusCode);
        
        if (!empty($expectedKeys)) {
            $response->assertJsonStructure($expectedKeys);
        }
    }

    /**
     * Verificar estrutura de resposta de erro de permissão
     */
    protected function assertPermissionDeniedResponse($response, string $expectedPermission = null): void
    {
        $response->assertStatus(403)
                ->assertJson(['success' => false])
                ->assertJsonStructure([
                    'success',
                    'message',
                    'required_permission',
                    'user_permissions',
                    'user_roles'
                ]);

        if ($expectedPermission) {
            $response->assertJson(['required_permission' => $expectedPermission]);
        }
    }

    /**
     * Verificar resposta de não autenticado
     */
    protected function assertUnauthenticatedResponse($response): void
    {
        $response->assertStatus(401)
                ->assertJson(['success' => false, 'message' => 'Não autenticado']);
    }

    /**
     * Verificar resposta de erro de autorização (403)
     */
    protected function assertUnauthorizedResponse($response): void
    {
        $response->assertStatus(403);
    }

    /**
     * Verificar resposta de erro de validação PO-UI
     */
    protected function assertValidationErrorResponse($response, $expectedData = []): void
    {
        $response->assertStatus(422)
                ->assertJsonStructure([
                    'code',
                    'type',
                    'message',
                    'detailMessage'
                ]);
        
        if (!empty($expectedData)) {
            $response->assertJson($expectedData);
        }
    }

    /**
     * Criar usuário com permissões específicas
     */
    protected function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create([
            'name' => 'Test User with Permissions',
            'email' => 'user-perms@test-' . uniqid() . '.local'
        ]);
        
        // Criar um role temporário com essas permissões
        $roleName = 'Test Role ' . uniqid();
        $role = $this->createTestRole($roleName, $permissions);
        $user->assignRole($role);
        
        return $user;
    }

    /**
     * Criar role customizado para testes
     */
    protected function createTestRole(string $name, array $permissions = []): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        
        if (!empty($permissions)) {
            $role->givePermissionTo($permissions);
        }
        
        return $role;
    }

    /**
     * Criar permissão customizada para testes
     */
    protected function createTestPermission(string $name): Permission
    {
        return Permission::create(['name' => $name, 'guard_name' => 'web']);
    }

    /**
     * Limpar dados de teste
     */
    protected function cleanupTestData(): void
    {
        // Remover tokens criados nos testes
        \DB::table('personal_access_tokens')->where('name', 'test-token')->delete();
        
        // Limpar usuários de teste (com emails únicos agora)
        User::where('email', 'like', '%@test-%')->delete();
    }
} 