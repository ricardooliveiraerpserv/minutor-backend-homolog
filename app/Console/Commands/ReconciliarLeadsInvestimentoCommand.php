<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerCrmProfile;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * Reconciliação 1x (opcional, não-destrutiva): vincula leads-CRM existentes (sem
 * investimento_project_id) aos lead-projetos já presentes na árvore de Investimento
 * Comercial da ERPSERV (categoria 'Leads'), casando por nome normalizado.
 *
 * Uso: php artisan crm:reconciliar-leads-investimento [--commit]
 * Sem --commit roda em modo simulação (dry-run), só lista.
 * Doc: docs/crm-lead-investimento-integracao.md
 */
class ReconciliarLeadsInvestimentoCommand extends Command
{
    protected $signature = 'crm:reconciliar-leads-investimento {--commit : Aplica os vínculos (sem isso, só simula)}';
    protected $description = 'Vincula leads-CRM existentes aos lead-projetos da árvore de Investimento Comercial (por nome).';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $erpserv = Customer::whereRaw('UPPER(name) = ?', ['ERPSERV'])->first();
        if (!$erpserv) {
            $this->error('Cliente ERPSERV não encontrado.');
            return self::FAILURE;
        }

        // Lead-projetos disponíveis (categoria Leads, ainda não vinculados a um perfil).
        $jaVinculados = CustomerCrmProfile::whereNotNull('investimento_project_id')
            ->pluck('investimento_project_id')->all();

        $leadProjetos = Project::where('customer_id', $erpserv->id)
            ->where('is_investimento_comercial', true)
            ->where('categoria_interna', 'Leads')
            ->whereNotIn('id', $jaVinculados)
            ->get(['id', 'name', 'code']);

        $porNome = [];
        foreach ($leadProjetos as $p) {
            $porNome[$this->norm($p->name)][] = $p;
        }

        // Leads-CRM sem vínculo (perfil sem investimento_project_id).
        $leads = Customer::whereHas('crmProfile', fn ($q) => $q->whereNull('investimento_project_id'))
            ->with('crmProfile')
            ->get(['id', 'name', 'crm_status']);

        $vinculados = 0; $ambiguos = 0; $semMatch = 0;
        foreach ($leads as $lead) {
            $key = $this->norm($lead->name);
            $cands = $porNome[$key] ?? [];

            if (count($cands) === 1) {
                $proj = $cands[0];
                $this->line(($commit ? '[VINCULA] ' : '[dry] ') . "{$lead->name} (#{$lead->id}) → {$proj->code}");
                if ($commit) {
                    $lead->crmProfile->update(['investimento_project_id' => $proj->id]);
                }
                // Remove o projeto do pool para não reusar.
                $porNome[$key] = array_values(array_filter($cands, fn ($c) => $c->id !== $proj->id));
                $vinculados++;
            } elseif (count($cands) > 1) {
                $this->warn("[AMBÍGUO] {$lead->name} (#{$lead->id}) casa com " . count($cands) . ' projetos — revisar manualmente.');
                $ambiguos++;
            } else {
                $semMatch++;
            }
        }

        $this->newLine();
        $this->info(($commit ? 'APLICADO' : 'SIMULAÇÃO') . ": vinculados={$vinculados} · ambíguos={$ambiguos} · leads sem match={$semMatch}");
        $this->line('Lead-projetos sem lead-CRM correspondente: ' . collect($porNome)->flatten()->count());
        if (!$commit) {
            $this->comment('Rode com --commit para aplicar.');
        }

        return self::SUCCESS;
    }

    private function norm(?string $s): string
    {
        $s = mb_strtolower(trim($s ?? ''));
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    }
}
