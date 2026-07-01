<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caderno de participantes por cliente (P-E.2.2): validadores/assinantes ficam gravados e
 * reaparecem automaticamente nas próximas propostas do mesmo cliente (incluir/excluir).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('crm_customer_signers')) return;
        Schema::create('crm_customer_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('email');
            $table->string('cargo', 120)->nullable();
            $table->string('parte', 16)->nullable();   // contratada | contratante
            $table->json('roles');                       // ['reviewer','approver','signer']
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['customer_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_customer_signers');
    }
};
