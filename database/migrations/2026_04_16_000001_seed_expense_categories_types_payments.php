<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Categorias de Despesa ────────────────────────────────────────────
        $categories = [
            ['name' => 'Alimentação',            'code' => 'alimentacao'],
            ['name' => 'Hospedagem',             'code' => 'hospedagem'],
            ['name' => 'Transporte',             'code' => 'transporte'],
            ['name' => 'Combustível',            'code' => 'combustivel'],
            ['name' => 'Material de Escritório', 'code' => 'material_escritorio'],
            ['name' => 'Software / Tecnologia',  'code' => 'software_tecnologia'],
            ['name' => 'Telefone / Comunicação', 'code' => 'telefone_comunicacao'],
            ['name' => 'Treinamento / Curso',    'code' => 'treinamento_curso'],
            ['name' => 'Outros',                 'code' => 'outros'],
        ];
        foreach ($categories as $cat) {
            $exists = DB::table('expense_categories')->where('code', $cat['code'])->exists();
            if (!$exists) {
                DB::table('expense_categories')->insert([
                    'name'       => $cat['name'],
                    'code'       => $cat['code'],
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // ─── Tipos de Despesa ─────────────────────────────────────────────────
        $types = [
            ['code' => 'reimbursement',  'name' => 'Reembolso'],
            ['code' => 'advance',        'name' => 'Adiantamento'],
            ['code' => 'corporate_card', 'name' => 'Cartão Corporativo'],
        ];
        foreach ($types as $t) {
            $exists = DB::table('expense_types')->where('code', $t['code'])->exists();
            if (!$exists) {
                DB::table('expense_types')->insert([
                    'code'       => $t['code'],
                    'name'       => $t['name'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // ─── Formas de Pagamento ──────────────────────────────────────────────
        $payments = [
            ['code' => 'pix',           'name' => 'PIX'],
            ['code' => 'credit_card',   'name' => 'Cartão de Crédito'],
            ['code' => 'debit_card',    'name' => 'Cartão de Débito'],
            ['code' => 'cash',          'name' => 'Dinheiro'],
            ['code' => 'bank_transfer', 'name' => 'Transferência Bancária'],
        ];
        foreach ($payments as $pm) {
            $exists = DB::table('payment_methods')->where('code', $pm['code'])->exists();
            if (!$exists) {
                DB::table('payment_methods')->insert([
                    'code'       => $pm['code'],
                    'name'       => $pm['name'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // não remove registros que podem ter sido usados
    }
};
