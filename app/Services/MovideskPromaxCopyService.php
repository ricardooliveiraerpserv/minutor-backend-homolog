<?php

namespace App\Services;

use App\Models\HelpDeskMovideskStatusMap;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\HelpDeskTicketEvent;
use App\Models\MovideskOrganization;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * CÓPIA NO MOVIDESK dos chamados PROMAX abertos no Minutor (e-mail suporte@) — decisão Ricardo 14/11/2026.
 *
 * Após o go-live o suporte@ entra direto no Minutor. Para a Promax continuar enxergando tudo no Movidesk,
 * todo chamado do cliente Promax (domínio promax.com.br / promax.bardahl.com.br → cliente 269 pelas Regras
 * de Associação) ganha uma CÓPIA no Movidesk (conta ERPSERV), de MÃO ÚNICA Minutor → Movidesk:
 *   - abertura: solicitante (pessoa do Movidesk; criada na org Promax se não existir), dono "Suporte ERPSERV",
 *     tag "minutor" e campo "Envia E-mail?" = Não (os gatilhos de e-mail ao cliente do Movidesk checam esse
 *     campo → o Movidesk NÃO manda e-mail; criação via API também não casa com "aberto via e-mail/agente");
 *   - toda interação (resposta do agente E resposta do cliente por e-mail) vira ação no Movidesk;
 *   - status segue o de-para outbound (helpdesk_movidesk_status_map) + justificativa global.
 * Os workflows/e-mails ao cliente continuam SEMPRE do Minutor (o chamado não é source_system=movidesk,
 * então MovideskPushPolicy::deliversViaMovidesk = false). O import ignora o id da cópia (não volta duplicado).
 *
 * Travas: `movidesk_promax_copy_enabled` (padrão DESLIGADO) + `movidesk_promax_copy_since` (corte; vazio = nada).
 * Só conta Movidesk da ERPSERV (empresa principal); BIZIFY nunca.
 */
class MovideskPromaxCopyService
{
    public function __construct(private MovideskService $movidesk, private MovideskHelpDeskImporter $importer) {}

    public static function enabled(): bool
    {
        return (bool) SystemSetting::get('movidesk_promax_copy_enabled', false) && self::since() !== null;
    }

    public static function since(): ?Carbon
    {
        $v = trim((string) SystemSetting::get('movidesk_promax_copy_since', ''));
        if ($v === '') return null;
        try { return Carbon::parse($v); } catch (\Throwable) { return null; }
    }

    /** @return int[] clientes do Minutor tratados como Promax. */
    public static function customerIds(): array
    {
        $raw = SystemSetting::get('movidesk_promax_copy_customer_ids', [269]);
        if (is_string($raw)) { $raw = json_decode($raw, true) ?: array_filter(explode(',', $raw)); }
        return array_values(array_filter(array_map('intval', (array) $raw)));
    }

    /** @return string[] canais que geram cópia (padrão: só e-mail = suporte@). */
    public static function channels(): array
    {
        $raw = SystemSetting::get('movidesk_promax_copy_channels', ['email']);
        if (is_string($raw)) { $raw = json_decode($raw, true) ?: array_filter(explode(',', $raw)); }
        return array_values(array_filter(array_map(fn ($c) => trim((string) $c), (array) $raw)));
    }

    /** Chamados que precisam de cópia (ainda sem movidesk_copy_id). */
    public function candidatesQuery()
    {
        $since = self::since() ?: now()->addYears(100);
        return HelpDeskTicket::query()
            ->whereNull('movidesk_copy_id')
            ->where(fn ($q) => $q->whereNull('source_system')->orWhere('source_system', '!=', 'movidesk'))
            ->where('company_id', MovideskPushPolicy::primaryCompanyId())
            ->whereIn('customer_id', self::customerIds() ?: [0])
            ->whereIn('channel', self::channels() ?: ['-'])
            ->whereNull('merged_into_id')
            ->where('created_at', '>=', $since);
    }

    /**
     * Uma passada: cria cópias pendentes, empurra interações e status das cópias existentes.
     * @param bool $dry só conta/lista (não escreve no Movidesk).
     */
    public function run(bool $dry = false, ?int $onlyTicketId = null, bool $force = false): array
    {
        $stats = ['created' => 0, 'comments' => 0, 'statuses' => 0, 'errors' => 0, 'candidates' => []];
        if (!$force && !self::enabled()) { $stats['skipped'] = 'desligado'; return $stats; }
        $this->movidesk->forCompany(null); // conta principal (ERPSERV) — token global

        $cands = $this->candidatesQuery()->when($onlyTicketId, fn ($q) => $q->where('id', $onlyTicketId))
            ->orderBy('id')->limit(50)->get();
        foreach ($cands as $t) {
            $stats['candidates'][] = "{$t->ticket_number} · {$t->requester_email} · {$t->subject}";
            if ($dry) continue;
            try {
                if ($this->createCopy($t)) { $stats['created']++; } else { $stats['errors']++; }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('📤 [MOVIDESK COPIA] criação falhou', ['ticket_id' => $t->id, 'error' => $e->getMessage()]);
            }
        }
        if ($dry) return $stats;

        // VOLTA (Movidesk → Minutor): o que a Promax fizer na cópia (ações, anexos, status).
        try { $stats['pulled'] = $this->pullChanged($onlyTicketId); }
        catch (\Throwable $e) { $stats['errors']++; Log::error('📥 [MOVIDESK COPIA] volta falhou', ['error' => $e->getMessage()]); }

        $copies = HelpDeskTicket::query()->whereNotNull('movidesk_copy_id')
            ->when($onlyTicketId, fn ($q) => $q->where('id', $onlyTicketId))
            ->where('updated_at', '>=', now()->subDays(60))
            ->get();
        foreach ($copies as $t) {
            try {
                $stats['comments'] += $this->pushComments($t);
                $stats['statuses'] += $this->pushStatus($t) ? 1 : 0;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('📤 [MOVIDESK COPIA] sync falhou', ['ticket_id' => $t->id, 'error' => $e->getMessage()]);
            }
        }
        if ($stats['created'] || $stats['comments'] || $stats['statuses']) {
            Log::info('📤 [MOVIDESK COPIA] passada', array_diff_key($stats, ['candidates' => 1]));
        }
        return $stats;
    }

    private function createCopy(HelpDeskTicket $t): bool
    {
        $email = mb_strtolower(trim((string) $t->requester_email));
        if ($email === '') { Log::warning('📤 [MOVIDESK COPIA] sem e-mail do solicitante', ['ticket_id' => $t->id]); return false; }

        $org = MovideskOrganization::where('hd_customer_id', $t->customer_id)
            ->where('company_id', MovideskPushPolicy::primaryCompanyId())->first();
        $personId = $this->movidesk->findPersonIdByEmail($email)
            ?: $this->movidesk->createPerson((string) $t->requester_name, $email, $org?->movidesk_id);
        if (!$personId) { Log::warning('📤 [MOVIDESK COPIA] sem pessoa no Movidesk', ['ticket_id' => $t->id, 'email' => $email]); return false; }

        $body = $this->importer->htmlToText((string) $t->description);
        if ($body === '') $body = htmlspecialchars((string) $t->subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $payload = [
            'type' => 2,
            'subject' => mb_substr((string) $t->subject, 0, 255),
            'createdBy' => ['id' => $personId],
            'clients' => [['id' => $personId]],
            'tags' => [SystemSetting::get('movidesk_promax_copy_tag', 'minutor')],
            'actions' => [['type' => 2, 'origin' => 3, 'description' => $body, 'createdBy' => ['id' => $personId]]],
        ];
        // "Envia E-mail?" = Não → os gatilhos de e-mail ao cliente do Movidesk (nova ação, resolvido,
        // cancelado) já checam esse campo e NÃO disparam: o e-mail ao cliente é sempre do Minutor.
        // A regra de exibição do "Envia E-mail?" (123791) exige "Fonte atualizado no VCS?" (55719, regra 26925)
        // = NÃO — por isso vão os dois. Configurável: movidesk_promax_copy_custom_fields (JSON [[id,rule,item],...]).
        $cf = SystemSetting::get('movidesk_promax_copy_custom_fields', null);
        if (is_string($cf)) { $cf = json_decode($cf, true); }
        if (!is_array($cf)) { $cf = [[55719, 26925, 'NÃO'], [235235, 123791, 'Não']]; }
        $vals = [];
        foreach ($cf as $c) {
            if (count($c) < 3 || !(int) $c[0]) continue;
            $vals[] = ['customFieldId' => (int) $c[0], 'customFieldRuleId' => (int) $c[1], 'line' => 1, 'items' => [['customFieldItem' => (string) $c[2]]]];
        }
        if ($vals) { $payload['customFieldValues'] = $vals; }
        $ownerId = $this->ownerId();
        if ($ownerId) { $payload['owner'] = ['id' => $ownerId]; }
        $team = trim((string) SystemSetting::get('movidesk_promax_copy_owner_team', ''));
        if ($team !== '') { $payload['ownerTeam'] = $team; }

        $id = $this->movidesk->createTicket($payload);
        if (!$id) return false;

        // Anexos da abertura sobem na ação 1 (a abertura).
        try { app(MovideskAttachmentSync::class)->push('HELPDESK_TICKET', (int) $t->id, (int) $id, 1); }
        catch (\Throwable $e) { Log::warning('📤 [MOVIDESK COPIA] anexos da abertura falharam', ['ticket_id' => $t->id, 'e' => $e->getMessage()]); }
        $t->movidesk_copy_id = $id;
        $t->movidesk_copy_at = now();
        $t->movidesk_copy_status = 'New|Novo'; // nasce "Novo" no Movidesk; pushStatus alinha ao Minutor
        $t->saveQuietly();
        try {
            HelpDeskTicketEvent::log($t->id, 'movidesk_copy', ['to_value' => $id, 'meta' => ['via' => 'promax_copy']]);
        } catch (\Throwable) {}
        return true;
    }

    /** Dono padrão da cópia: agente genérico "Suporte ERPSERV" (id em cache no SystemSetting). */
    private function ownerId(): ?string
    {
        $id = trim((string) SystemSetting::get('movidesk_promax_copy_owner_id', ''));
        if ($id !== '') return $id;
        $name = (string) SystemSetting::get('movidesk_promax_copy_owner_name', 'Suporte ERPSERV');
        $id = $this->movidesk->findPersonIdByName($name);
        if ($id) SystemSetting::set('movidesk_promax_copy_owner_id', $id, 'string', 'movidesk', 'Cópia Promax: id do agente dono no Movidesk');
        return $id;
    }

    /** Empurra as interações pendentes (agente e cliente) como ações, na ordem. */
    private function pushComments(HelpDeskTicket $t): int
    {
        $since = self::since();
        $q = HelpDeskTicketComment::query()->where('ticket_id', $t->id)
            ->whereNull('source')->where('is_system', false)->whereNull('external_action_id')
            ->where('created_at', '>=', $since ?: now()->addYears(100));
        if (!MovideskPushPolicy::pushInternal()) $q->where('visibility', 'customer');
        $pending = $q->orderBy('id')->get(['id', 'body', 'visibility', 'author_user_id', 'author_contact_id']);
        if ($pending->isEmpty()) return 0;

        $ext = (int) $t->movidesk_copy_id;
        $full = $this->movidesk->fetchTicket($ext);
        if (!$full) return 0;
        $this->ensureTag($ext, $full);
        $maxId = 0;
        foreach ($full['actions'] ?? [] as $a) { $maxId = max($maxId, (int) ($a['id'] ?? 0)); }
        $requesterId = null;
        foreach ($full['clients'] ?? [] as $c) { if (!empty($c['id'])) { $requesterId = (string) $c['id']; break; } }

        $n = 0;
        foreach ($pending as $c) {
            $text = $this->importer->htmlToText((string) $c->body);
            if ($text === '') { $c->external_action_id = '0'; $c->saveQuietly(); continue; }
            $type = $c->visibility === 'customer' ? 2 : 1;
            $action = ['type' => $type, 'description' => $text];
            $author = $this->importer->authorMovideskId($c->author_user_id)
                ?: (!$c->author_user_id ? $requesterId : null); // resposta do cliente por e-mail sem usuário
            if ($author) { $action['createdBy'] = ['id' => (string) $author]; }
            if (!$this->movidesk->addActions($ext, [$action])) break; // tenta de novo na próxima passada
            $real = $this->importer->locateCreatedActionId($ext, $maxId, $type, $text);
            $maxId = max($maxId + 1, (int) $real);
            $c->external_action_id = (string) ($real ?: $maxId);
            $c->saveQuietly();
            try { app(MovideskAttachmentSync::class)->push('HELPDESK_TICKET_COMMENT', (int) $c->id, $ext, (int) $c->external_action_id); }
            catch (\Throwable $e) { Log::warning('📤 [MOVIDESK COPIA] anexos da interação falharam', ['comment' => $c->id, 'e' => $e->getMessage()]); }
            $n++;
        }
        return $n;
    }

    /** Status do Minutor equivalente a uma assinatura "base|texto" do Movidesk (de-para inbound). */
    private function inboundStatusId(HelpDeskTicket $t, string $sig): ?int
    {
        if ($sig === '' || !str_contains($sig, '|')) return null;
        [$base, $text] = explode('|', $sig, 2);
        $id = HelpDeskMovideskStatusMap::resolveInbound((int) $t->company_id, $base, $text)
            ?? HelpDeskMovideskStatusMap::resolveInbound(null, $base, $text);
        return $id ? (int) $id : null;
    }

    /**
     * VOLTA: lista (1 chamada) as cópias alteradas no Movidesk desde o cursor e sincroniza cada uma:
     * ações novas (com anexos) viram interações; status muda no Minutor se SÓ o Movidesk mudou.
     */
    private function pullChanged(?int $onlyTicketId = null): int
    {
        $cursorKey = 'movidesk_promax_copy_pull_cursor';
        $since = $onlyTicketId ? now()->subDays(30)
            : (($c = SystemSetting::get($cursorKey)) ? Carbon::parse($c) : (self::since() ?? now()));
        $ids = $this->movidesk->listTicketIdsUpdatedSince($since, (string) SystemSetting::get('movidesk_promax_copy_tag', 'minutor'));
        if ($ids === null) return 0; // falha na API → mantém o cursor
        $max = $since->copy();
        $n = 0;
        foreach ($ids as $row) {
            $lu = !empty($row['lastUpdate']) ? Carbon::parse($row['lastUpdate']) : null;
            if ($lu && $lu->gt($max)) $max = $lu;
            $t = HelpDeskTicket::where('movidesk_copy_id', (string) $row['id'])
                ->when($onlyTicketId, fn ($q) => $q->where('id', $onlyTicketId))->first();
            if (!$t) continue;
            $full = $this->movidesk->fetchTicket((int) $t->movidesk_copy_id);
            if (!$full) continue;
            // Ações novas (dedup por external_action_id; a 1 é a abertura = nossa descrição).
            $before = HelpDeskTicketComment::withTrashed()->where('ticket_id', $t->id)->max('id') ?? 0;
            $n += $this->importer->importActions($t, $full['actions'] ?? [], '1');
            $this->importer->rehostPendingImages();
            // Avisos PELO MINUTOR (o Movidesk está silenciado p/ cópias): resposta de agente → e-mail ao
            // cliente; resposta do cliente (portal Movidesk) → gatilho que avisa o responsável.
            $profileByAction = [];
            foreach ($full['actions'] ?? [] as $a) { $profileByAction[(string) ($a['id'] ?? '')] = (int) ($a['createdBy']['profileType'] ?? 0); }
            $new = HelpDeskTicketComment::where('ticket_id', $t->id)->where('id', '>', $before)->where('source', 'movidesk')->get();
            foreach ($new as $c) {
                $isClient = ($profileByAction[(string) $c->external_action_id] ?? 0) === 2;
                try {
                    if (!$isClient && $c->visibility === 'customer') {
                        \App\Jobs\SendHelpDeskEmailJob::dispatch($t->id, $c->id)->onConnection(config('queue.helpdesk_email_connection'))->onQueue('emails');
                    }
                    \App\Services\HelpDeskTriggerEngine::queue('comment_added', $t->fresh(), ['comment_by' => $isClient ? 'client' : 'agent', 'visibility' => $c->visibility, 'via' => 'movidesk_copia']);
                } catch (\Throwable $e) {
                    Log::warning('📥 [MOVIDESK COPIA] aviso da interação falhou', ['comment' => $c->id, 'e' => $e->getMessage()]);
                }
            }
            // Status: 3 vias (sombra = movidesk_copy_status).
            $mdSig = trim((string) ($full['baseStatus'] ?? '') . '|' . (string) ($full['status'] ?? ''));
            $shadow = (string) $t->movidesk_copy_status;
            if ($mdSig !== '|' && $mdSig !== $shadow) {
                $minutorUnchanged = $this->inboundStatusId($t, $shadow) === (int) $t->status_id || $shadow === '';
                if ($minutorUnchanged) {
                    $sid = $this->inboundStatusId($t, $mdSig);
                    if ($sid && $sid !== (int) $t->status_id) {
                        $t->status_id = $sid; $t->save();
                        try { \App\Services\HelpDeskTriggerEngine::queue('status_changed', $t->fresh(), ['via' => 'movidesk_copia']); } catch (\Throwable) {}
                    }
                    HelpDeskTicket::where('id', $t->id)->update(['movidesk_copy_status' => $mdSig]);
                }
                // Minutor também mudou → Minutor vence (a passada de ida empurra).
            }
        }
        if (!$onlyTicketId) SystemSetting::set($cursorKey, $max->copy()->subSecond()->toIso8601String(), 'string', 'movidesk', 'Cópia Promax: cursor da volta');
        return $n;
    }

    /**
     * Garante a tag "minutor" na cópia ANTES de escrever: as automações do Movidesk filtram por ela (não
     * mandam e-mail), e há gatilhos que SUBSTITUEM todas as tags (ex.: "Definir TAGs") e a apagariam.
     */
    private function ensureTag(int $ext, array $full): void
    {
        $tag = (string) SystemSetting::get('movidesk_promax_copy_tag', 'minutor');
        $tags = array_values(array_filter((array) ($full['tags'] ?? [])));
        if (in_array($tag, $tags, true)) return;
        $tags[] = $tag;
        $this->movidesk->patchTicket($ext, ['tags' => $tags]);
    }

    /** Status do Minutor → Movidesk (de-para outbound + justificativa global). */
    private function pushStatus(HelpDeskTicket $t): bool
    {
        if (!$t->status_id) return false;
        $just = trim((string) SystemSetting::get('movidesk_hd_status_justification', ''));
        $map = HelpDeskMovideskStatusMap::query()->where('is_outbound_default', true)
            ->where('helpdesk_status_id', $t->status_id)->where('company_id', $t->company_id)
            ->first(['movidesk_base_status', 'movidesk_status_text']);
        if (!$map || trim((string) $map->movidesk_status_text) === '') return false;
        $sig = trim(($map->movidesk_base_status ?? '') . '|' . $map->movidesk_status_text);
        if ($sig === (string) $t->movidesk_copy_status) return false;
        // Anti-vaivém: se o status atual do Minutor É o equivalente da sombra (veio do Movidesk), não empurra.
        if ($this->inboundStatusId($t, (string) $t->movidesk_copy_status) === (int) $t->status_id) return false;

        $patch = ['status' => $map->movidesk_status_text];
        if (!empty($map->movidesk_base_status)) $patch['baseStatus'] = $map->movidesk_base_status;
        if ($just !== '') $patch['justification'] = $just;
        $full = $this->movidesk->fetchTicket((int) $t->movidesk_copy_id);
        if ($full) $this->ensureTag((int) $t->movidesk_copy_id, $full);
        if (!$this->movidesk->patchTicket((int) $t->movidesk_copy_id, $patch)) return false;
        HelpDeskTicket::where('id', $t->id)->update(['movidesk_copy_status' => $sig]); // sem tocar updated_at do modelo
        return true;
    }
}
