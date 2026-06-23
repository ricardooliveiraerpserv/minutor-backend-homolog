<?php

namespace App\Console\Commands;

use App\Http\Controllers\CrmPipelineController;
use App\Models\Contract;
use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityEvent;
use App\Models\CrmPipeline;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * CRM Item 5 — Renovação automática. Roda diariamente: para cada contrato a
 * `--days` (90) dias do vencimento, cria uma oportunidade no funil "Renovação"
 * (etapa "Renovação Aberta"), herdando empresa/executivo/valor/tipo.
 *
 * PROTEÇÕES: idempotente — não cria se já existe renovação ABERTA para o mesmo
 * contrato (`renewal_contract_id`). Ignora contratos sem `data_vencimento`.
 */
class GerarRenovacoesCommand extends Command
{
    protected $signature = 'crm:gerar-renovacoes {--days=90 : Janela (dias) antes do vencimento} {--dry-run : Não grava, só relata}';
    protected $description = 'Cria oportunidades de renovação para contratos próximos do vencimento';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');
        $hoje   = Carbon::today();
        $limite = (clone $hoje)->addDays($days);

        CrmPipelineController::ensureSeeded();
        $pipeline = CrmPipeline::where('code', 'renovacao')->with('stages')->first();
        if (!$pipeline) {
            $this->error('Funil de renovação não encontrado.');
            return self::FAILURE;
        }
        $stage = $pipeline->stages->firstWhere('name', 'Renovação Aberta')
            ?? $pipeline->stages->where('is_won', false)->where('is_lost', false)->sortBy('ordem')->first();

        // Contratos vivos (com projeto gerado) a N dias do vencimento.
        $contratos = Contract::whereNotNull('data_vencimento')
            ->whereNotNull('project_id')
            ->whereDate('data_vencimento', '>=', $hoje)
            ->whereDate('data_vencimento', '<=', $limite)
            ->get();

        $criadas = 0; $puladas = 0;
        foreach ($contratos as $c) {
            // PROTEÇÃO (Item 5): existe QUALQUER oportunidade ABERTA vinculada a este contrato?
            // Vínculo = renovação deste contrato (renewal_contract_id) OU oportunidade que gerou
            // o contrato (contract_id). status='aberto' cobre todas as etapas (Lead..Negociação).
            $jaExiste = CrmOpportunity::where('status', 'aberto')
                ->where(fn ($q) => $q->where('renewal_contract_id', $c->id)->orWhere('contract_id', $c->id))
                ->exists();
            if ($jaExiste) {
                $puladas++;
                // Loga o evento UMA vez por contrato (evita spam diário na timeline).
                $jaLogado = \App\Models\CrmCustomerEvent::where('event_type', 'renovacao_ignorada')
                    ->where('meta->contract_id', $c->id)->exists();
                if (!$dryRun && !$jaLogado) {
                    \App\Models\CrmCustomerEvent::log($c->customer_id, 'renovacao_ignorada',
                        'Renovação ignorada — oportunidade já existente (contrato #' . $c->id . ')',
                        ['contract_id' => $c->id]);
                }
                continue;
            }

            if ($dryRun) { $criadas++; continue; }

            $valor = (float) ($c->valor_projeto ?? $c->valor_hora ?? 0);
            $opp = CrmOpportunity::create([
                'title'               => 'Renovação — ' . ($c->project_name ?: ('Contrato #' . $c->id)),
                'descricao'           => 'Renovação do contrato ' . ($c->project_name ?: ('#' . $c->id)) . '.',
                'customer_id'         => $c->customer_id,
                'pipeline_id'         => $pipeline->id,
                'stage_id'            => $stage?->id,
                'responsavel_id'      => $c->executivo_conta_id ?? $c->vendedor_id,
                'tipo'                => 'renovacao',
                'origem'              => 'renovacao_automatica',
                'valor'               => $valor,
                'status'              => 'aberto',
                'data_abertura'       => $hoje->toDateString(),
                'previsao_fechamento' => $c->data_vencimento,
                'renewal_contract_id' => $c->id,
                'notas'               => "Renovação automática. Contrato #{$c->id}"
                    . ($c->tipo_contrato ? " · tipo {$c->tipo_contrato}" : '')
                    . " · valor atual " . number_format($valor, 2, ',', '.')
                    . " · vence em " . Carbon::parse($c->data_vencimento)->format('d/m/Y'),
            ]);
            CrmOpportunityEvent::log($opp->id, 'renewal_created', ['to_value' => 'Contrato #' . $c->id]);
            $criadas++;
        }

        $semVenc = Contract::whereNull('data_vencimento')->whereNotNull('project_id')->count();
        $this->info(($dryRun ? '[DRY-RUN] ' : '')
            . "Renovações: {$criadas} criada(s), {$puladas} já existentes (puladas). "
            . "Janela: {$days}d (até {$limite->format('d/m/Y')}). "
            . "{$semVenc} contrato(s) sem data_vencimento ignorados.");

        return self::SUCCESS;
    }
}
