<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ReceitaCnpjService;
use Illuminate\Console\Command;

/**
 * Varre os consultores que têm CNPJ cadastrado, consulta a Receita (BrasilAPI)
 * e preenche o regime tributário de cada um. Feito para o levantamento da
 * Reforma Tributária — evita conferir consultor por consultor.
 *
 *   php artisan consultores:classificar-regime           # só quem ainda não tem regime
 *   php artisan consultores:classificar-regime --force   # reconsulta todo mundo com CNPJ
 */
class ClassificarRegimeConsultores extends Command
{
    protected $signature = 'consultores:classificar-regime {--force : Reconsulta mesmo quem já tem regime definido}';

    protected $description = 'Classifica o regime tributário (MEI/Simples/demais) dos consultores a partir do CNPJ, via Receita (BrasilAPI)';

    public function handle(ReceitaCnpjService $receita): int
    {
        $query = User::query()
            ->whereNotNull('cnpj')
            ->where('cnpj', '!=', '');

        if (! $this->option('force')) {
            $query->whereNull('tax_regime');
        }

        $users = $query->get();
        $this->info("Consultores com CNPJ a processar: {$users->count()}");

        $ok = 0;
        $fail = 0;
        $contagem = ['mei' => 0, 'simples_nacional' => 0, 'lucro_presumido' => 0];

        foreach ($users as $u) {
            $data = $receita->lookup($u->cnpj);

            if (! $data) {
                $fail++;
                $this->warn("  [falha]  {$u->name} — CNPJ {$u->cnpj}");
                continue;
            }

            $u->tax_regime = $data['tax_regime'];
            $u->tax_regime_checked_at = now();
            $u->save();

            $ok++;
            $contagem[$data['tax_regime']] = ($contagem[$data['tax_regime']] ?? 0) + 1;
            $this->line("  [ok]     {$u->name} → {$data['tax_regime']}");
        }

        $this->newLine();
        $this->info("Concluído. Classificados: {$ok} | Falhas: {$fail}");
        $this->table(
            ['Regime', 'Qtd'],
            collect($contagem)->map(fn ($qtd, $regime) => [$regime, $qtd])->values()->all()
        );

        return self::SUCCESS;
    }
}
