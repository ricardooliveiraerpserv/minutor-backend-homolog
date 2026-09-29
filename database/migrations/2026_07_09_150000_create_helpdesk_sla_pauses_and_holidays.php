<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 do SLA:
 *  - helpdesk_sla_target_pauses: status que pausam o SLA POR REGRA/PRIORIDADE (uma lista
 *    própria por target). Substitui o "sla_paused global" (que vira só fallback).
 *  - helpdesk_sla_holidays: feriados POR CONTRATO/política (além dos nacionais globais).
 *  - policies.use_national_holidays: se a política também respeita os feriados nacionais.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('helpdesk_sla_target_pauses')) {
            Schema::create('helpdesk_sla_target_pauses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sla_target_id')->constrained('helpdesk_sla_targets')->cascadeOnDelete();
                $table->string('status_key', 60); // casa com helpdesk_statuses.key / timeline to_value
                $table->timestamps();
                $table->unique(['sla_target_id', 'status_key']);
            });
        }

        if (!Schema::hasTable('helpdesk_sla_holidays')) {
            Schema::create('helpdesk_sla_holidays', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sla_policy_id')->constrained('helpdesk_sla_policies')->cascadeOnDelete();
                $table->date('date');
                $table->string('name', 120)->nullable();
                $table->timestamps();
                $table->unique(['sla_policy_id', 'date']);
            });
        }

        if (!Schema::hasColumn('helpdesk_sla_policies', 'use_national_holidays')) {
            Schema::table('helpdesk_sla_policies', function (Blueprint $table) {
                $table->boolean('use_national_holidays')->default(true)->after('timezone');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_sla_target_pauses');
        Schema::dropIfExists('helpdesk_sla_holidays');
        if (Schema::hasColumn('helpdesk_sla_policies', 'use_national_holidays')) {
            Schema::table('helpdesk_sla_policies', function (Blueprint $table) {
                $table->dropColumn('use_national_holidays');
            });
        }
    }
};
