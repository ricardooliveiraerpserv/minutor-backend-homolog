<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\MovideskPromaxCopyService;
use Illuminate\Console\Command;

/**
 * Cópia no Movidesk dos chamados PROMAX abertos no Minutor (suporte@). Ver MovideskPromaxCopyService.
 *
 *   php artisan movidesk:promax-copy                  # estado + candidatos (não escreve)
 *   php artisan movidesk:promax-copy --ativar [--desde="2026-11-15 00:00"]
 *   php artisan movidesk:promax-copy --desativar
 *   php artisan movidesk:promax-copy --rodar [--ticket=ID] [--forcar]   # uma passada agora
 */
class MovideskPromaxCopyCommand extends Command
{
    protected $signature = 'movidesk:promax-copy {--ativar} {--desativar} {--desde=} {--rodar} {--ticket=} {--forcar : roda mesmo desligado (teste)}';
    protected $description = 'Cópia Minutor→Movidesk dos chamados Promax abertos via suporte@ (mão única)';

    public function handle(MovideskPromaxCopyService $svc): int
    {
        if ($this->option('ativar')) {
            $since = $this->option('desde') ? \Carbon\Carbon::parse($this->option('desde')) : now();
            SystemSetting::set('movidesk_promax_copy_since', $since->toIso8601String(), 'string', 'movidesk', 'Cópia Promax: só chamados/interações a partir desta data');
            SystemSetting::set('movidesk_promax_copy_enabled', true, 'boolean', 'movidesk', 'Cópia Promax no Movidesk ligada');
            $this->info('ATIVADO a partir de ' . $since->format('d/m/Y H:i'));
        } elseif ($this->option('desativar')) {
            SystemSetting::set('movidesk_promax_copy_enabled', false, 'boolean', 'movidesk');
            $this->warn('DESATIVADO.');
        }

        $this->line('Ligado .......... ' . (MovideskPromaxCopyService::enabled() ? 'SIM' : 'não'));
        $this->line('Desde ........... ' . (MovideskPromaxCopyService::since()?->format('d/m/Y H:i') ?? '-'));
        $this->line('Clientes ........ ' . implode(',', MovideskPromaxCopyService::customerIds()));
        $this->line('Canais .......... ' . implode(',', MovideskPromaxCopyService::channels()));

        $run = $this->option('rodar');
        $res = $svc->run(!$run, $this->option('ticket') ? (int) $this->option('ticket') : null, $run && $this->option('forcar') || !$run);
        foreach ($res['candidates'] ?? [] as $c) $this->line('  • ' . $c);
        unset($res['candidates']);
        $this->line(json_encode($res, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
