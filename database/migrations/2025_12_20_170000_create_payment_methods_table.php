<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Índices
            $table->index(['is_active']);
            $table->index(['sort_order']);
            $table->index(['code']);
        });

        // Inserir dados padrão (valores de PAYMENT_METHOD_OPTIONS)
        DB::table('payment_methods')->insert([
            [
                'code' => 'corporate_card',
                'name' => 'Cartão Corporativo',
                'description' => 'Pagamento realizado com cartão corporativo',
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'cash',
                'name' => 'Dinheiro',
                'description' => 'Pagamento realizado em dinheiro',
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'bank_transfer',
                'name' => 'Transferência Bancária',
                'description' => 'Pagamento realizado via transferência bancária',
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'pix',
                'name' => 'PIX',
                'description' => 'Pagamento realizado via PIX',
                'is_active' => true,
                'sort_order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'check',
                'name' => 'Cheque',
                'description' => 'Pagamento realizado com cheque',
                'is_active' => true,
                'sort_order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'other',
                'name' => 'Outro',
                'description' => 'Outro método de pagamento',
                'is_active' => true,
                'sort_order' => 6,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};

