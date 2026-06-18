<?php

namespace App\Services;

use App\Models\CrmCustomerEvent;
use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityEvent;
use App\Models\CrmPipelineStage;
use App\Models\CrmProposal;
use App\Models\CrmStageAutomation;
use App\Models\CrmTask;
use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Motor de automações de etapa (Fase 3). Ao ENTRAR numa etapa, executa as automações
 * ativas em ordem. Cada tipo é um handler isolado (estratégia) — adicionar tipo novo =
 * adicionar um método handle*, sem tocar no núcleo. Cada automação roda em try/catch:
 * uma falha NUNCA quebra a mudança de etapa (apenas registra erro na timeline).
 */
class StageAutomationRunner
{
    public function runOnEnter(CrmOpportunity $o, CrmPipelineStage $stage): void
    {
        $autos = CrmStageAutomation::where('stage_id', $stage->id)
            ->where('evento', 'ao_entrar')->where('ativa', true)->orderBy('ordem')->get();

        foreach ($autos as $a) {
            try {
                $this->dispatch($a, $o);
                CrmOpportunityEvent::log($o->id, 'automacao', ['to_value' => $a->tipo]);
            } catch (\Throwable $e) {
                Log::warning("[CRM automacao] {$a->tipo} falhou na opp {$o->id}: " . $e->getMessage());
                CrmOpportunityEvent::log($o->id, 'automacao_erro', ['field' => $a->tipo, 'to_value' => $e->getMessage()]);
            }
        }
    }

    private function dispatch(CrmStageAutomation $a, CrmOpportunity $o): void
    {
        $c = $a->config ?? [];
        match ($a->tipo) {
            'criar_tarefa'           => $this->handleCriarTarefa($o, $c),
            'alterar_status_empresa' => $this->handleAlterarStatus($o, $c),
            'enviar_email'           => $this->handleEnviarEmail($o, $c),
            'notificar'              => $this->handleNotificar($o, $c),
            'gerar_proposta'         => $this->handleGerarProposta($o, $c),
            'gerar_contrato'         => $this->handleGerarContrato($o, $c),
            'webhook'                => $this->handleWebhook($o, $a, $c),
            default                  => null,
        };
    }

    private function handleCriarTarefa(CrmOpportunity $o, array $c): void
    {
        CrmTask::create([
            'opportunity_id' => $o->id,
            'tipo'           => in_array($c['tipo'] ?? '', CrmTask::TIPOS, true) ? $c['tipo'] : 'ligacao',
            'titulo'         => $c['titulo'] ?? 'Follow-up automático',
            'data'           => now()->addDays((int) ($c['dias_prazo'] ?? 1)),
            'responsavel_id' => $c['responsavel_id'] ?? $o->responsavel_id,
            'prioridade'     => $c['prioridade'] ?? 'media',
            'created_by_id'  => auth()->id(),
        ]);
    }

    private function handleAlterarStatus(CrmOpportunity $o, array $c): void
    {
        $status = $c['status'] ?? null;
        if (!in_array($status, Customer::CRM_STATUSES, true)) return;
        $cust = Customer::find($o->customer_id);
        if (!$cust) return;
        // Respeita a regra de CNPJ (não promove a cliente/contrato sem CNPJ).
        if (Customer::statusRequiresCgc($status) && empty($cust->cgc)) {
            CrmCustomerEvent::log($cust->id, 'status_pendente_cnpj', "Automação não promoveu a {$status}: falta CNPJ");
            return;
        }
        $cust->update(['crm_status' => $status]);
        CrmCustomerEvent::log($cust->id, 'status_automacao', "Status alterado para {$status} (automação)");
    }

    private function handleEnviarEmail(CrmOpportunity $o, array $c): void
    {
        $o->loadMissing('contato:id,email', 'responsavel:id,email');
        $to = match ($c['para'] ?? 'contato') {
            'fixo'        => $c['email_fixo'] ?? null,
            'responsavel' => $o->responsavel?->email,
            default       => $o->contato?->email,
        };
        if (!$to) return;
        $assunto = $c['assunto'] ?? 'Atualização comercial';
        $corpo   = $c['corpo'] ?? "Atualização da oportunidade: {$o->title}.";
        Mail::raw($corpo, fn ($m) => $m->to($to)->subject($assunto));
    }

    private function handleNotificar(CrmOpportunity $o, array $c): void
    {
        // Sem infra de push dedicada: registra aviso na timeline da empresa (extensível).
        CrmCustomerEvent::log($o->customer_id, 'notificacao', $c['mensagem'] ?? "Oportunidade \"{$o->title}\" mudou de etapa");
    }

    private function handleGerarProposta(CrmOpportunity $o, array $c): void
    {
        $numero = (int) CrmProposal::where('opportunity_id', $o->id)->max('numero') + 1;
        CrmProposal::create([
            'opportunity_id' => $o->id,
            'numero'         => $numero,
            'versao'         => 1,
            'data_emissao'   => now()->toDateString(),
            'valor'          => (float) $o->valor,
            'descontos'      => 0,
            'vendedor_id'    => $o->responsavel_id,
            'status'         => 'rascunho',
            'created_by_id'  => auth()->id(),
        ]);
        CrmOpportunityEvent::log($o->id, 'note', ['to_value' => "Proposta #{$numero} gerada (automação)"]);
    }

    private function handleGerarContrato(CrmOpportunity $o, array $c): void
    {
        // SEMI-AUTOMÁTICO (decisão aprovada): NÃO gera contrato automaticamente ao arrastar
        // o card. Apenas SINALIZA que está pronto — a geração segue manual (botão Converter),
        // evitando contratos criados por engano. Idempotente.
        if ($o->contract_id) return;
        CrmCustomerEvent::log($o->customer_id, 'contrato_sugerido', "Oportunidade \"{$o->title}\" pronta para gerar contrato");
        CrmOpportunityEvent::log($o->id, 'contrato_pendente', ['to_value' => 'Pronto para converter em contrato']);
    }

    private function handleWebhook(CrmOpportunity $o, CrmStageAutomation $a, array $c): void
    {
        $url = $c['url'] ?? null;
        if (!$url) return;
        Http::timeout(10)->post($url, [
            'opportunity_id' => $o->id, 'title' => $o->title, 'customer_id' => $o->customer_id,
            'valor' => (float) $o->valor, 'stage_id' => $o->stage_id, 'event' => 'stage_entered',
        ]);
    }
}
