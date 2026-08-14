<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\StageDelivery;
use App\Models\StageAllocation;

/**
 * SEED de cronograma (spec fechada) — só para o ENSAIO/migração:
 * Para cada projeto tipo "Projeto" SEM cronograma, cria 1 etapa + 1 atividade
 * no estágio equivalente ao do Demandas e Projetos, com o consultor alocado como
 * responsável (is_primary) e planned_hours=0. Datas = início/previsão do projeto.
 * Idempotente (só mexe onde não há etapas).
 */
return new class extends Migration
{
    public function up(): void
    {
        // status do projeto → status da ATIVIDADE (kanban 5 estados)
        $deliv = [
            'backlog' => 'backlog', 'awaiting_start' => 'backlog', 'planning' => 'backlog',
            'started' => 'in_progress', 'paused' => 'in_progress',
            'liberado_para_testes' => 'review',
            'em_producao' => 'done', 'finished' => 'done', 'cancelled' => 'done',
        ];
        // status do projeto → status da ETAPA
        $stageStatus = fn (string $s) => in_array($s, ['em_producao', 'finished', 'cancelled'], true)
            ? 'done' : ($s === 'paused' ? 'paused' : 'active');

        Project::query()
            ->whereHas('serviceType', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['projeto']))
            ->whereDoesntHave('stages')
            ->with('consultants')
            ->chunkById(100, function ($projects) use ($deliv, $stageStatus) {
                foreach ($projects as $p) {
                    $consultants = $p->consultants;         // project_consultants (Selecionar Equipe)
                    $primary = $consultants->first();        // 1º = responsável (is_primary)

                    $stage = ProjectStage::create([
                        'project_id'        => $p->id,
                        'parent_stage_id'   => null,
                        'name'              => 'Execução',
                        'status'            => $stageStatus($p->status),
                        'order_index'       => 0,
                        'expected_end_date' => $p->expected_end_date,
                    ]);

                    $delivery = StageDelivery::create([
                        'stage_id'            => $stage->id,
                        'title'               => 'Atividade do projeto',
                        'description'         => 'Card criado na migração para refletir o estágio atual do projeto. Detalhar as etapas/atividades reais.',
                        'status'              => $deliv[$p->status] ?? 'backlog',
                        'responsible_user_id' => $primary?->id,
                        'hours_planned'       => 0,
                        'order_index'         => 0,
                        'planned_start_at'    => $p->start_date,
                        'due_date'            => $p->expected_end_date,
                    ]);

                    foreach ($consultants as $i => $c) {
                        StageAllocation::create([
                            'stage_id'            => $stage->id,
                            'delivery_id'         => $delivery->id,
                            'user_id'             => $c->id,
                            'planned_hours'       => 0,
                            'allocation_start_at' => $p->start_date,
                            'allocation_end_at'   => $p->expected_end_date,
                            'is_primary'          => $i === 0,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Seed de dados — sem reversão automática.
    }
};
