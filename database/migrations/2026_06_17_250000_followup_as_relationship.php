<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap Fase 7 — Follow-up como RELACIONAMENTO. crm_tasks deixa de ser só "tarefa da
 * oportunidade": passa a pertencer à EMPRESA (customer_id), com vínculos opcionais a
 * contrato/projeto/atividade e uma categoria de relacionamento. opportunity_id vira opcional.
 * Atividade (execução do Cronograma) ≠ Follow-up (relacionamento). activity_id fica preparado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_tasks', 'customer_id'))  $table->foreignId('customer_id')->nullable()->after('id')->constrained('customers')->cascadeOnDelete();
            if (!Schema::hasColumn('crm_tasks', 'contract_id'))  $table->foreignId('contract_id')->nullable()->after('opportunity_id')->constrained('contracts')->nullOnDelete();
            if (!Schema::hasColumn('crm_tasks', 'project_id'))   $table->foreignId('project_id')->nullable()->after('contract_id')->constrained('projects')->nullOnDelete();
            if (!Schema::hasColumn('crm_tasks', 'activity_id'))  $table->unsignedBigInteger('activity_id')->nullable()->after('project_id'); // Cronograma (futuro)
            if (!Schema::hasColumn('crm_tasks', 'categoria'))    $table->string('categoria', 40)->nullable()->after('tipo'); // relacionamento
        });

        // opportunity_id passa a ser opcional (follow-up pode ser só da empresa).
        DB::statement('ALTER TABLE crm_tasks ALTER COLUMN opportunity_id DROP NOT NULL');
        // Backfill: customer_id a partir da oportunidade existente.
        DB::statement('UPDATE crm_tasks t SET customer_id = o.customer_id FROM crm_opportunities o WHERE o.id = t.opportunity_id AND t.customer_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            foreach (['customer_id', 'contract_id', 'project_id'] as $c) if (Schema::hasColumn('crm_tasks', $c)) $table->dropConstrainedForeignId($c);
            foreach (['activity_id', 'categoria'] as $c) if (Schema::hasColumn('crm_tasks', $c)) $table->dropColumn($c);
        });
    }
};
