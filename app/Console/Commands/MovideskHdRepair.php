<?php

namespace App\Console\Commands;

use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Services\MovideskHelpDeskImporter;
use App\Services\MovideskPushPolicy;
use App\Services\MovideskService;
use Illuminate\Console\Command;

/**
 * REPARO dos chamados importados do Movidesk (SÓ LEITURA no Movidesk):
 *  - nota interna que entrou como pública → volta a interna (type=1);
 *  - ações que faltaram (ex.: só print) → importa;
 *  - anexos das ações → baixa (token da conta certa);
 *  - prints (imagens S3 que expiram) → troca pelo link NOVO do Movidesk e re-hospeda no Minutor.
 */
class MovideskHdRepair extends Command
{
    protected $signature = 'movidesk:hd-repair {--company=1} {--since=2026-10-01} {--limit=0} {--sleep=700} {--ticket=} {--before-id=0}';
    protected $description = 'Repara chamados importados do Movidesk (visibilidade, ações faltantes, anexos e prints)';

    public function handle(MovideskService $md, MovideskHelpDeskImporter $imp): int
    {
        $company = (int) $this->option('company');
        $primary = MovideskPushPolicy::primaryCompanyId();
        $md->forCompany($company === $primary ? null : $company);

        $q = HelpDeskTicket::withoutGlobalScopes()->where('source_system', 'movidesk')
            ->where('origin_company_id', $company)->whereNull('deleted_at')
            ->where('updated_at', '>=', $this->option('since'))->orderByDesc('id');
        if ($this->option('ticket')) $q->where('ticket_number', $this->option('ticket'));
        // Retomar após interrupção (deploy reinicia o container): só ids ABAIXO do último processado.
        if ((int) $this->option('before-id') > 0) $q->where('id', '<', (int) $this->option('before-id'));
        if ((int) $this->option('limit') > 0) $q->limit((int) $this->option('limit'));
        $ids = $q->pluck('id');
        $this->info("Empresa $company — " . $ids->count() . ' chamado(s)');

        $st = ['tickets' => 0, 'erro_api' => 0, 'vis_corrigida' => 0, 'acoes_novas' => 0, 'com_anexo' => 0, 'com_print' => 0];
        foreach ($ids as $i => $tid) {
            $t = HelpDeskTicket::withoutGlobalScopes()->find($tid);
            $extId = (int) preg_replace('/\D/', '', (string) $t->external_ref);
            $full = $extId ? $md->fetchTicket($extId) : null;
            usleep(((int) $this->option('sleep')) * 1000);
            if (!$full) { $st['erro_api']++; continue; }
            $st['tickets']++;
            $byAid = HelpDeskTicketComment::where('ticket_id', $t->id)->whereNotNull('external_action_id')->get()->keyBy(fn ($c) => (string) $c->external_action_id);
            foreach ($full['actions'] ?? [] as $a) {
                $aid = (string) ($a['id'] ?? '');
                $c = $byAid->get($aid);
                if (!$c) continue;
                $want = MovideskHelpDeskImporter::actionIsPublic($a) ? 'customer' : 'internal';
                $dirty = false;
                if ($c->visibility !== $want && $c->source === 'movidesk') { $c->visibility = $want; $dirty = true; $st['vis_corrigida']++; }
                // Print: o corpo guardado tem link S3 que EXPIRA → usa o corpo NOVO do Movidesk (links válidos agora).
                $fresh = (string) ($a['htmlDescription'] ?? '');
                if ($c->source === 'movidesk' && preg_match('~s3[.-][^/]*amazonaws\.com/[^?"\']*movidesk~i', (string) $c->body) && $fresh !== '') {
                    $c->body = $fresh; $dirty = true; $st['com_print']++;
                }
                if ($dirty) $c->saveQuietly();
                if (!empty($a['attachments'])) $st['com_anexo']++;
                $imp->queueRepair($c, $t, $a['attachments'] ?? []);
            }
            $st['acoes_novas'] += $imp->importActions($t, $full['actions'] ?? [], $imp->openingActionId($full));
            // Prints da DESCRIÇÃO (abertura): HTML novo da ação de abertura + re-hospedagem.
            try {
                $openId = $imp->openingActionId($full);
                $open = collect($full['actions'] ?? [])->first(fn ($x) => (string) ($x['id'] ?? '') === (string) $openId);
                if ($imp->rehostDescriptionImages($t, $open['htmlDescription'] ?? null)) $st['descricao_print'] = ($st['descricao_print'] ?? 0) + 1;
            } catch (\Throwable) {}
            $imp->rehostPendingImages();
            if (($i + 1) % 25 === 0) $this->line(now()->format('H:i:s') . ' ' . ($i + 1) . '/' . $ids->count() . ' id<' . $tid . ' ' . json_encode($st));
        }
        $this->info('FIM ' . json_encode($st));
        return self::SUCCESS;
    }
}
