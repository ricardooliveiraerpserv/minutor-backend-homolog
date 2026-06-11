<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('adiantamentos')) {
            return;
        }

        Schema::create('adiantamentos', function (Blueprint $table) {
            $table->id();
            // Beneficiário polimórfico: 'consultor' → users.id | 'parceiro' → partners.id
            $table->string('beneficiario_tipo', 12);
            $table->unsignedBigInteger('beneficiario_id');
            $table->decimal('valor_total', 12, 2);
            $table->unsignedSmallInteger('num_parcelas')->default(1);
            $table->string('primeira_competencia', 7); // AAAA-MM (quando começa a descontar)
            $table->text('descricao')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['beneficiario_tipo', 'beneficiario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adiantamentos');
    }
};
