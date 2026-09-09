<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rateio de horas por PERÍODO (vigência). Cada projeto-servidor (is_rateio) passa a ter
 * N períodos (project_rateio_plans) com data_inicio/data_fim (fim nullable = aberto); os
 * destinos (project_rateio_targets) passam a pertencer a um período (plan_id). O serviço
 * escolhe o período ativo pela DATA do apontamento e normaliza os pesos p/ 100%.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('project_rateio_plans')) {
            Schema::create('project_rateio_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rateio_project_id')->constrained('projects')->cascadeOnDelete();
                $table->date('data_inicio')->nullable();  // null = desde sempre
                $table->date('data_fim')->nullable();      // null = sem data fim (aberto)
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->index('rateio_project_id');
            });
        }

        if (!Schema::hasColumn('project_rateio_targets', 'plan_id')) {
            Schema::table('project_rateio_targets', function (Blueprint $table) {
                $table->foreignId('plan_id')->nullable()->after('rateio_project_id')
                    ->constrained('project_rateio_plans')->cascadeOnDelete();
            });
        }

        // Migra destinos existentes (sem período) para um período "desde sempre" por servidor.
        $servers = DB::table('project_rateio_targets')->whereNull('plan_id')
            ->distinct()->pluck('rateio_project_id');
        foreach ($servers as $sid) {
            $planId = DB::table('project_rateio_plans')->insertGetId([
                'rateio_project_id' => $sid,
                'data_inicio'       => null,
                'data_fim'          => null,
                'position'          => 0,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
            DB::table('project_rateio_targets')->where('rateio_project_id', $sid)
                ->whereNull('plan_id')->update(['plan_id' => $planId]);
        }

        // O unique passa a ser (plan_id, target_project_id) — o mesmo destino pode
        // aparecer em períodos diferentes, mas não duas vezes no MESMO período.
        Schema::table('project_rateio_targets', function (Blueprint $table) {
            try { $table->dropUnique('project_rateio_targets_rateio_project_id_target_project_id_unique'); } catch (\Throwable $e) {}
        });
        Schema::table('project_rateio_targets', function (Blueprint $table) {
            try { $table->unique(['plan_id', 'target_project_id']); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('project_rateio_targets', function (Blueprint $table) {
            try { $table->dropUnique(['plan_id', 'target_project_id']); } catch (\Throwable $e) {}
            if (Schema::hasColumn('project_rateio_targets', 'plan_id')) {
                try { $table->dropConstrainedForeignId('plan_id'); } catch (\Throwable $e) { $table->dropColumn('plan_id'); }
            }
        });
        Schema::dropIfExists('project_rateio_plans');
    }
};
