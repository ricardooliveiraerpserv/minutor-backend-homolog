<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro de DEPARTAMENTOS do Help Desk, escopo POR CLIENTE (customer).
 * Estilo Movidesk: cada organização (cliente) tem seus próprios departamentos,
 * e uma pessoa (usuário cliente) pertence a um departamento do seu cliente.
 *
 * `users.helpdesk_department_id` = departamento da pessoa (nullable). company_id
 * carimbado p/ multi-empresa (como as demais tabelas do HD).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            // Nome único por cliente (ignora soft-deletados via índice parcial).
            $table->unique(['customer_id', 'name'], 'helpdesk_departments_customer_name_unique');
            $table->index(['customer_id', 'active']);
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'helpdesk_department_id')) {
                $table->unsignedBigInteger('helpdesk_department_id')->nullable()->after('helpdesk_access_profile_id');
                $table->foreign('helpdesk_department_id')->references('id')->on('helpdesk_departments')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'helpdesk_department_id')) {
                $table->dropForeign(['helpdesk_department_id']);
                $table->dropColumn('helpdesk_department_id');
            }
        });
        Schema::dropIfExists('helpdesk_departments');
    }
};
