<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Áreas organizacionais do OKR (Comercial, Customer Success, Consultoria, etc.).
 * Estrutura própria — não reutiliza consultant_groups — com responsável (líder)
 * e usuários vinculados (pivot okr_area_user). Cadastráveis pelo gestor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('okr_areas')) {
            Schema::create('okr_areas', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->string('name', 200);
                $t->text('description')->nullable();
                $t->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete()->comment('Líder/responsável da área');
                $t->boolean('active')->default(true);
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->softDeletes();

                $t->index('name');
                $t->index('active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_areas');
    }
};
