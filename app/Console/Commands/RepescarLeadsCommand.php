<?php

namespace App\Console\Commands;

use App\Models\CrmContactType;
use App\Models\CrmCustomerEvent;
use App\Models\CrmTask;
use App\Models\CustomerCrmProfile;
use Illuminate\Console\Command;

/**
 * REPESCAGEM AUTOMÁTICA de leads descartados. Quando o motivo de descarte tem
 * `dias_repescagem`, o descarte grava `repescar_em`. Este comando (diário) devolve
 * ao funil os leads cujo prazo venceu: volta para a etapa inicial do MESMO funil de
 * prospecção, limpa o descarte e cria uma ATIVIDADE de retomada para o responsável.
 *
 * Roda em console (sem empresa ativa) → o CompanyScope não filtra; a empresa de cada
 * lead é derivada do funil em que ele vive (stage → pipeline → company_id), e a task
 * é criada já com esse company_id (senão ficaria invisível no scoping por empresa).
 */
class RepescarLeadsCommand extends Command
{
    protected $signature = 'crm:repescar-leads {--dry-run : Não grava nada, só relata}';
    protected $description = 'Repesca leads descartados cujo prazo de repescagem venceu (volta ao funil + cria atividade)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $hoje = now()->toDateString();
        $repescados = 0;
        $ignorados = 0;

        CustomerCrmProfile::whereNotNull('repescar_em')
            ->whereNotNull('lost_at')
            ->whereDate('repescar_em', '<=', $hoje)
            ->with(['qualificationStage.pipeline.stages', 'customer', 'discardReason'])
            ->chunkById(200, function ($lote) use (&$repescados, &$ignorados, $dry) {
                foreach ($lote as $p) {
                    $customer = $p->customer;
                    $pipeline = $p->qualificationStage?->pipeline;
                    if (!$customer || !$pipeline) { $ignorados++; continue; }

                    // Etapa inicial do MESMO funil (is_inicial, senão a 1ª não terminal por ordem).
                    $primeira = $pipeline->stages->firstWhere('is_inicial', true)
                        ?? $pipeline->stages->reject(fn ($s) => $s->is_won || $s->is_lost)->sortBy('ordem')->first()
                        ?? $pipeline->stages->sortBy('ordem')->first();
                    if (!$primeira) { $ignorados++; continue; }

                    $companyId = $pipeline->company_id;
                    $motivo = $p->discardReason?->name ?? $p->lost_reason;

                    if ($dry) {
                        $this->line("• {$customer->name} — repescaria p/ \"{$primeira->name}\" (motivo: " . ($motivo ?: '—') . ')');
                        $repescados++;
                        continue;
                    }

                    // Volta ao funil: limpa o descarte e reposiciona na etapa inicial.
                    $p->update([
                        'qualification_stage_id' => $primeira->id,
                        'lost_at'                => null,
                        'lost_reason'            => null,
                        'discard_reason_id'      => null,
                        'repescar_em'            => null,
                        'ultima_interacao_at'    => null,
                    ]);
                    // Lead segue como 'lead' (o descarte não muda crm_status); garante consistência.
                    if ($customer->crm_status !== 'lead') {
                        $customer->update(['crm_status' => 'lead']);
                    }

                    // Cria a ATIVIDADE de retomada para o responsável. company_id explícito
                    // porque o console não tem empresa ativa para o carimbo automático.
                    $tipo = CrmContactType::query()
                        ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                        ->orderBy('ordem')->value('slug') ?? 'ligacao';
                    $task = new CrmTask();
                    $task->fill([
                        'customer_id'    => $customer->id,
                        'tipo'           => $tipo,
                        'titulo'         => 'Repescagem — retomar contato' . ($motivo ? " (descartado: {$motivo})" : ''),
                        'data'           => now(),
                        'responsavel_id' => $customer->executive_id,
                        'created_by_id'  => null,
                    ]);
                    $task->company_id = $companyId;
                    $task->save();

                    CrmCustomerEvent::log($customer->id, 'repescado', 'Lead repescado automaticamente' . ($motivo ? " (era: {$motivo})" : ''));
                    $repescados++;
                }
            });

        $this->info(($dry ? '[dry-run] ' : '') . "Repescados: {$repescados}" . ($ignorados ? " | ignorados (sem funil/etapa): {$ignorados}" : ''));
        return self::SUCCESS;
    }
}
