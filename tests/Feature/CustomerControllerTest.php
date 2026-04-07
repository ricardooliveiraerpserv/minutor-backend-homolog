<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\TestHelpers;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CustomerControllerTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /** @test */
    public function admin_can_list_all_customers()
    {
        $admin = $this->createAdminUser();
        
        // Criar alguns customers de teste
        Customer::create(['name' => 'João Silva', 'cgc' => '12345678901']);
        Customer::create(['name' => 'Maria Santos', 'cgc' => '98765432109']);
        Customer::create(['name' => 'Empresa ABC Ltda', 'cgc' => '12345678000195']);

        $response = $this->getJson($this->apiUrl('/customers'), $this->authHeaders($admin));

        $this->assertPoUiCollectionResponse($response);
        
        $responseData = $response->json();
        $this->assertCount(3, $responseData['items']);
    }

    /** @test */
    public function user_with_customers_view_permission_can_list_customers()
    {
        $user = $this->createUserWithPermissions(['customers.view']);
        
        Customer::create(['name' => 'Test Customer', 'cgc' => '12345678901']);

        $response = $this->getJson($this->apiUrl('/customers'), $this->authHeaders($user));

        $this->assertPoUiCollectionResponse($response);
        $responseData = $response->json();
        $this->assertCount(1, $responseData['items']);
    }

    /** @test */
    public function user_without_permission_cannot_list_customers()
    {
        $user = $this->createConsultantUser();

        $response = $this->getJson($this->apiUrl('/customers'), $this->authHeaders($user));

        $this->assertUnauthorizedResponse($response);
    }

    /** @test */
    public function admin_can_create_customer_with_valid_cpf()
    {
        $admin = $this->createAdminUser();

        $customerData = [
            'name' => 'João Silva',
            'cgc' => '11144477735' // CPF válido
        ];

        $response = $this->postJson($this->apiUrl('/customers'), $customerData, $this->authHeaders($admin));

        $this->assertSuccessResponse($response, [], 201);

        $this->assertDatabaseHas('customers', [
            'name' => 'João Silva',
            'cgc' => '11144477735'
        ]);
    }

    /** @test */
    public function admin_can_create_customer_with_valid_cnpj()
    {
        $admin = $this->createAdminUser();

        $customerData = [
            'name' => 'Empresa ABC Ltda',
            'cgc' => '11222333000181' // CNPJ válido
        ];

        $response = $this->postJson($this->apiUrl('/customers'), $customerData, $this->authHeaders($admin));

        $this->assertSuccessResponse($response, [], 201);

        $this->assertDatabaseHas('customers', [
            'name' => 'Empresa ABC Ltda',
            'cgc' => '11222333000181'
        ]);
    }

    /** @test */
    public function cannot_create_customer_with_invalid_cpf()
    {
        $admin = $this->createAdminUser();

        $customerData = [
            'name' => 'João Silva',
            'cgc' => '12345678901' // CPF inválido
        ];

        $response = $this->postJson($this->apiUrl('/customers'), $customerData, $this->authHeaders($admin));

        $this->assertValidationErrorResponse($response);

        $this->assertDatabaseMissing('customers', [
            'name' => 'João Silva',
            'cgc' => '12345678901'
        ]);
    }

    /** @test */
    public function cannot_create_customer_with_duplicate_cgc()
    {
        $admin = $this->createAdminUser();
        
        Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);

        $customerData = [
            'name' => 'Maria Santos',
            'cgc' => '11144477735' // CGC duplicado
        ];

        $response = $this->postJson($this->apiUrl('/customers'), $customerData, $this->authHeaders($admin));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cgc']);
    }

    /** @test */
    public function user_with_permission_can_create_customer()
    {
        $user = $this->createUserWithPermissions(['customers.create']);

        $customerData = [
            'name' => 'Cliente Teste',
            'cgc' => '11144477735'
        ];

        $response = $this->postJson($this->apiUrl('/customers'), $customerData, $this->authHeaders($user));

        $this->assertSuccessResponse($response, [], 201);
    }

    /** @test */
    public function user_without_permission_cannot_create_customer()
    {
        $user = $this->createConsultantUser();

        $customerData = [
            'name' => 'Cliente Teste',
            'cgc' => '11144477735'
        ];

        $response = $this->postJson($this->apiUrl('/customers'), $customerData, $this->authHeaders($user));

        $this->assertUnauthorizedResponse($response);
    }

    /** @test */
    public function admin_can_view_specific_customer()
    {
        $admin = $this->createAdminUser();
        $customer = Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);

        $response = $this->getJson("/api/v1/customers/{$customer->id}", $this->authHeaders($admin));

        $this->assertSuccessResponse($response);
    }

    /** @test */
    public function admin_can_update_customer()
    {
        $admin = $this->createAdminUser();
        $customer = Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);

        $updateData = [
            'name' => 'João Silva Santos'
        ];

        $response = $this->putJson("/api/v1/customers/{$customer->id}", $updateData, $this->authHeaders($admin));

        $this->assertSuccessResponse($response);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'João Silva Santos',
            'cgc' => '11144477735'
        ]);
    }

    /** @test */
    public function admin_can_update_customer_cgc()
    {
        $admin = $this->createAdminUser();
        $customer = Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);

        $updateData = [
            'cgc' => '11222333000181' // CNPJ válido
        ];

        $response = $this->putJson("/api/v1/customers/{$customer->id}", $updateData, $this->authHeaders($admin));

        $this->assertSuccessResponse($response);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'cgc' => '11222333000181'
        ]);
    }

    /** @test */
    public function cannot_update_customer_with_invalid_cgc()
    {
        $admin = $this->createAdminUser();
        $customer = Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);

        $updateData = [
            'cgc' => '12345678901' // CGC inválido
        ];

        $response = $this->putJson("/api/v1/customers/{$customer->id}", $updateData, $this->authHeaders($admin));

        $this->assertValidationErrorResponse($response);

        // Verifica que o CGC não foi alterado
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'cgc' => '11144477735' // CGC original
        ]);
    }

    /** @test */
    public function user_with_permission_can_update_customer()
    {
        $user = $this->createUserWithPermissions(['customers.update']);
        $customer = Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);

        $updateData = ['name' => 'João Santos'];

        $response = $this->putJson("/api/v1/customers/{$customer->id}", $updateData, $this->authHeaders($user));

        $this->assertSuccessResponse($response);
    }

    /** @test */
    public function user_without_permission_cannot_update_customer()
    {
        $user = $this->createConsultantUser();
        $customer = Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);

        $updateData = ['name' => 'João Santos'];

        $response = $this->putJson("/api/v1/customers/{$customer->id}", $updateData, $this->authHeaders($user));

        $this->assertUnauthorizedResponse($response);
    }

    /** @test */
    public function admin_can_delete_customer()
    {
        $admin = $this->createAdminUser();
        $customer = Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}", [], $this->authHeaders($admin));

        $this->assertSuccessResponse($response);

        $this->assertSoftDeleted('customers', [
            'id' => $customer->id
        ]);
    }

    /** @test */
    public function user_with_permission_can_delete_customer()
    {
        $user = $this->createUserWithPermissions(['customers.delete']);
        $customer = Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}", [], $this->authHeaders($user));

        $this->assertSuccessResponse($response);
    }

    /** @test */
    public function user_without_permission_cannot_delete_customer()
    {
        $user = $this->createConsultantUser();
        $customer = Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}", [], $this->authHeaders($user));

        $this->assertUnauthorizedResponse($response);
    }

    /** @test */
    public function can_search_customers_by_name()
    {
        $admin = $this->createAdminUser();
        
        Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);
        Customer::create(['name' => 'Maria Santos', 'cgc' => '98765432109']);
        Customer::create(['name' => 'José João', 'cgc' => '11222333000181']);

        $response = $this->getJson($this->apiUrl('/customers?search=João'), $this->authHeaders($admin));

        $this->assertPoUiCollectionResponse($response);
        $responseData = $response->json();
        $this->assertCount(2, $responseData['items']); // João Silva e José João
    }

    /** @test */
    public function can_search_customers_by_cgc()
    {
        $admin = $this->createAdminUser();
        
        Customer::create(['name' => 'João Silva', 'cgc' => '11144477735']);
        Customer::create(['name' => 'Maria Santos', 'cgc' => '98765432109']);

        $response = $this->getJson($this->apiUrl('/customers?search=111'), $this->authHeaders($admin));

        $this->assertPoUiCollectionResponse($response);
        $responseData = $response->json();
        $this->assertCount(1, $responseData['items']); // Apenas João Silva
    }

    /** @test */
    public function customers_list_is_paginated()
    {
        $admin = $this->createAdminUser();
        
        // Criar 20 customers
        for ($i = 1; $i <= 20; $i++) {
            Customer::create([
                'name' => "Customer {$i}",
                'cgc' => sprintf('%011d', $i) // Gera CPFs únicos
            ]);
        }

        $response = $this->getJson($this->apiUrl('/customers?pageSize=5'), $this->authHeaders($admin));

        $this->assertPoUiCollectionResponse($response);
        $responseData = $response->json();
        $this->assertCount(5, $responseData['items']);
        $this->assertTrue($responseData['hasNext']); // Deve haver mais páginas
    }
} 