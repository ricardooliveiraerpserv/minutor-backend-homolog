<?php

namespace App\Services;

use App\Models\HelpDeskMovideskStatusMap;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\MovideskOrganization;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ENTRADA da integração Help Desk ↔ Movidesk (espelhamento Promax).
 *
 * Traz para o HELP DESK do Minutor os chamados que a Promax abre no Movidesk direcionados às
 * NOSSAS equipes de atendimento (ownerTeam ∈ times configurados), com suas interações, para
 * atendermos pelo Minutor. O status é traduzido pelo de-para (HelpDeskMovideskStatusMap).
 *
 * ⚠️ SOMENTE LEITURA no Movidesk — este serviço NUNCA escreve/interage no Movidesk (não pode
 * chegar ao cliente). A saída (Minutor → Movidesk) é a Fase 2, e não existe aqui.
 */
class MovideskHelpDeskImporter
{
    public function __construct(private MovideskService $movidesk) {}

    // ── Config (SystemSetting) ────────────────────────────────────────────────
    private function enabled(): bool
    {
        return (bool) SystemSetting::get('movidesk_hd_import_enabled', false);
    }

    /** @return string[] Times (ownerTeam) do Movidesk que roteiam para nós. */
    private function teams(): array
    {
        $raw = SystemSetting::get('movidesk_hd_import_teams', ['Promax Bardahl', 'Manutenção Promax']);
        if (is_string($raw)) { $raw = json_decode($raw, true) ?: [$raw]; }
        return array_values(array_filter(array_map('trim', (array) $raw)));
    }

    /**
     * Domínios de e-mail do SOLICITANTE permitidos (teste seguro). Se vazio, não filtra por domínio.
     * Ex.: ['erpserv.com.br'] → só importa chamados abertos por gente da ERPSERV (sem tocar cliente real).
     */
    private function allowedDomains(): array
    {
        $raw = SystemSetting::get('movidesk_hd_import_domains', []);
        if (is_string($raw)) { $raw = json_decode($raw, true) ?: array_filter([trim($raw)]); }
        return array_values(array_filter(array_map(fn ($d) => mb_strtolower(ltrim(trim((string) $d), '@')), (array) $raw)));
    }

    private function domainAllowed(array $md): bool
    {
        $domains = $this->allowedDomains();
        if (!$domains) return true; // sem filtro → tudo passa
        foreach (($md['clients'] ?? []) as $c) {
            $email = mb_strtolower((string) ($this->firstEmail($c) ?? ''));
            $at = strrpos($email, '@');
            if ($at !== false && in_array(substr($email, $at + 1), $domains, true)) return true;
        }
        return false;
    }

    private function companyId(): int
    {
        $c = (int) SystemSetting::get('movidesk_hd_import_company_id', 0);
        if ($c > 0) return $c;
        // fallback: empresa do status HD default
        $def = HelpDeskStatus::query()->where('is_default', true)->orderBy('company_id')->first();
        return (int) ($def->company_id ?? 1);
    }

    /** Cliente Minutor "padrão" para chamados Promax sem org vinculada (id do customer). */
    private function fallbackCustomerId(): ?int
    {
        $c = (int) SystemSetting::get('movidesk_hd_import_customer_id', 0);
        return $c > 0 ? $c : null;
    }

    // ── Entry point ───────────────────────────────────────────────────────────
    /**
     * Importa/atualiza os chamados Promax alterados desde o cursor.
     * @param bool $force ignora o flag de habilitação (para teste manual via command).
     * @return array{scanned:int,imported:int,updated:int,comments:int,skipped:int,since:string}
     */
    public function run(bool $force = false, ?Carbon $sinceOverride = null): array
    {
        $stats = ['scanned' => 0, 'imported' => 0, 'updated' => 0, 'comments' => 0, 'skipped' => 0, 'since' => ''];

        if (!$force && !$this->enabled()) {
            $stats['skipped'] = -1; // desabilitado
            return $stats;
        }

        $teams     = $this->teams();
        $companyId = $this->companyId();
        $since     = $sinceOverride ?: $this->cursor();
        $stats['since'] = $since->toIso8601String();

        $list = $this->movidesk->fetchHelpDeskTicketsSince($since, $teams);
        $maxUpdate = $since;

        foreach ($list as $lite) {
            $stats['scanned']++;
            $externalId = (string) ($lite['id'] ?? '');
            if ($externalId === '') { continue; }

            $base   = (string) ($lite['baseStatus'] ?? '');
            $exists = HelpDeskTicket::where('source_system', 'movidesk')->where('external_ref', $externalId)->exists();

            // Não importar histórico fechado/cancelado que nunca esteve aqui — só o que está vivo
            // ou o que já espelhamos antes (para receber a atualização de fechamento).
            if (!$exists && in_array($base, ['Closed', 'Canceled'], true)) {
                $stats['skipped']++;
                $this->bumpCursor($lite, $maxUpdate);
                continue;
            }

            try {
                $full = $this->movidesk->fetchTicket((int) $externalId);
                if (!$full) { $stats['skipped']++; continue; }
                // Filtro de teste por domínio do solicitante (ex.: erpserv.com.br).
                if (!$this->domainAllowed($full)) { $stats['skipped']++; $this->bumpCursor($lite, $maxUpdate); continue; }
                $res = DB::transaction(fn () => $this->upsert($full, $companyId));
                $stats['imported'] += $res['created'] ? 1 : 0;
                $stats['updated']  += $res['created'] ? 0 : 1;
                $stats['comments'] += $res['comments'];
            } catch (\Throwable $e) {
                $stats['skipped']++;
                Log::error('📥 [MOVIDESK HD] Falha ao importar ticket', ['id' => $externalId, 'error' => $e->getMessage()]);
            }

            $this->bumpCursor($lite, $maxUpdate);
        }

        // Avança o cursor (com folga de 1s para trás para não perder atualizações no mesmo segundo).
        $this->saveCursor($maxUpdate->copy()->subSecond());

        Log::info('📥 [MOVIDESK HD] Import concluído', $stats);
        return $stats;
    }

    /**
     * Importa UM chamado específico do Movidesk (por id), ignorando filtros de equipe/domínio/status.
     * Uso: validação/teste manual. SOMENTE LEITURA no Movidesk.
     * @return array{created:bool,comments:int,ticket_id:int|null,hd_number:string|null}
     */
    public function importOne(int $externalId, ?int $companyId = null): array
    {
        $companyId = $companyId ?: $this->companyId();
        $full = $this->movidesk->fetchTicket($externalId);
        if (!$full) return ['created' => false, 'comments' => 0, 'ticket_id' => null, 'hd_number' => null];

        $res = DB::transaction(fn () => $this->upsert($full, $companyId));
        $hd = HelpDeskTicket::where('source_system', 'movidesk')->where('external_ref', (string) $externalId)->first(['id', 'ticket_number']);
        return ['created' => $res['created'], 'comments' => $res['comments'], 'ticket_id' => $hd?->id, 'hd_number' => $hd?->ticket_number];
    }

    private function bumpCursor(array $lite, Carbon &$max): void
    {
        $lu = $lite['lastUpdate'] ?? null;
        if ($lu) {
            try { $c = Carbon::parse($lu); if ($c->gt($max)) $max = $c; } catch (\Throwable) {}
        }
    }

    // ── Upsert do ticket + comentários ────────────────────────────────────────
    /** @return array{created:bool,comments:int} */
    private function upsert(array $md, int $companyId): array
    {
        $externalId = (string) ($md['id'] ?? '');
        $base       = (string) ($md['baseStatus'] ?? '');
        $statusText = (string) ($md['status'] ?? '');

        $ticket = HelpDeskTicket::where('source_system', 'movidesk')->where('external_ref', $externalId)->first();
        $created = false;

        // Cliente/solicitante a partir do primeiro "client" do Movidesk.
        $client   = $md['clients'][0] ?? null;
        $orgId    = $client['organization']['id'] ?? ($md['clients'][0]['organization']['id'] ?? null);
        $customerId = $this->resolveCustomer($orgId);
        $reqName  = (string) ($client['businessName'] ?? '');
        $reqEmail = $this->firstEmail($client);

        if (!$ticket) {
            $ticket = new HelpDeskTicket();
            $ticket->forceFill([
                'ticket_number' => HelpDeskTicketNumber::next($companyId),
                'source_system' => 'movidesk',
                'external_ref'  => $externalId,
                'channel'       => 'movidesk',
                'company_id'    => $companyId,
            ]);
            $created = true;
        }

        $ticket->subject = mb_substr((string) ($md['subject'] ?: ('Chamado Movidesk #' . $externalId)), 0, 255);
        if ($created) {
            // descrição inicial = primeira ação (mensagem de abertura do cliente), se houver.
            $ticket->description = $this->firstActionBody($md);
        }
        if ($customerId) { $ticket->customer_id = $customerId; }
        if ($reqName)  { $ticket->requester_name = mb_substr($reqName, 0, 255); }
        if ($reqEmail) { $ticket->requester_email = mb_substr($reqEmail, 0, 255); }

        // Status: aplica o de-para SÓ quando o status do Movidesk MUDOU desde o último import
        // (não desfaz o status que o atendente ajustou no Minutor).
        $movideskSig = trim($base . '|' . $statusText);
        if ($created || $ticket->external_status !== $movideskSig) {
            $statusId = HelpDeskMovideskStatusMap::resolveInbound($companyId, $base, $statusText)
                ?? HelpDeskMovideskStatusMap::resolveInbound(null, $base, $statusText)
                ?? optional(HelpDeskStatus::query()->where('company_id', $companyId)->where('is_default', true)->first())->id;
            if ($statusId) { $ticket->status_id = $statusId; }
            $ticket->external_status = $movideskSig;
        }

        $ticket->external_synced_at = now();
        // company_id não é fillable — garante empresa mesmo em update.
        if ((int) $ticket->company_id !== $companyId) { $ticket->forceFill(['company_id' => $companyId]); }
        $ticket->save();

        $comments = $this->importActions($ticket, $md['actions'] ?? []);

        return ['created' => $created, 'comments' => $comments];
    }

    /** Importa as ações do Movidesk como interações do HD (dedup por external_action_id). */
    private function importActions(HelpDeskTicket $ticket, array $actions): int
    {
        if (!$actions) return 0;

        $existing = HelpDeskTicketComment::withTrashed()
            ->where('ticket_id', $ticket->id)
            ->where('source', 'movidesk')
            ->pluck('external_action_id')
            ->filter()->map(fn ($v) => (string) $v)->flip();

        $n = 0;
        foreach ($actions as $a) {
            $aid = (string) ($a['id'] ?? '');
            if ($aid === '' || $existing->has($aid)) continue;

            $body = (string) ($a['htmlDescription'] ?? '');
            if (trim(strip_tags($body)) === '' && empty($a['attachments'])) continue; // ação vazia

            $isPublic = (bool) ($a['isPublic'] ?? true);
            $authorId = $this->resolveAgentUser($a['createdBy']['email'] ?? null);

            HelpDeskTicketComment::create([
                'ticket_id'          => $ticket->id,
                'author_user_id'     => $authorId, // null => interação de origem externa (cliente)
                'body'               => $body,
                'visibility'         => $isPublic ? 'customer' : 'internal',
                'is_system'          => false,
                'channel'            => 'movidesk',
                'source'             => 'movidesk',
                'external_action_id' => $aid,
                'idempotency_key'    => 'movidesk-action-' . $aid,
            ]);
            $existing->put($aid, true);
            $n++;
        }
        return $n;
    }

    // ── Resolução de entidades ────────────────────────────────────────────────
    private function resolveCustomer($orgId): ?int
    {
        if ($orgId) {
            $org = MovideskOrganization::where('movidesk_id', (string) $orgId)->first();
            if ($org && $org->customer_id) return (int) $org->customer_id;
        }
        return $this->fallbackCustomerId();
    }

    private function resolveAgentUser(?string $email): ?int
    {
        if (!$email) return null;
        $u = User::where('email', $email)->first(['id']);
        return $u?->id;
    }

    private function firstEmail($client): ?string
    {
        if (!is_array($client)) return null;
        if (!empty($client['email'])) return (string) $client['email'];
        $emails = $client['emails'] ?? null;
        if (is_array($emails) && isset($emails[0]['email'])) return (string) $emails[0]['email'];
        return null;
    }

    private function firstActionBody(array $md): ?string
    {
        foreach (($md['actions'] ?? []) as $a) {
            $b = (string) ($a['htmlDescription'] ?? '');
            if (trim(strip_tags($b)) !== '') return $b;
        }
        return null;
    }

    // ── Cursor ────────────────────────────────────────────────────────────────
    private function cursor(): Carbon
    {
        $raw = SystemSetting::get('movidesk_hd_import_since', null);
        if ($raw) { try { return Carbon::parse($raw); } catch (\Throwable) {} }
        // 1ª execução: pega os últimos 7 dias (fila viva), não o histórico todo.
        return now()->subDays(7);
    }

    private function saveCursor(Carbon $ts): void
    {
        SystemSetting::set('movidesk_hd_import_since', $ts->toIso8601String(), 'string', 'movidesk', 'Cursor do import HD Movidesk (Promax)');
    }
}
