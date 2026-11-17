<?php

namespace App\Console\Commands;

use App\Models\HelpDeskTicket;
use App\Services\MovideskHelpDeskImporter;
use App\Services\MovideskPushPolicy;
use App\Services\MovideskService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Corrige chamados espelhados do Movidesk que ficaram parados em espera/resolvido mesmo com o
 * CLIENTE tendo respondido (a importação não aplicava a reativação). Lê o Movidesk (só leitura):
 * reativa quando a ÚLTIMA ação pública é de um CLIENTE (profileType 2) dentro de --days e não houve
 * mudança de status no Minutor depois dela. Dry-run por padrão.
 */
class MovideskReactivateReplies extends Command
{
    protected $signature = 'movidesk:reactivate-replies {--company=1} {--days=15} {--ticket=} {--sleep=600} {--execute}';
    protected $description = 'Reativa chamados (Movidesk) cuja última ação pública é resposta do cliente';

    private const KEYS = ['aguardando_cliente', 'resolvido', 'solucao_gmud', 'pendente_terceiros', 'em_homologacao', 'em_desenvolvimento', 'planejamento_gmud', 'reuniao_agendada'];

    public function handle(MovideskService $md, MovideskHelpDeskImporter $imp): int
    {
        $company = (int) $this->option('company');
        $md->forCompany($company === MovideskPushPolicy::primaryCompanyId() ? null : $company);
        $q = HelpDeskTicket::withoutGlobalScopes()->whereNull('deleted_at')->where('source_system', 'movidesk')
            ->where('company_id', $company)
            ->whereHas('status', fn ($s) => $s->withoutGlobalScopes()->whereIn('key', self::KEYS));
        if ($this->option('ticket')) $q->where('ticket_number', $this->option('ticket'));
        $since = now()->subDays((int) $this->option('days'));
        $hits = 0; $done = 0;
        foreach ($q->get() as $t) {
            $ext = (int) preg_replace('/\D/', '', (string) $t->external_ref);
            $full = $ext ? $md->fetchTicket($ext) : null;
            usleep((int) $this->option('sleep') * 1000);
            if (!$full) continue;
            $pub = collect($full['actions'] ?? [])->filter(fn ($a) => MovideskHelpDeskImporter::actionIsPublic($a) && !empty($a['createdDate']))
                ->sortBy(fn ($a) => $a['createdDate'])->values();
            $last = $pub->last();
            if (!$last || (int) ($last['createdBy']['profileType'] ?? 0) !== 2) continue;
            $at = Carbon::parse($last['createdDate']);
            if ($at->lt($since)) continue;
            $hits++;
            $st = optional($t->status()->withoutGlobalScopes()->first())->key;
            $this->line("{$t->ticket_number} [{$st}] resposta do cliente em {$at->format('d/m H:i')} — " . ($last['createdBy']['businessName'] ?? '?'));
            if ($this->option('execute') && $imp->reactivateOnCustomerReply($t, $at, true)) $done++;
        }
        $this->info(($this->option('execute') ? 'REATIVADOS' : 'DRY-RUN') . " — candidatos: {$hits}, reativados: {$done}");
        return self::SUCCESS;
    }
}
