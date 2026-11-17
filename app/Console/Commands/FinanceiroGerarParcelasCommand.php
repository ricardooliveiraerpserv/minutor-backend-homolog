<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Financeiro\ParcelaGenerator;
use Illuminate\Console\Command;

/**
 * Gera/atualiza as parcelas de faturamento. Padrão (sem --competencia): mês ANTERIOR (pega
 * fechamentos/excedentes que encerraram) e mês ATUAL (recorrentes). Idempotente — pode rodar
 * todo dia. Não emite nada: NFS-e e boleto são a F3/F4.
 */
class FinanceiroGerarParcelasCommand extends Command
{
    protected $signature = 'financeiro:gerar-parcelas {--competencia= : AAAA-MM} {--company= : id da empresa}';
    protected $description = 'Financeiro: gera/atualiza parcelas (recorrentes, On Demand, excedentes, despesas)';

    public function handle(ParcelaGenerator $gen): int
    {
        $competencias = $this->option('competencia')
            ? [$this->option('competencia')]
            : [now()->subMonthNoOverflow()->format('Y-m'), now()->format('Y-m')];

        $empresas = $this->option('company')
            ? Company::whereKey((int) $this->option('company'))->get()
            : Company::where('status', 'active')->get();

        foreach ($empresas as $e) {
            foreach ($competencias as $ym) {
                $r = $gen->gerar($e->id, $ym);
                $this->line(sprintf('%s %s: +%d criadas, %d atualizadas, %d inalteradas, %d travadas%s',
                    $e->name, $ym, $r['criadas'], $r['atualizadas'], $r['inalteradas'], $r['travadas'],
                    $r['sem_contrato'] ? ' | sem contrato: ' . count($r['sem_contrato']) : ''));
            }
        }
        return self::SUCCESS;
    }
}
