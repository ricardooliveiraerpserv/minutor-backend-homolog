<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inativa automaticamente CONSULTORES e PARCEIROS que ficaram N dias (default 180)
 * sem NENHUM apontamento no Minutor.
 *
 * Regra (roda 1x/dia):
 *  - Escopo: users.type IN ('consultor', 'parceiro_admin'). Admin/coordenador/administrativo/cliente
 *    NÃO entram na regra.
 *  - Referência = data do ÚLTIMO apontamento (MAX(timesheets.date), ignorando soft-deletes).
 *    Quem NUNCA apontou não entra na regra (sem apontamento, sem relógio).
 *  - Se o último apontamento é anterior ao corte (hoje - N dias) e o usuário está ativo →
 *    enabled=false + auto_inactivated_at=now + revoga tokens (derruba a sessão).
 *  - Reativação AUTOMÁTICA, porém só de quem o robô inativou (auto_inactivated_at != null):
 *    se voltar a ter apontamento dentro da janela → enabled=true + auto_inactivated_at=null.
 *    Desativações MANUAIS (auto_inactivated_at = null) nunca são tocadas.
 */
class DeactivateStaleConsultantsCommand extends Command
{
    protected $signature   = 'consultants:deactivate-stale {--days=180 : Dias sem apontamento para inativar} {--dry-run : Só mostra o que faria, sem gravar}';
    protected $description  = 'Inativa consultores/parceiros com N dias (default 180) sem apontamento; reativa os auto-inativados que voltaram a apontar.';

    private const TIPOS = ['consultor', 'parceiro_admin'];

    public function handle(): int
    {
        $days   = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::today()->subDays($days)->toDateString();

        // Último apontamento por usuário (bypassa scopes de empresa; respeita soft-delete manualmente).
        $lastByUser = DB::table('timesheets')
            ->whereNull('deleted_at')
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(date) as last_date')
            ->pluck('last_date', 'user_id');

        $users = User::whereIn('type', self::TIPOS)->get(['id', 'name', 'type', 'enabled', 'auto_inactivated_at']);

        $inativados = [];
        $reativados = [];

        foreach ($users as $u) {
            $last = $lastByUser[$u->id] ?? null;

            // Reativação: só quem o robô inativou e voltou a apontar dentro da janela.
            if (! $u->enabled) {
                if ($u->auto_inactivated_at && $last !== null && $last >= $cutoff) {
                    $reativados[] = "{$u->name} (#{$u->id}, último {$last})";
                    if (! $dryRun) {
                        $u->enabled = true;
                        $u->auto_inactivated_at = null;
                        $u->save();
                    }
                }
                continue;
            }

            // Inativação: precisa ter histórico (last != null) e estar além do corte.
            if ($last !== null && $last < $cutoff) {
                $inativados[] = "{$u->name} (#{$u->id}, último {$last})";
                if (! $dryRun) {
                    $u->enabled = false;
                    $u->auto_inactivated_at = now();
                    $u->save();
                    $u->tokens()->delete(); // derruba sessões ativas
                }
            }
        }

        $resumo = sprintf(
            '%sConsultores/parceiros sem apontamento há %d dias (corte %s): %d inativado(s), %d reativado(s).',
            $dryRun ? '[DRY-RUN] ' : '', $days, $cutoff, count($inativados), count($reativados)
        );
        $this->info($resumo);
        foreach ($inativados as $x) { $this->line("  inativado: {$x}"); }
        foreach ($reativados as $x) { $this->line("  reativado: {$x}"); }

        if (! $dryRun && (count($inativados) || count($reativados))) {
            Log::info('consultants:deactivate-stale', [
                'days' => $days, 'cutoff' => $cutoff,
                'inativados' => $inativados, 'reativados' => $reativados,
            ]);
        }

        return self::SUCCESS;
    }
}
