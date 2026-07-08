<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — Políticas de SLA + metas por prioridade.
 *
 * Resolução de SLA de um ticket (feita no service, fase posterior):
 *   override no ticket → política do contrato → política do cliente → política da categoria → política default.
 * Por isso a política pode ser escopada (contract_id / customer_id) OU global (is_default).
 *
 * business_hours (JSON, opcional) prepara cálculo por horário comercial:
 *   { "tz": "America/Sao_Paulo", "days": [1,2,3,4,5], "start": "09:00", "end": "18:00", "holidays": true }
 * Sem isso, o cálculo é corrido (24x7). Semeia uma política default + 4 metas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('helpdesk_sla_policies')) {
            Schema::create('helpdesk_sla_policies', function (Blueprint $table) {
                $table->id();
                $table->string('name', 140);
                $table->text('description')->nullable();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
                $table->json('business_hours')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['active', 'is_default']);
                $table->index('customer_id');
                $table->index('contract_id');
            });
        }

        if (!Schema::hasTable('helpdesk_sla_targets')) {
            Schema::create('helpdesk_sla_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sla_policy_id')->constrained('helpdesk_sla_policies')->cascadeOnDelete();
                $table->string('priority', 12);                 // baixa | normal | alta | urgente
                $table->integer('first_response_minutes')->nullable();
                $table->integer('resolution_minutes')->nullable();
                $table->timestamps();
                $table->unique(['sla_policy_id', 'priority']);
            });
        }

        // Política default + metas (minutos corridos). Operável desde o go-live.
        if (DB::table('helpdesk_sla_policies')->count() === 0) {
            $policyId = DB::table('helpdesk_sla_policies')->insertGetId([
                'name'        => 'SLA Padrão',
                'description' => 'Política de SLA aplicada quando não há regra específica de contrato/cliente/categoria.',
                'is_default'  => true,
                'active'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            DB::table('helpdesk_sla_targets')->insert([
                ['sla_policy_id' => $policyId, 'priority' => 'baixa',   'first_response_minutes' => 480, 'resolution_minutes' => 4800, 'created_at' => now(), 'updated_at' => now()],
                ['sla_policy_id' => $policyId, 'priority' => 'normal',  'first_response_minutes' => 240, 'resolution_minutes' => 2880, 'created_at' => now(), 'updated_at' => now()],
                ['sla_policy_id' => $policyId, 'priority' => 'alta',    'first_response_minutes' => 120, 'resolution_minutes' => 960,  'created_at' => now(), 'updated_at' => now()],
                ['sla_policy_id' => $policyId, 'priority' => 'urgente', 'first_response_minutes' => 30,  'resolution_minutes' => 240,  'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_sla_targets');
        Schema::dropIfExists('helpdesk_sla_policies');
    }
};
