<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — Status do chamado (workflow configurável, espelha o padrão de stages do CRM).
 *  - is_open      → chamado ainda em aberto (conta como pendência/fila).
 *  - is_resolved  → resolvido (marca resolved_at; ainda reabrível).
 *  - is_terminal  → encerrado de fato (fechado/cancelado).
 *  - sla_paused   → relógio de SLA pausa nesse status (ex.: aguardando cliente).
 * Semeia o workflow padrão para o módulo já nascer operável.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('helpdesk_statuses')) {
            Schema::create('helpdesk_statuses', function (Blueprint $table) {
                $table->id();
                $table->string('key', 40)->unique();      // novo|em_andamento|aguardando_cliente|resolvido|fechado|cancelado
                $table->string('label', 60);
                $table->string('color', 16)->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_default')->default(false);  // status inicial de um ticket novo
                $table->boolean('is_open')->default(true);
                $table->boolean('is_resolved')->default(false);
                $table->boolean('is_terminal')->default(false);
                $table->boolean('sla_paused')->default(false);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['active', 'sort_order']);
            });
        }

        if (DB::table('helpdesk_statuses')->count() === 0) {
            DB::table('helpdesk_statuses')->insert([
                ['key' => 'novo',               'label' => 'Novo',               'color' => '#3b82f6', 'sort_order' => 10, 'is_default' => true,  'is_open' => true,  'is_resolved' => false, 'is_terminal' => false, 'sla_paused' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'em_andamento',       'label' => 'Em andamento',       'color' => '#6366f1', 'sort_order' => 20, 'is_default' => false, 'is_open' => true,  'is_resolved' => false, 'is_terminal' => false, 'sla_paused' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'aguardando_cliente', 'label' => 'Aguardando cliente', 'color' => '#f59e0b', 'sort_order' => 30, 'is_default' => false, 'is_open' => true,  'is_resolved' => false, 'is_terminal' => false, 'sla_paused' => true,  'active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'resolvido',          'label' => 'Resolvido',          'color' => '#22c55e', 'sort_order' => 40, 'is_default' => false, 'is_open' => false, 'is_resolved' => true,  'is_terminal' => false, 'sla_paused' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'fechado',            'label' => 'Fechado',            'color' => '#64748b', 'sort_order' => 50, 'is_default' => false, 'is_open' => false, 'is_resolved' => true,  'is_terminal' => true,  'sla_paused' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'cancelado',          'label' => 'Cancelado',          'color' => '#ef4444', 'sort_order' => 60, 'is_default' => false, 'is_open' => false, 'is_resolved' => false, 'is_terminal' => true,  'sla_paused' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_statuses');
    }
};
