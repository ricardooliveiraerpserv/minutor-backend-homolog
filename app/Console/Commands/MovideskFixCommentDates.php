<?php

namespace App\Console\Commands;

use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Services\MovideskHelpDeskImporter;
use App\Services\MovideskPushPolicy;
use App\Services\MovideskService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Corrige interações importadas do Movidesk que ficaram com a DATA DA IMPORTAÇÃO (aparecem no fim da
 * conversa como se fossem novas) e remove (soft-delete) a ABERTURA duplicada — só quando o texto é
 * igual ao da descrição do chamado. Lê o Movidesk (só leitura). Dry-run por padrão.
 */
class MovideskFixCommentDates extends Command
{
    protected $signature = 'movidesk:fix-comment-dates {--company=1} {--since=2026-11-14} {--ticket=} {--before-id=0} {--sleep=400} {--authors : também preenche o AUTOR das interações sem autor (chamados dos últimos 60 dias)} {--execute}';
    protected $description = 'Acerta a data das interações importadas do Movidesk e remove a abertura duplicada';

    private static function norm(?string $h): string
    {
        $t = html_entity_decode(strip_tags((string) $h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr((string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($t)), 0, 160);
    }

    public function handle(MovideskService $md, MovideskHelpDeskImporter $imp): int
    {
        $company = (int) $this->option('company');
        $md->forCompany($company === MovideskPushPolicy::primaryCompanyId() ? null : $company);
        $since = $this->option('since');
        $ids = HelpDeskTicket::withoutGlobalScopes()->where('source_system', 'movidesk')->where('origin_company_id', $company)
            ->whereNull('deleted_at')
            ->when(!$this->option('authors'), fn ($q) => $q->whereIn('id', HelpDeskTicketComment::where('source', 'movidesk')->where('created_at', '>=', $since)->select('ticket_id')))
            ->when($this->option('authors'), fn ($q) => $q->where('updated_at', '>=', now()->subDays(60))
                ->whereIn('id', HelpDeskTicketComment::where('source', 'movidesk')->whereNull('author_user_id')->whereNull('author_contact_id')->select('ticket_id')))
            ->when($this->option('ticket'), fn ($q) => $q->where('ticket_number', $this->option('ticket')))
            ->when((int) $this->option('before-id') > 0, fn ($q) => $q->where('id', '<', (int) $this->option('before-id')))
            ->orderByDesc('id')->pluck('id');
        $this->info("Empresa {$company} — {$ids->count()} chamado(s)");
        $st = ['tickets' => 0, 'erro_api' => 0, 'datas' => 0, 'aberturas_removidas' => 0];
        foreach ($ids as $i => $tid) {
            $t = HelpDeskTicket::withoutGlobalScopes()->find($tid);
            $ext = (int) preg_replace('/\D/', '', (string) $t->external_ref);
            $full = $ext ? $md->fetchTicket($ext) : null;
            usleep((int) $this->option('sleep') * 1000);
            if (!$full) { $st['erro_api']++; continue; }
            $st['tickets']++;
            $dates = []; $acts = [];
            foreach ($full['actions'] ?? [] as $a) {
                if (!empty($a['id'])) $acts[(string) $a['id']] = $a;
                if (!empty($a['id']) && !empty($a['createdDate'])) $dates[(string) $a['id']] = Carbon::parse($a['createdDate'], 'UTC');
            }
            if ($this->option('execute')) { $st['status_hist'] = ($st['status_hist'] ?? 0) + $imp->syncStatusHistory($t, $full); }
            $openId = $imp->openingActionId($full);
            $desc = self::norm($t->description);
            foreach (HelpDeskTicketComment::where('ticket_id', $t->id)->where('source', 'movidesk')->whereNotNull('external_action_id')->get() as $c) {
                $aid = (string) $c->external_action_id;
                // Abertura duplicada (mesmo texto da descrição) → remove (reversível).
                if ($openId !== null && $aid === (string) $openId && $desc !== '' && self::norm($c->body) === $desc) {
                    $st['aberturas_removidas']++;
                    if ($this->option('execute')) $c->delete();
                    continue;
                }
                // Autor: interação sem autor ("Sistema") cujo autor no Movidesk é agente/cliente conhecido.
                if (!$c->author_user_id && !$c->author_contact_id && isset($acts[$aid])) {
                    $uid = $imp->resolveActionAuthor($acts[$aid]);
                    if ($uid) { $st['autores'] = ($st['autores'] ?? 0) + 1; if ($this->option('execute')) HelpDeskTicketComment::whereKey($c->id)->toBase()->update(['author_user_id' => $uid]); }
                }
                $real = $dates[$aid] ?? null;
                if ($real && abs($real->diffInSeconds(Carbon::parse($c->created_at), false)) > 120) {
                    $st['datas']++;
                    if ($this->option('execute')) HelpDeskTicketComment::whereKey($c->id)->toBase()->update(['created_at' => $real, 'updated_at' => $real]);
                }
            }
            if ($i % 50 === 0) $this->line("… id<{$tid} " . json_encode($st));
        }
        $this->info(($this->option('execute') ? 'GRAVADO ' : 'DRY-RUN ') . json_encode($st));
        return self::SUCCESS;
    }
}
