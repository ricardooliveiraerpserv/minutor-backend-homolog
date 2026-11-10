<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\MovideskPushPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * GO-LIVE do Help Desk: ERPSERV 100% no Minutor; só a PROMAX segue no Movidesk.
 *
 *   php artisan movidesk:hd-golive              # só mostra o estado (não muda nada)
 *   php artisan movidesk:hd-golive --ativar     # resposta do Minutor grava no Movidesk
 *   php artisan movidesk:hd-golive --desativar  # para de gravar no Movidesk
 *
 * O filtro do import fica SEMPRE em "exclude": chamado com as equipes Promax (pessoal da Promax) não
 * entra nem recebe escrita; só integra quando o responsável é equipe da ERPSERV.
 *
 * Opções do --ativar: --internas (nota interna também vai, como ação interna) · --desde="2026-11-20 08:00"
 * (corte; padrão = agora). Só a conta Movidesk da ERPSERV escreve; BIZIFY nunca (MovideskPushPolicy).
 */
class MovideskGoLiveCommand extends Command
{
    protected $signature = 'movidesk:hd-golive {--ativar} {--desativar} {--internas : Também envia notas internas (ação interna no Movidesk)} {--desde= : Corte: só comentários criados a partir desta data/hora vão ao Movidesk (padrão: agora)}';
    protected $description = 'Go-live HD: liga/desliga o envio Minutor→Movidesk dos chamados Promax (só ERPSERV) e mostra o checklist';

    public function handle(): int
    {
        if ($this->option('ativar') && $this->option('desativar')) {
            $this->error('Use --ativar OU --desativar.');
            return self::FAILURE;
        }

        if ($this->option('ativar')) {
            $since = now();
            if ($this->option('desde')) {
                try { $since = \Carbon\Carbon::parse($this->option('desde')); }
                catch (\Throwable $e) { $this->error('--desde inválido: ' . $e->getMessage()); return self::FAILURE; }
            }
            // Mantém (ou volta) o filtro em exclude: só entra o que está com equipe da ERPSERV.
            SystemSetting::set('movidesk_hd_team_filter_mode', 'exclude', 'string', 'movidesk', 'Import ignora chamados com as equipes Promax (só equipe ERPSERV)');
            SystemSetting::set('movidesk_hd_push_since', $since->toIso8601String(), 'string', 'movidesk', 'Go-live: só comentários a partir desta data vão ao Movidesk');
            SystemSetting::set('movidesk_hd_push_internal', (bool) $this->option('internas'), 'boolean', 'movidesk', 'Go-live: notas internas também vão ao Movidesk');
            SystemSetting::set('movidesk_hd_push_comments_enabled', true, 'boolean', 'movidesk', 'Go-live: resposta no Minutor grava no Movidesk (Promax/ERPSERV)');
            $this->info("ATIVADO. Respostas criadas a partir de {$since->format('d/m/Y H:i')} vão ao Movidesk (só conta ERPSERV, responsável em equipe ERPSERV).");
        } elseif ($this->option('desativar')) {
            SystemSetting::set('movidesk_hd_push_comments_enabled', false, 'boolean', 'movidesk');
            $this->warn('DESATIVADO. Nada mais é gravado no Movidesk (o import segue normal).');
        }

        $this->report();
        return self::SUCCESS;
    }

    private function report(): void
    {
        $on = MovideskPushPolicy::enabled();
        $since = MovideskPushPolicy::since();
        $primary = MovideskPushPolicy::primaryCompanyId();
        $mode = SystemSetting::get('movidesk_hd_team_filter_mode', 'exclude');
        $yes = fn ($b) => $b ? 'SIM' : 'não';

        $this->line('');
        $this->line('<options=bold>Movidesk (Promax)</>');
        $this->line('  Import ligado ...................... ' . $yes((bool) SystemSetting::get('movidesk_hd_import_enabled', false)));
        $this->line('  Import traz ........................ ' . ($mode === 'include' ? '⚠️ SÓ equipes Promax (ERRADO: rode --ativar p/ voltar a exclude)' : 'só chamados com equipe da ERPSERV (ignora equipes Promax)'));
        $this->line('  Equipes Promax (não integram) ...... ' . implode(', ', MovideskPushPolicy::promaxTeams()));
        $this->line('  Resposta grava no Movidesk ......... ' . $yes($on) . ($on ? ' (a partir de ' . $since->format('d/m/Y H:i') . ')' : ''));
        $this->line('  Notas internas também vão .......... ' . $yes(MovideskPushPolicy::pushInternal()));
        $this->line("  Conta que escreve .................. só empresa {$primary} (ERPSERV); BIZIFY nunca");
        $this->line('  Status/categoria vão ao Movidesk ... ' . $yes((bool) SystemSetting::get('movidesk_hd_writeback_enabled', false)) . ' (movidesk_hd_writeback_enabled + justificativa)');

        if ($on) {
            $pending = MovideskPushPolicy::scopeComments(DB::table('helpdesk_ticket_comments as c')
                ->join('helpdesk_tickets as t', 't.id', '=', 'c.ticket_id')
                ->where('t.source_system', 'movidesk')->where('t.origin_company_id', $primary)
                ->whereNull('c.source')->where('c.is_system', false)->whereNull('c.external_action_id')
                ->whereNull('c.deleted_at'))
                ->count();
            $this->line("  Respostas aguardando envio ......... {$pending}");
        }

        $this->line('');
        $this->line('<options=bold>E-mail direto no Minutor (ERPSERV)</>');
        $accs = DB::table('helpdesk_email_accounts')->whereNull('deleted_at')->orderBy('id')
            ->get(['email', 'company_id', 'enabled', 'receive_enabled', 'last_status', 'last_checked_at']);
        foreach ($accs as $a) {
            $this->line(sprintf('  %-34s empresa %s | ligada %s | recebe %s | %s | última leitura %s',
                $a->email, $a->company_id, $yes($a->enabled), $yes($a->receive_enabled), $a->last_status ?: '-', $a->last_checked_at ?: '-'));
        }
        $hasSupport = $accs->contains(fn ($a) => strcasecmp($a->email, 'suporte@erpserv.com.br') === 0 && $a->enabled && $a->receive_enabled);
        $gate = (bool) SystemSetting::get('helpdesk_email_erpserv_only', false);
        $this->line('  Trava "e-mail só p/ cliente ERPSERV" ' . ($gate ? 'LIGADA (clientes reais NÃO recebem e-mail do Minutor)' : 'desligada'));

        $this->line('');
        $this->line('<options=bold>Checklist do go-live</>');
        $this->line('  [' . ($hasSupport ? 'x' : ' ') . '] Caixa suporte@erpserv.com.br cadastrada e lendo no Minutor');
        $this->line('  [' . (!$gate ? 'x' : ' ') . '] Trava helpdesk_email_erpserv_only desligada (clientes passam a receber resposta por e-mail)');
        $this->line('  [ ] Regra no Movidesk: só chamados Promax entram no Movidesk (feito por você, fora do Minutor)');
        $this->line('  [' . ($mode !== 'include' ? 'x' : ' ') . '] Import ignora equipes Promax (só responsável em equipe ERPSERV)');
        $this->line('  [' . ($on ? 'x' : ' ') . '] Resposta no Minutor grava no Movidesk (--ativar)');
        $this->line('  [ ] Teste real: responder no Minutor 1 chamado Promax com equipe ERPSERV e conferir a ação no Movidesk');
    }
}
