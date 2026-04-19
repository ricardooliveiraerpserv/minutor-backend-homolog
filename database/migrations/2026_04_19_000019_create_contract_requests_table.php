<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->constrained('users');
            $table->string('area_requisitante');
            $table->string('product_owner')->nullable();
            $table->string('modulo_tecnologia')->nullable();
            $table->string('tipo_necessidade');
            $table->string('nivel_urgencia');
            $table->text('descricao')->nullable();
            $table->text('cenario_atual')->nullable();
            $table->text('cenario_desejado')->nullable();
            $table->string('status')->default('pendente');
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notas_revisao')->nullable();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_requests');
    }
};
