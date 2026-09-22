<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Timesheet;
use App\Services\MovideskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Re-resolve apontamentos que ficaram no CLIENTE PADRÃO (fallback) do Movidesk.
 *
 * Motivo: o sync de rotina só relê tickets com lastUpdate na janela recente (~6h). Quando
 * um chamado é aberto por um agente ERPSERV e o solicitante é trocado DEPOIS que o ticket
 * saiu da janela (ou o depto/empresa não resolveu na 1ª passada), o apontamento fica preso
 * no cliente padrão e a varredura nunca mais o relê.
 *
 * Este passe pega esses apontamentos (não-manuais, do Movidesk, no cliente padrão), re-busca
 * o ticket e chama processTicket — que RE-RESOLVE cliente E projeto pela regra normal (CNPJ da
 * organização / depto→empresa-mãe). O upgrade padrão→cliente real é permitido; o downgrade
 * real→padrão é bloqueado no próprio sync. Assim, se o solicitante hoje é de empresa cadastrada,
 * o apontamento passa a apontar no cliente/projeto correto sozinho.
 *
 * Throttle: um ticket que segue no padrão após o reprocesso (ERPSERV legítimo ou org não
 * cadastrada) é marcado no cache por alguns dias, pra não re-bater na API do Movidesk a cada run.
 */
class MovideskReresolveDefaultsCommand extends Command
{
    protected $signature = 'movidesk:reresolve-defaults
        {--execute : Aplica de fato (processTicket). Sem essa flag é dry-run.}
        {--limit=40 : Máximo de TICKETS reprocessados por execução (protege a API do Movidesk).}
        {--ticket= : Reprocessa só este ticket (ignora o throttle de cache).}';

    protected $description = 'Re-resolve apontamentos parados no cliente PADRÃO do Movidesk (corrige cliente+projeto quando o solicitante é/virou de empresa cadastrada).';

    private const CHECKED_TTL_DAYS = 3;

    public function handle(MovideskService $svc): int
    {
        $execute  = (bool) $this->option('execute');
        $limit    = max(1, (int) $this->option('limit'));
        $onlyTk   = $this->option('ticket') ? (string) $this->option('ticket') : null;
        $defaultId = $svc->defaultCustomerId();
        $mode = $execute ? 'EXECUTE' : 'DRY-RUN';

        if (!$defaultId) {
            $this->error('Cliente padrão do Movidesk não configurado (movidesk_default_customer_id).');
            return self::FAILURE;
        }
        $this->info("Modo: {$mode} | cliente padrão=#{$defaultId} | limite={$limit} tickets");

        // Tickets DISTINTOS com apontamentos não-manuais parados no cliente padrão.
        $q = Timesheet::query()
            ->where('customer_id', $defaultId)
            ->where('manual_project_edit', false)
            ->whereNotNull('movidesk_appointment_id')
            ->whereNotNull('ticket');
        if ($onlyTk) {
            $q->where('ticket', $onlyTk);
        }
        $tickets = $q->distinct()->orderByDesc('ticket')->pluck('ticket');

        $sum = ['candidatos' => $tickets->count(), 'processados' => 0, 'corrigidos' => 0, 'seguiu_padrao' => 0, 'fetch_falhou' => 0, 'erros' => 0, 'pulados_cache' => 0];
        $done = 0;

        foreach ($tickets as $ticket) {
            if ($done >= $limit) {
                $this->line("… limite de {$limit} tickets atingido; restam " . ($sum['candidatos'] - $done - $sum['pulados_cache']) . " pra próxima run.");
                break;
            }
            $cacheKey = "movidesk:reresolve:checked:{$ticket}";
            if (!$onlyTk && Cache::has($cacheKey)) { $sum['pulados_cache']++; continue; }

            try {
                $data = $svc->fetchTicket((int) $ticket);
                if (!$data) {
                    $this->warn("ticket={$ticket}: fetch falhou — mantém");
                    $sum['fetch_falhou']++;
                    continue;
                }
                $done++;
                $resolved = $svc->resolveTicketCustomerId($data);
                $willFix  = $resolved && $resolved !== $defaultId;
                $toName   = $willFix ? (Customer::find($resolved)?->name ?? "#{$resolved}") : 'PADRÃO (sem mudança)';
                $this->line("ticket={$ticket} → " . ($willFix ? "CORRIGE p/ {$toName} (#{$resolved})" : 'segue no PADRÃO'));

                if (!$execute) {
                    if (!$willFix) $sum['seguiu_padrao']++; else $sum['corrigidos']++;
                    continue;
                }

                $svc->processTicket($data);
                $sum['processados']++;

                // Re-checa o estado no banco após o processTicket.
                $stillDefault = Timesheet::where('ticket', $ticket)
                    ->where('manual_project_edit', false)
                    ->where('customer_id', $defaultId)
                    ->exists();
                if ($stillDefault) {
                    $sum['seguiu_padrao']++;
                    Cache::put($cacheKey, 1, now()->addDays(self::CHECKED_TTL_DAYS));
                } else {
                    $sum['corrigidos']++;
                }
            } catch (\Throwable $e) {
                $sum['erros']++;
                $this->error("ticket={$ticket}: erro — " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("SUMMARY [{$mode}] candidatos={$sum['candidatos']} processados={$sum['processados']} corrigidos={$sum['corrigidos']} seguiu_padrao={$sum['seguiu_padrao']} pulados_cache={$sum['pulados_cache']} fetch_falhou={$sum['fetch_falhou']} erros={$sum['erros']}");
        if (!$execute && $sum['candidatos'] > 0) {
            $this->warn('Pra aplicar: php artisan movidesk:reresolve-defaults --execute');
        }
        return self::SUCCESS;
    }
}
