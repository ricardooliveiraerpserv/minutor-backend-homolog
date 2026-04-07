<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function can_create_customer_with_valid_data()
    {
        $customer = Customer::create([
            'name' => 'João Silva',
            'cgc' => '11144477735'
        ]);

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertEquals('João Silva', $customer->name);
        $this->assertEquals('11144477735', $customer->cgc);
    }

    /** @test */
    public function validates_valid_cpf()
    {
        $customer = new Customer([
            'name' => 'João Silva',
            'cgc' => '11144477735' // CPF válido
        ]);

        $this->assertTrue($customer->isValidCgc());
    }

    /** @test */
    public function validates_valid_cnpj()
    {
        $customer = new Customer([
            'name' => 'Empresa ABC',
            'cgc' => '11222333000181' // CNPJ válido
        ]);

        $this->assertTrue($customer->isValidCgc());
    }

    /** @test */
    public function rejects_invalid_cpf()
    {
        $invalidCpfs = [
            '12345678901', // Sequência inválida
            '11111111111', // Todos iguais
            '00000000000', // Zeros
            '12345678912', // Dígito verificador errado
        ];

        foreach ($invalidCpfs as $cpf) {
            $customer = new Customer([
                'name' => 'Test',
                'cgc' => $cpf
            ]);

            $this->assertFalse($customer->isValidCgc(), "CPF {$cpf} deveria ser inválido");
        }
    }

    /** @test */
    public function rejects_invalid_cnpj()
    {
        $invalidCnpjs = [
            '12345678000194', // Sequência inválida com dígito errado
            '11111111111111', // Todos iguais
            '00000000000000', // Zeros
            '11222333000182', // Dígito verificador errado
        ];

        foreach ($invalidCnpjs as $cnpj) {
            $customer = new Customer([
                'name' => 'Test',
                'cgc' => $cnpj
            ]);

            $this->assertFalse($customer->isValidCgc(), "CNPJ {$cnpj} deveria ser inválido");
        }
    }

    /** @test */
    public function rejects_cgc_with_wrong_length()
    {
        $invalidLengths = [
            '123456789',      // 9 dígitos
            '1234567890',     // 10 dígitos
            '123456789012',   // 12 dígitos
            '12345678901234', // 15 dígitos
        ];

        foreach ($invalidLengths as $cgc) {
            $customer = new Customer([
                'name' => 'Test',
                'cgc' => $cgc
            ]);

            $this->assertFalse($customer->isValidCgc(), "CGC {$cgc} com tamanho inválido deveria ser rejeitado");
        }
    }

    /** @test */
    public function formats_cpf_correctly()
    {
        $customer = new Customer([
            'name' => 'João Silva',
            'cgc' => '11144477735'
        ]);

        $this->assertEquals('111.444.777-35', $customer->formatted_cgc);
    }

    /** @test */
    public function formats_cnpj_correctly()
    {
        $customer = new Customer([
            'name' => 'Empresa ABC',
            'cgc' => '11222333000181'
        ]);

        $this->assertEquals('11.222.333/0001-81', $customer->formatted_cgc);
    }

    /** @test */
    public function returns_cpf_type_for_11_digits()
    {
        $customer = new Customer([
            'name' => 'João Silva',
            'cgc' => '11144477735'
        ]);

        $this->assertEquals('CPF', $customer->cgc_type);
    }

    /** @test */
    public function returns_cnpj_type_for_14_digits()
    {
        $customer = new Customer([
            'name' => 'Empresa ABC',
            'cgc' => '11222333000181'
        ]);

        $this->assertEquals('CNPJ', $customer->cgc_type);
    }

    /** @test */
    public function returns_invalid_type_for_wrong_length()
    {
        $customer = new Customer([
            'name' => 'Test',
            'cgc' => '123456789'
        ]);

        $this->assertEquals('Inválido', $customer->cgc_type);
    }

    /** @test */
    public function removes_special_characters_from_cgc_before_validation()
    {
        $customer = new Customer([
            'name' => 'João Silva',
            'cgc' => '111.444.777-35' // CPF formatado
        ]);

        $this->assertTrue($customer->isValidCgc());
        $this->assertEquals('CPF', $customer->cgc_type);
    }

    /** @test */
    public function removes_special_characters_from_cnpj_before_validation()
    {
        $customer = new Customer([
            'name' => 'Empresa ABC',
            'cgc' => '11.222.333/0001-81' // CNPJ formatado
        ]);

        $this->assertTrue($customer->isValidCgc());
        $this->assertEquals('CNPJ', $customer->cgc_type);
    }

    /** @test */
    public function formatted_cgc_returns_original_for_invalid_length()
    {
        $customer = new Customer([
            'name' => 'Test',
            'cgc' => '123456789'
        ]);

        $this->assertEquals('123456789', $customer->formatted_cgc);
    }

    /** @test */
    public function uses_soft_deletes()
    {
        $customer = Customer::create([
            'name' => 'João Silva',
            'cgc' => '11144477735'
        ]);

        $customer->delete();

        // Verifica que ainda existe no banco mas está marcado como deletado
        $this->assertSoftDeleted('customers', [
            'id' => $customer->id
        ]);

        // Verifica que não aparece em consultas normais
        $this->assertCount(0, Customer::all());

        // Verifica que aparece em consultas com trashed
        $this->assertCount(1, Customer::withTrashed()->get());
    }

    /** @test */
    public function fillable_attributes_work_correctly()
    {
        $data = [
            'name' => 'João Silva',
            'cgc' => '11144477735'
        ];

        $customer = new Customer($data);

        $this->assertEquals('João Silva', $customer->name);
        $this->assertEquals('11144477735', $customer->cgc);
    }

    /** @test */
    public function validates_real_cpfs()
    {
        $validCpfs = [
            '11144477735',
            '01234567890',
            '12345678909',
        ];

        foreach ($validCpfs as $cpf) {
            $customer = new Customer([
                'name' => 'Test',
                'cgc' => $cpf
            ]);

            $this->assertTrue($customer->isValidCgc(), "CPF {$cpf} deveria ser válido");
        }
    }

    /** @test */
    public function validates_real_cnpjs()
    {
        $validCnpjs = [
            '11222333000181',
            '11444777000161',
            '98765432000198',
        ];

        foreach ($validCnpjs as $cnpj) {
            $customer = new Customer([
                'name' => 'Test',
                'cgc' => $cnpj
            ]);

            $this->assertTrue($customer->isValidCgc(), "CNPJ {$cnpj} deveria ser válido");
        }
    }

    /** @test */
    public function datetime_casting_works()
    {
        $customer = Customer::create([
            'name' => 'João Silva',
            'cgc' => '11144477735'
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $customer->created_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $customer->updated_at);
    }
} 