<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

/**
 * Mantém o cache da tela "Gestão de Contratos" (/projects?gestao=true) sempre quente.
 *
 * O miss desse endpoint é caro (~vários segundos p/ montar+serializar todos os projetos).
 * O cache (chave gestaojson:...) deixa o HIT em ~0.2s, mas expira (TTL) e é invalidado por
 * edição de projeto/aporte. Este job dispara um request interno (header X-Cache-Warm) que
 * FORÇA o recompute+gravação, renovando o TTL antes que o usuário pegue um miss frio.
 *
 * Faz um HTTP real p/ o backend (http://backend:8080) autenticado como um admin, então passa
 * por TODO o middleware (auth + CompanyContext) e popula EXATAMENTE a mesma chave que o FE lê
 * (escopo 'admin' compartilhado). O header não entra na chave.
 */
class WarmGestaoCache extends Command
{
    protected $signature = 'projects:warm-gestao-cache';

    protected $description = 'Mantém quente o cache da tela Gestão de Contratos (evita o miss frio)';

    public function handle(): int
    {
        $admin = User::where('type', 'admin')->where('enabled', true)->orderBy('id')->first();
        if (!$admin) {
            $this->warn('Nenhum admin habilitado — nada a aquecer.');
            return 0;
        }

        $plain = $admin->createToken('gestao-warmer')->plainTextToken;
        $base  = env('WARM_INTERNAL_URL', 'http://backend:8080') . '/api/v1/projects';
        $month = Carbon::now()->format('Y-m');

        // Variantes que o FE realmente pede (lista base + mês de consumo corrente).
        $queries = [
            'pageSize=1000&gestao=true',
            "pageSize=1000&gestao=true&year_months={$month}",
        ];

        try {
            foreach ($queries as $qs) {
                try {
                    $r = Http::withToken($plain)
                        ->withHeaders(['Accept' => 'application/json', 'X-Cache-Warm' => '1'])
                        ->timeout(60)
                        ->get($base . '?' . $qs);
                    $this->info("warm [{$qs}] → HTTP {$r->status()} ({$r->transferStats?->getTransferTime()}s)");
                } catch (\Throwable $e) {
                    $this->error("falha ao aquecer [{$qs}]: {$e->getMessage()}");
                }
            }
        } finally {
            // token temporário, sempre removido
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $admin->id)
                ->where('tokenable_type', get_class($admin))
                ->where('name', 'gestao-warmer')
                ->delete();
        }

        return 0;
    }
}
