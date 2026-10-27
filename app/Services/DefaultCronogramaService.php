<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Timesheet;
use Illuminate\Support\Facades\DB;

/**
 * Atividade padrão "Execução" do Cronograma.
 *
 * Todo projeto tipo Projeto (service_type_id=2) deve ter a etapa/atividade "Execução"
 * (1 por consultor). O seed de 2026-10-16 criou isso para os projetos existentes; este
 * serviço garante o mesmo de forma AUTOMÁTICA e LAZY: quando chega um apontamento de um
 * projeto que ainda não tem a atividade do usuário, cria etapa + atividade + alocação (0h)
 * e liga o apontamento. Assim projetos novos/importados nunca ficam "sem atividade".
 *
 * Regras de segurança:
 * - Inserts RAW (DB::table) — NÃO disparam os observers de stage/delivery → projects.status
 *   (coluna do kanban) permanece intacto.
 * - Status da atividade casa o rank do recomputeStatusFromStages (não move o card).
 * - NÃO toca projeto que já tem cronograma de verdade (etapas sem "Execução") — só opera no
 *   regime padrão "Execução", para não poluir cronogramas montados pela coordenação.
 * - 0h planejadas é intencional: o teto só libera via aporte da coordenadora (gate).
 */
class DefaultCronogramaService
{
    public const PROJETO_SERVICE_TYPE_ID = 2;

    /** Status do projeto → status da atividade "Execução" (casa o rank do recompute). */
    private function deliveryStatusFor(?string $projectStatus): string
    {
        return match ($projectStatus) {
            'em_producao', 'finished'     => 'done',
            'liberado_para_testes'        => 'review',
            'awaiting_start', 'cancelled' => 'backlog',
            default                       => 'in_progress', // started/paused/outros
        };
    }

    /**
     * Garante a atividade "Execução" do usuário do apontamento e liga o timesheet.
     * Idempotente e best-effort (nunca deve derrubar o save do apontamento).
     */
    public function ensureForTimesheet(Timesheet $ts): void
    {
        if ($ts->stage_delivery_id || !$ts->project_id || !$ts->user_id) {
            return;
        }

        $project = Project::withoutGlobalScopes()->find($ts->project_id);
        if (!$project || (int) $project->service_type_id !== self::PROJETO_SERVICE_TYPE_ID) {
            return;
        }
        // On Demand opera como sustentação: sem cronograma/atividade. Mesmo quando cadastrado
        // com categoria='projeto' (service_type_id=2), o apontamento fica direto no projeto,
        // sem a atividade "Execução". A equipe é alocada sem atividade e consome o saldo do pai.
        if ($project->isOnDemandType()) {
            return;
        }

        // Etapa "Execução" do regime padrão.
        $stage = DB::table('project_stages')
            ->where('project_id', $project->id)->whereNull('deleted_at')
            ->where('name', 'Execução')->first();

        if (!$stage) {
            // Projeto já tem cronograma de verdade (etapas, mas sem "Execução") → não poluir.
            $hasAnyStage = DB::table('project_stages')
                ->where('project_id', $project->id)->whereNull('deleted_at')->exists();
            if ($hasAnyStage) {
                return;
            }
            $now = now();
            $stageId = DB::table('project_stages')->insertGetId([
                'project_id' => $project->id, 'name' => 'Execução', 'responsible_user_id' => null,
                'hours_planned' => 0, 'status' => 'active', 'order_index' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $stageId = $stage->id;
        }

        // Atividade do consultor. Resolução (nessa ordem):
        //  1) Se o usuário JÁ tem alocação nessa etapa (unique stage_id+user_id), usa a atividade
        //     dela — pode ser uma atividade REAL que a coordenação montou sobre a etapa "Execução"
        //     (ex.: proj com cronograma de verdade). Assim NÃO criamos "Execução" duplicada nem
        //     violamos o unique da alocação.
        //  2) Senão, se já existe a atividade "Execução" do usuário, usa ela.
        //  3) Senão, cria a atividade "Execução" + alocação (0h).
        $alloc = DB::table('stage_allocations')
            ->where('stage_id', $stageId)->where('user_id', $ts->user_id)->first();

        if ($alloc && $alloc->delivery_id) {
            $deliveryId = $alloc->delivery_id;
        } else {
            $delivery = DB::table('stage_deliveries')
                ->where('stage_id', $stageId)->whereNull('deleted_at')
                ->where('responsible_user_id', $ts->user_id)->where('title', 'Execução')->first();

            if ($delivery) {
                $deliveryId = $delivery->id;
            } else {
                $now   = now();
                $order = (int) DB::table('stage_deliveries')->where('stage_id', $stageId)
                    ->whereNull('deleted_at')->max('order_index');
                $deliveryId = DB::table('stage_deliveries')->insertGetId([
                    'stage_id' => $stageId, 'title' => 'Execução', 'responsible_user_id' => $ts->user_id,
                    'hours_planned' => 0, 'priority' => 'medium',
                    'status' => $this->deliveryStatusFor($project->status),
                    'order_index' => $order + 1, 'client_involved' => false, 'approval_status' => 'none',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // Garante a alocação do usuário (respeita o unique stage_id+user_id).
            if ($alloc) {
                DB::table('stage_allocations')->where('id', $alloc->id)
                    ->update(['delivery_id' => $deliveryId, 'updated_at' => now()]);
            } else {
                $now = now();
                DB::table('stage_allocations')->insert([
                    'stage_id' => $stageId, 'user_id' => $ts->user_id, 'planned_hours' => 0,
                    'delivery_id' => $deliveryId, 'is_primary' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // Liga o apontamento (raw — sem reentrar nos hooks do Timesheet).
        DB::table('timesheets')->where('id', $ts->id)
            ->update(['stage_id' => $stageId, 'stage_delivery_id' => $deliveryId]);
        $ts->stage_id         = $stageId;
        $ts->stage_delivery_id = $deliveryId;
    }
}
