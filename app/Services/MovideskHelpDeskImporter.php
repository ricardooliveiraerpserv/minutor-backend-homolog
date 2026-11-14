<?php

namespace App\Services;

use App\Models\HelpDeskMovideskStatusMap;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\MovideskAgent;
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

    /** @return string[] Times (ownerTeam) da PROMAX a EXCLUIR: enquanto o chamado estiver nessas
     *  equipes (Promax atendendo internamente), NÃO espelhamos nem interagimos. */
    private function blockedTeams(): array
    {
        $raw = SystemSetting::get('movidesk_hd_blocked_teams', ['Promax Bardahl', 'Manutenção Promax']);
        if (is_string($raw)) { $raw = json_decode($raw, true) ?: [$raw]; }
        return array_values(array_filter(array_map('trim', (array) $raw)));
    }

    /** Modo do filtro de equipe (SystemSetting 'movidesk_hd_team_filter_mode'):
     *   - 'exclude' (PADRÃO, hoje): espelha TUDO menos as equipes da lista (exclui Promax = traz o
     *     legado ERPServ pro Minutor).
     *   - 'include' (FUTURO): espelha SÓ as equipes da lista (ex.: só Promax) — para quando o Movidesk
     *     for desligado p/ ERPServ (que passa a abrir nativo no Minutor) e sobrar só a Promax no Movidesk.
     *  A virada é só mudar este setting — sem redeploy. */
    private function teamFilterMode(): string
    {
        return SystemSetting::get('movidesk_hd_team_filter_mode', 'exclude') === 'include' ? 'include' : 'exclude';
    }

    /**
     * O filtro de equipe Promax só vale para a conta Movidesk PRINCIPAL (ERPSERV), onde a Promax
     * tem equipes próprias atendendo. Nas demais contas (ex.: BIZIFY = 2) a Promax é CLIENTE comum
     * → importa como qualquer cliente, sem filtro de equipe. (Decisão Ricardo 2026-11-09.)
     */
    private function teamFilterApplies(?int $companyId): bool
    {
        return !$companyId || $companyId === $this->companyId();
    }

    /** Deve PULAR (não espelhar/interagir) este chamado pela equipe, respeitando o modo:
     *  exclude → pula se a equipe ESTÁ na lista; include → pula se NÃO está (mantém só as da lista). */
    private function shouldSkipTeam(?string $ownerTeam, ?int $companyId = null): bool
    {
        if (!$this->teamFilterApplies($companyId)) { return false; }
        $t = mb_strtolower(trim((string) $ownerTeam));
        $inList = $t !== '' && in_array($t, array_map(fn ($b) => mb_strtolower(trim($b)), $this->blockedTeams()), true);
        return $this->teamFilterMode() === 'include' ? !$inList : $inList;
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

    /** Se true (padrão), o import só processa chamados de organizações VINCULADAS no HD (hd_customer_id). */
    private function requireLinkedOrg(): bool
    {
        return (bool) SystemSetting::get('movidesk_hd_import_require_linked_org', true);
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
    public function run(bool $force = false, ?Carbon $sinceOverride = null, ?int $companyIdOverride = null): array
    {
        $stats = ['scanned' => 0, 'imported' => 0, 'updated' => 0, 'comments' => 0, 'skipped' => 0, 'since' => ''];

        if (!$force && !$this->enabled()) {
            $stats['skipped'] = -1; // desabilitado
            return $stats;
        }

        $companyId = $companyIdOverride ?: $this->companyId();
        $primary   = $this->teamFilterApplies($companyId);
        // Conta secundária SEM token próprio → o MovideskService cairia no token global (ERPSERV) e
        // gravaria chamados da ERPSERV como se fossem desta empresa. Aborta.
        if (!$primary && empty(config("services.movidesk.tokens.{$companyId}"))) {
            Log::warning('📥 [MOVIDESK HD] Empresa sem token Movidesk próprio — import ignorado', ['company_id' => $companyId]);
            $stats['skipped'] = -2;
            return $stats;
        }
        // Lista de equipes (exclude: excluir; include: manter só estas) — SÓ na conta principal.
        $teams     = $primary ? $this->blockedTeams() : [];
        $mode      = $primary ? $this->teamFilterMode() : 'exclude';
        // Multi-empresa: fixa o token Movidesk da empresa alvo p/ todas as chamadas desta varredura.
        $this->movidesk->forCompany($companyId);
        $since     = $sinceOverride ?: $this->cursor($companyId);
        $stats['since'] = $since->toIso8601String();

        $list = $this->movidesk->fetchHelpDeskTicketsSince($since, $teams, $mode);
        $maxUpdate = $since;

        foreach ($list as $lite) {
            $stats['scanned']++;
            $externalId = (string) ($lite['id'] ?? '');
            if ($externalId === '') { continue; }

            // CÓPIA Promax criada PELO Minutor (MovideskPromaxCopyService): o original é o chamado do
            // Minutor — não importa de volta (senão duplica). Fluxo de mão única Minutor → Movidesk.
            if ($primary && HelpDeskTicket::where('movidesk_copy_id', $externalId)->exists()) {
                $stats['skipped']++; $this->bumpCursor($lite, $maxUpdate); continue;
            }

            $base   = (string) ($lite['baseStatus'] ?? '');
            $exists = HelpDeskTicket::where('source_system', 'movidesk')->where('external_ref', $externalId)->where('origin_company_id', $companyId)->exists();

            // Não importar histórico fechado/cancelado que nunca esteve aqui — só o que está vivo
            // ou o que já espelhamos antes (para receber a atualização de fechamento).
            // Contas SECUNDÁRIAS (ex.: BIZIFY) espelham 100% — inclusive o histórico fechado.
            if ($primary && !$exists && in_array($base, ['Closed', 'Canceled'], true)) {
                $stats['skipped']++;
                $this->bumpCursor($lite, $maxUpdate);
                continue;
            }

            try {
                $full = $this->movidesk->fetchTicket((int) $externalId);
                if (!$full) { $stats['skipped']++; continue; }
                // Filtro de teste por domínio do solicitante (ex.: erpserv.com.br).
                if (!$this->domainAllowed($full)) { $stats['skipped']++; $this->bumpCursor($lite, $maxUpdate); continue; }
                // Só age para ORGS VINCULADAS no Help Desk (hd_customer_id). Org sem vínculo → ignora
                // APENAS tickets NOVOS. Chamados JÁ espelhados (ex.: carga histórica do dev1, cujas
                // orgs não foram vinculadas) continuam recebendo atualização de status/interações —
                // o update não altera customer_id quando a org é nula (resolveCustomer→null), então é seguro.
                $org = $this->orgFor($this->extractOrgId($full, $companyId), $companyId);
                if ($primary && $this->requireLinkedOrg() && !$exists && !($org && $org->hd_customer_id)) {
                    $stats['skipped']++; $this->bumpCursor($lite, $maxUpdate); continue;
                }
                // Modelo ESPELHO: cliente migrado importa normalmente aqui (cria local); o espelhamento
                // p/ a Conecta é feito pelo sync outbound (conecta:sync). (Antes isto pulava a criação.)
                // "A partir do vínculo apenas": ignora chamados CRIADOS antes de a org ser vinculada.
                if ($primary && $org && $org->hd_linked_at && $this->createdBeforeLink($full, $org)) {
                    $stats['skipped']++; $this->bumpCursor($lite, $maxUpdate); continue;
                }
                // Cliente migrado em modo "SÓ LOG": o chamado fica FORA da fila da ERPSERV
                // (soft-deleted) mas é empurrado pra Conecta a CADA atualização — INCREMENTAL,
                // inclusive interações novas (Conecta deduplica comentários). O importer só recebe
                // tickets ATUALIZADOS desde o cursor, então pausar e voltar não perde histórico.
                // Re-mapeia (restaura p/ o upsert gravar os comentários novos), empurra, re-esconde.
                $migCust = ($org && $org->hd_customer_id) ? \App\Models\Customer::find($org->hd_customer_id) : null;
                if ($migCust && $migCust->migrated_to_conecta_at && $migCust->conecta_ticket_log_only) {
                    $trashed = HelpDeskTicket::withTrashed()
                        ->where('source_system', 'movidesk')->where('external_ref', $externalId)->where('origin_company_id', $companyId)->first();
                    if ($trashed && method_exists($trashed, 'trashed') && $trashed->trashed()) {
                        $trashed->restore();
                    }
                    $res = DB::transaction(fn () => $this->upsert($full, $companyId));
                    $stats['imported'] += $res['created'] ? 1 : 0;
                    $stats['updated']  += $res['created'] ? 0 : 1;
                    $stats['comments'] += $res['comments'];
                    try {
                        $t = HelpDeskTicket::where('source_system', 'movidesk')->where('external_ref', $externalId)->where('origin_company_id', $companyId)->first();
                        if ($t) {
                            app(\App\Services\ConectaTicketPusher::class)->push($t, $res['created'] ? 'novo' : 'legado');
                            $t->delete(); // re-esconde da fila; log + Conecta são a verdade
                        }
                    } catch (\Throwable $e) {
                        Log::warning('📥 [MOVIDESK HD] push só-log falhou', ['id' => $externalId, 'err' => $e->getMessage()]);
                    }
                    $this->bumpCursor($lite, $maxUpdate);
                    continue;
                }

                $res = DB::transaction(fn () => $this->upsert($full, $companyId));
                $stats['imported'] += $res['created'] ? 1 : 0;
                $stats['updated']  += $res['created'] ? 0 : 1;
                $stats['comments'] += $res['comments'];

                // Cliente migrado em modo "CÓPIA": o chamado cai NORMALMENTE na fila do HD da
                // ERPSERV (upsert acima) E uma CÓPIA é empurrada pra Conecta a cada atualização
                // (incremental; Conecta deduplica comentários). Fica nos DOIS lados.
                if ($migCust && $migCust->migrated_to_conecta_at && $migCust->conecta_ticket_copy) {
                    try {
                        $t = HelpDeskTicket::where('source_system', 'movidesk')->where('external_ref', $externalId)->where('origin_company_id', $companyId)->first();
                        if ($t) app(\App\Services\ConectaTicketPusher::class)->push($t, 'copia');
                    } catch (\Throwable $e) {
                        Log::warning('📥 [MOVIDESK HD] push cópia falhou', ['id' => $externalId, 'err' => $e->getMessage()]);
                    }
                }
            } catch (\Throwable $e) {
                $stats['skipped']++;
                Log::error('📥 [MOVIDESK HD] Falha ao importar ticket', ['id' => $externalId, 'error' => $e->getMessage()]);
            }

            $this->bumpCursor($lite, $maxUpdate);
        }

        // Avança o cursor (com folga de 1s para trás para não perder atualizações no mesmo segundo).
        $this->saveCursor($maxUpdate->copy()->subSecond(), $companyId);

        // PASSADA DEDICADA: empurra comentários pendentes de QUALQUER chamado espelhado,
        // independente de o chamado ter entrado na varredura por lastUpdate. Sem isto, uma
        // interação criada só no Minutor só ia pro Movidesk quando o chamado mudasse lá.
        $stats['pushed'] = $this->pushPendingComments($companyId);
        // PASSADA DEDICADA DE STATUS: mudança de status no Minutor vai pro Movidesk sozinha,
        // independente do chamado ter entrado na varredura por lastUpdate.
        $stats['status_pushed'] = $this->pushPendingStatuses($companyId);
        // CÓPIA Promax (chamado aberto no Minutor via suporte@ → cópia no Movidesk). Flag própria, padrão off.
        if ($primary) {
            try { $stats['promax_copy'] = app(MovideskPromaxCopyService::class)->run(); }
            catch (\Throwable $e) { Log::error('📤 [MOVIDESK COPIA] falhou', ['error' => $e->getMessage()]); }
        }
        // Re-hospeda imagens inline das interações novas (fora da transação; URL S3 expira em horas).
        $stats['images'] = $this->rehostPendingImages();

        Log::info('📥 [MOVIDESK HD] Import concluído', $stats);
        return $stats;
    }

    /**
     * Varre TODOS os comentários pendentes (criados no Minutor, não-sistema, sem external_action_id)
     * de chamados espelhados do Movidesk e os empurra como ações — independe do sync por lastUpdate.
     * Idempotente: pushComments só envia quem ainda não tem external_action_id.
     */
    public function pushPendingComments(?int $companyId = null): int
    {
        if (!$this->pushCommentsEnabled()) {
            return 0;
        }
        // SÓ a conta Movidesk da ERPSERV escreve (BIZIFY nunca) — ver MovideskPushPolicy.
        if (($companyId ?: $this->companyId()) !== MovideskPushPolicy::primaryCompanyId()) {
            return 0;
        }
        $companyId = $companyId ?: $this->companyId();
        $ticketIds = MovideskPushPolicy::scopeComments(HelpDeskTicketComment::query()
            ->whereNull('source')->where('is_system', false)->whereNull('external_action_id'))
            ->when($companyId, fn ($q) => $q->whereIn('ticket_id', HelpDeskTicket::where('origin_company_id', $companyId)->select('id')))
            ->distinct()->pluck('ticket_id');
        if ($ticketIds->isEmpty()) {
            return 0;
        }
        $pushed = 0;
        foreach ($ticketIds as $tid) {
            $ticket = HelpDeskTicket::where('id', $tid)
                ->where('source_system', 'movidesk')
                ->when($companyId, fn ($q) => $q->where('origin_company_id', $companyId))
                ->whereNotNull('external_ref')
                ->first();
            if (!$ticket) {
                continue; // chamado nativo do Minutor (sem par no Movidesk) — nada a empurrar
            }
            try {
                $full = $this->movidesk->fetchTicket((int) $ticket->external_ref);
                if (!$full) { continue; }
                // SÓ chamado com responsável em equipe da ERPSERV (fora das equipes Promax).
                if (!MovideskPushPolicy::ticketAllowed($ticket, $full['ownerTeam'] ?? null)) { continue; }
                $before = MovideskPushPolicy::scopeComments(HelpDeskTicketComment::where('ticket_id', $tid)
                    ->whereNull('source')->where('is_system', false)->whereNull('external_action_id'))->count();
                $this->pushComments($ticket, (int) $ticket->external_ref, $full['actions'] ?? []);
                $after = MovideskPushPolicy::scopeComments(HelpDeskTicketComment::where('ticket_id', $tid)
                    ->whereNull('source')->where('is_system', false)->whereNull('external_action_id'))->count();
                $pushed += max(0, $before - $after);
            } catch (\Throwable $e) {
                Log::error('📤 [MOVIDESK HD] Falha na passada de pendentes', ['ticket_id' => $tid, 'error' => $e->getMessage()]);
            }
        }
        if ($pushed > 0) {
            Log::info('📤 [MOVIDESK HD] Passada de pendentes concluída', ['pushed' => $pushed]);
        }
        return $pushed;
    }

    /**
     * Varre chamados espelhados cujo STATUS do Minutor difere da sombra (external_status) e empurra
     * o status ao Movidesk — independe do sync por lastUpdate. Só empurra status com mapeamento
     * outbound_default que tenha texto E justificativa (Movidesk exige justificativa p/ pausar).
     * Não age quando não há divergência (idempotente) nem quando o Movidesk é quem mudou (o sync
     * completo reconcilia esse caso comparando as 3 vias).
     */
    public function pushPendingStatuses(?int $companyId = null): int
    {
        $just = $this->statusJustification();
        if (!$this->writebackEnabled() || empty($just)) {
            return 0; // sem justificativa global configurada → não empurra status
        }
        // SÓ a conta Movidesk da ERPSERV escreve (BIZIFY nunca).
        if (($companyId ?: $this->companyId()) !== MovideskPushPolicy::primaryCompanyId()) {
            return 0;
        }
        $companyId = $companyId ?: $this->companyId();
        // Candidatos via join: mapeamento outbound_default do status do ticket (com texto),
        // cuja assinatura (base|texto) difere da sombra external_status → Minutor está à frente.
        // A justificativa NÃO é por status: usamos uma única global (associada a todos os status).
        $rows = DB::table('helpdesk_tickets as t')
            ->join('helpdesk_movidesk_status_map as m', function ($j) {
                $j->on('m.helpdesk_status_id', '=', 't.status_id')
                  ->on('m.company_id', '=', 't.company_id')
                  ->where('m.is_outbound_default', true);
            })
            ->where('t.source_system', 'movidesk')
            ->when($companyId, fn ($q) => $q->where('t.origin_company_id', $companyId))
            ->whereNotNull('t.external_ref')
            ->whereNotNull('m.movidesk_status_text')->where('m.movidesk_status_text', '<>', '')
            ->whereRaw("coalesce(t.external_status,'') <> btrim(coalesce(m.movidesk_base_status,'') || '|' || coalesce(m.movidesk_status_text,''))")
            ->select('t.id', 't.external_ref', 't.external_status', 'm.movidesk_base_status as base', 'm.movidesk_status_text as text')
            ->limit(200)
            ->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        $pushed = 0;
        foreach ($rows as $r) {
            $sig = trim(($r->base ?? '') . '|' . ($r->text ?? ''));
            $patch = ['status' => $r->text, 'justification' => $just];
            if (!empty($r->base)) { $patch['baseStatus'] = $r->base; }
            try {
                // SÓ chamado com responsável em equipe da ERPSERV — senão não escreve no Movidesk.
                $full = $this->movidesk->fetchTicket((int) $r->external_ref);
                if (!$full || !MovideskPushPolicy::isErpservTeam($full['ownerTeam'] ?? null)) { continue; }
                if ($this->movidesk->patchTicket((int) $r->external_ref, $patch)) {
                    HelpDeskTicket::where('id', $r->id)->update(['external_status' => $sig, 'external_synced_at' => now()]);
                    $pushed++;
                }
            } catch (\Throwable $e) {
                Log::error('📤 [MOVIDESK HD] Falha no push de status', ['ticket_id' => $r->id, 'error' => $e->getMessage()]);
            }
        }
        if ($pushed > 0) {
            Log::info('📤 [MOVIDESK HD] Passada de status concluída', ['pushed' => $pushed]);
        }
        return $pushed;
    }

    /**
     * Importa UM chamado específico do Movidesk (por id), ignorando filtros de equipe/domínio/status.
     * Uso: validação/teste manual. SOMENTE LEITURA no Movidesk.
     * @return array{created:bool,comments:int,ticket_id:int|null,hd_number:string|null}
     */
    public function importOne(int $externalId, ?int $companyId = null): array
    {
        $companyId = $companyId ?: $this->companyId();
        // Multi-empresa: usa o token Movidesk da empresa alvo (ERPSERV=global, Bizify=2, etc.).
        $this->movidesk->forCompany($companyId);
        $full = $this->movidesk->fetchTicket($externalId);
        if (!$full) return ['created' => false, 'comments' => 0, 'ticket_id' => null, 'hd_number' => null];

        $res = DB::transaction(fn () => $this->upsert($full, $companyId));
        $this->rehostPendingImages(); // fora da transação
        $hd = HelpDeskTicket::where('source_system', 'movidesk')->where('external_ref', (string) $externalId)->where('origin_company_id', $companyId)->first(['id', 'ticket_number']);
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

        $ticket = HelpDeskTicket::where('source_system', 'movidesk')->where('external_ref', $externalId)->where('origin_company_id', $companyId)->first();
        $created = false;
        // Empresa ATUAL do chamado (pode ter sido transferido p/ outra empresa do grupo): o de-para de
        // status/categoria/serviço usa o cadastro DELA. A conta de origem ($companyId) só define a busca.
        $tc = $ticket ? (int) ($ticket->company_id ?: $companyId) : $companyId;

        // Cliente/solicitante a partir do primeiro "client" do Movidesk.
        $client   = $md['clients'][0] ?? null;
        $orgId    = $this->extractOrgId($md, $companyId);
        $customerId = $this->resolveCustomer($orgId, $companyId);
        $reqName  = (string) ($client['businessName'] ?? '');
        $reqEmail = $this->firstEmail($client);
        // Contas SECUNDÁRIAS (ex.: BIZIFY): sem org vinculada, amarra o cliente pelo DOMÍNIO do
        // solicitante (Regras de Associação da empresa) e, por fim, pelo contato (users.customer_id).
        if (!$customerId && !$this->teamFilterApplies($companyId)) {
            $customerId = $this->resolveCustomerByEmail($reqEmail, $companyId);
        }
        // Nome da organização do Movidesk (para clientes legados sem cadastro no Minutor).
        $orgName  = trim((string) ($client['organization']['businessName'] ?? ''));
        if ($orgName === '' && $orgId) { $orgName = trim((string) (optional($this->orgFor($orgId, $companyId))->name ?? '')); }

        if (!$ticket) {
            $ticket = new HelpDeskTicket();
            $ticket->forceFill([
                // Número do chamado = número do Movidesk. Multi-conta: ERPSERV mantém id cru;
                // demais empresas recebem prefixo do slug (ids colidem entre contas Movidesk).
                'ticket_number' => $this->ticketNumberFor($companyId, $externalId),
                'source_system' => 'movidesk',
                'external_ref'  => $externalId,
                'channel'       => 'movidesk',
                'company_id'        => $companyId,
                'origin_company_id' => $companyId, // conta Movidesk de origem (não muda na transferência)
            ]);
            $created = true;
        }

        $ticket->subject = mb_substr((string) ($md['subject'] ?: ('Chamado Movidesk #' . $externalId)), 0, 255);
        if ($created) {
            // descrição inicial = primeira ação (mensagem de abertura do cliente), se houver.
            $ticket->description = $this->firstActionBody($md);
        }
        if ($customerId) {
            // Org VINCULADA por CNPJ → cliente do Minutor.
            $ticket->customer_id = $customerId;
        } elseif ($orgName !== '') {
            // Org NÃO vinculada (cliente legado, sem cadastro no Minutor desde 2017) → mostra o nome do Movidesk.
            $ticket->historical_customer_name = mb_substr($orgName, 0, 255);
        }
        if ($reqName)  { $ticket->requester_name = mb_substr($reqName, 0, 255); }
        if ($reqEmail) { $ticket->requester_email = mb_substr($reqEmail, 0, 255); }

        // RESPONSÁVEL: sincroniza o assignee com o owner do Movidesk (mapeado por e-mail → usuário).
        // Só reaplica quando o responsável MUDOU no Movidesk — não desfaz reatribuição feita no Minutor.
        $ownerEmail = mb_strtolower(trim((string) ($md['owner']['email'] ?? '')));
        // O RESPONSÁVEL do Minutor NUNCA é alterado pela integração depois que o chamado existe
        // (decisão Ricardo 14/11/2026, go-live: agentes desativados no Movidesk repassam os chamados
        // p/ o agente genérico, e isso não pode mexer no responsável real do Minutor).
        // Só na CRIAÇÃO o owner do Movidesk vira o responsável. A sombra segue atualizada (informativo).
        if ($created) {
            // Agente GENÉRICO do Movidesk ("Suporte ERPSERV", usa o e-mail do Ricardo) não é pessoa:
            // chamado novo com ele como owner entra SEM responsável (vai para a Triagem).
            $ownerName = mb_strtolower(trim((string) ($md['owner']['businessName'] ?? '')));
            $generic = in_array($ownerName, ['suporte erpserv'], true);
            $ticket->assignee_id = ($ownerEmail !== '' && !$generic) ? $this->resolveAgentUser($ownerEmail) : null;
        }
        $ticket->external_owner_email = $ownerEmail ?: null;
        // Equipe atual no Movidesk — decide se o chamado responde PELO Movidesk (equipe ERPSERV) sem chamar a API.
        $ticket->external_owner_team = mb_substr(trim((string) ($md['ownerTeam'] ?? '')), 0, 255) ?: null;

        // ── CLASSIFICAÇÃO ──
        // Categoria/Serviço/Urgência: merge de 3 vias com WRITE-BACK (Minutor vence → empurra p/ Movidesk).
        //   A sombra desses campos só avança APÓS o PATCH dar certo (senão perde a alteração / re-tenta).
        // Nível/Status: por ora só ENTRADA (Movidesk→HD) — o PATCH de nível (custom field) e de status
        //   (exige "motivo") ainda não é suportado; empurrar esses dois fica para um próximo passo.
        $patch = [];        // write-back
        $pushShadow = [];   // sombras a gravar só se o PATCH tiver sucesso

        // Categoria
        $hdCatName = $ticket->category_id ? $this->nameOfId('helpdesk_categories', (int) $ticket->category_id) : null;
        $m = $this->merge3($created, $hdCatName, trim((string) ($md['category'] ?? '')) ?: null, $ticket->external_category);
        if ($m['push'] !== null) { $patch['category'] = $m['push']; $pushShadow['external_category'] = $m['shadow']; }
        else { if ($m['setHd']) { $ticket->category_id = $m['hd'] ? $this->matchByName('helpdesk_categories', $tc, $m['hd']) : null; } $ticket->external_category = $m['shadow']; }

        // Serviço — ENTRADA apenas: o Movidesk aceita o PATCH (200) mas IGNORA a troca de serviço
        // (valida pelo contexto do chamado). Então adotamos o serviço do Movidesk quando ele muda.
        $mdSvc = trim((string) ($md['serviceFirstLevel'] ?? ($md['serviceFull'][0] ?? ($md['serviceSecondLevel'] ?? '')))) ?: null;
        if ($created || $ticket->external_service !== $mdSvc) {
            $sid = $mdSvc ? $this->matchByName('helpdesk_services', $tc, $mdSvc) : null;
            if ($sid) { $ticket->service_id = $sid; }
            $ticket->external_service = $mdSvc;
        }

        // Urgência ⇄ Prioridade (espaço de nomes de urgência do Movidesk)
        $hdUrg = $this->priorityToUrgency((string) $ticket->priority);
        $m = $this->merge3($created, $hdUrg, trim((string) ($md['urgency'] ?? '')) ?: null, $ticket->external_urgency);
        if ($m['push'] !== null) { $patch['urgency'] = $m['push']; $pushShadow['external_urgency'] = $m['shadow']; }
        else { if ($m['setHd'] && $m['hd']) { $p = $this->mapUrgency($m['hd']); if ($p) $ticket->priority = $p; } $ticket->external_urgency = $m['shadow']; }

        // Nível — merge de 3 vias + PUSH (custom field 13485 com customFieldRuleId + line).
        $m = $this->merge3($created, $ticket->level ?: null, $this->extractNivel($md), $ticket->external_level);
        if ($m['push'] !== null) {
            [$ruleId, $line] = $this->nivelMeta($md);
            $patch['customFieldValues'] = [[
                'customFieldId' => 13485, 'customFieldRuleId' => $ruleId, 'line' => $line,
                'items' => [['customFieldItem' => $m['push']]],
            ]];
            $pushShadow['external_level'] = $m['shadow'];
        } else {
            if ($m['setHd']) { $ticket->level = $m['hd']; }
            $ticket->external_level = $m['shadow'];
        }

        // Status — merge de 3 vias. PUSH só quando há JUSTIFICATIVA configurada (Movidesk exige).
        $mdSig = trim($base . '|' . $statusText);
        $hdOut = $ticket->status_id ? $this->outboundStatus($tc, (int) $ticket->status_id) : null;
        $hdSig = $hdOut ? trim(($hdOut['base'] ?? '') . '|' . ($hdOut['text'] ?? '')) : null;
        if ($created) {
            $sid = HelpDeskMovideskStatusMap::resolveInbound($tc, $base, $statusText)
                ?? HelpDeskMovideskStatusMap::resolveInbound(null, $base, $statusText)
                ?? optional(HelpDeskStatus::query()->where('company_id', $tc)->where('is_default', true)->first())->id;
            if ($sid) { $ticket->status_id = $sid; }
            $ticket->external_status = $mdSig;
        } elseif ($hdSig !== null && !empty($ticket->external_status) && $hdSig !== $ticket->external_status) {
            // Minutor mudou o status → empurra pro Movidesk. O Movidesk exige justificativa em TODA
            // troca de status; usamos UMA justificativa global (SystemSetting) associada a todos os
            // status no Movidesk. Sem ela configurada, não empurra (evita 400).
            // NB: só entra aqui com SOMBRA preenchida. Tickets migrados do dev1 vêm com external_status
            // vazio; sem este guard, hdSig!='' sempre "parecia" edição do Minutor e o status do Movidesk
            // (ex.: Cancelado) nunca era puxado. Com sombra vazia, cai no branch abaixo (puxa + backfilla).
            $just = $this->statusJustification();
            if (!empty($hdOut['text']) && !empty($just)) {
                $patch['status'] = $hdOut['text'];
                if (!empty($hdOut['base'])) { $patch['baseStatus'] = $hdOut['base']; }
                $patch['justification'] = $just;
                $pushShadow['external_status'] = $hdSig;
            }
            // sem justificativa global: não empurra e não puxa (mantém o status do Minutor).
        } elseif ($mdSig !== $ticket->external_status) {
            // Só o Movidesk mudou → puxa p/ o Minutor.
            $sid = HelpDeskMovideskStatusMap::resolveInbound($tc, $base, $statusText)
                ?? HelpDeskMovideskStatusMap::resolveInbound(null, $base, $statusText);
            if ($sid) { $ticket->status_id = $sid; }
            $ticket->external_status = $mdSig;
        }

        $ticket->external_synced_at = now();
        // company_id NÃO é reimposto em update: o chamado pode ter sido TRANSFERIDO de empresa
        // (transferCompany). A origem fica em origin_company_id.
        $ticket->save();

        // WRITE-BACK: empurra Categoria/Urgência/Nível (e Status quando há justificativa) p/ o Movidesk
        // (só metadado, nunca interação). A sombra só avança se o PATCH tiver sucesso (falha é re-tentada).
        $mdWritable = MovideskPushPolicy::isPrimaryAccount($ticket) && MovideskPushPolicy::isErpservTeam($md['ownerTeam'] ?? null);
        if ($patch && $this->writebackEnabled() && $mdWritable) {
            if ($this->movidesk->patchTicket((int) $externalId, $patch) && $pushShadow) {
                foreach ($pushShadow as $col => $val) { $ticket->$col = $val; }
                $ticket->save();
            }
        }

        // A ação de ABERTURA vira a "descrição inicial" do chamado — NÃO deve virar também uma
        // interação (senão duplica: descrição + comentário #1 iguais).
        $comments = $this->importActions($ticket, $md['actions'] ?? [], $this->openingActionId($md));
        // Anexos da ação de ABERTURA viram anexos do CHAMADO (a abertura é a descrição, não interação).
        if ($created) {
            $openId = $this->openingActionId($md);
            foreach ($md['actions'] ?? [] as $a) {
                if ((string) ($a['id'] ?? '') === (string) $openId && !empty($a['attachments'])) {
                    $this->pendingFilePulls[] = ['e' => 'HELPDESK_TICKET', 'id' => (int) $ticket->id, 'atts' => $a['attachments'], 'vis' => 'customer', 'actor' => null];
                }
            }
        }

        // SAÍDA de INTERAÇÕES: empurra p/ o Movidesk os comentários NATIVOS do Minutor ainda não enviados.
        if ($this->pushCommentsEnabled() && $mdWritable) {
            $this->pushComments($ticket, (int) $externalId, $md['actions'] ?? []);
        }

        return ['created' => $created, 'comments' => $comments];
    }

    /** Empurra comentários nativos do Minutor (não importados) como ações no Movidesk. */
    private function pushComments(HelpDeskTicket $ticket, int $externalId, array $mdActions): void
    {
        // Comentários criados NO MINUTOR (source null), não-sistema, ainda não enviados (sem action id).
        $pending = MovideskPushPolicy::scopeComments(HelpDeskTicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->whereNull('source')
            ->where('is_system', false)
            ->whereNull('external_action_id'))
            ->orderBy('id')
            ->get(['id', 'body', 'visibility', 'author_user_id']);
        if ($pending->isEmpty()) return;

        // id da última ação no Movidesk — as novas recebem ids sequenciais a partir daqui.
        $maxId = 0;
        foreach ($mdActions as $a) { $maxId = max($maxId, (int) ($a['id'] ?? 0)); }

        foreach ($pending as $c) {
            $text = $this->htmlToText((string) $c->body);
            if ($text === '') { // nada a enviar; marca como tratado p/ não reprocessar
                $c->external_action_id = '0'; $c->save();
                continue;
            }
            $type = $c->visibility === 'customer' ? 2 : 1; // 2=pública (chega ao cliente), 1=interna
            $action = ['type' => $type, 'description' => $text];
            // Atribui o AUTOR da ação no Movidesk (senão fica sem nome/avatar).
            $personId = $this->authorMovideskId($c->author_user_id);
            if ($personId) { $action['createdBy'] = ['id' => (string) $personId]; }
            $ok = $this->movidesk->addActions($externalId, [$action]);
            if ($ok) {
                // Anti-eco: grava o id REAL da ação criada (relê o chamado). Chutar "maior id + 1"
                // erra se o cliente/Movidesk criar outra ação no mesmo instante → a nossa voltaria
                // duplicada no próximo import. Sem leitura possível, cai no sequencial.
                $realId = $this->locateCreatedActionId($externalId, $maxId, $type, $text);
                $maxId = max($maxId + 1, (int) $realId);
                $c->external_action_id = (string) ($realId ?: $maxId);
                $c->save();
                // Anexos da interação sobem junto, na MESMA ação.
                try { app(MovideskAttachmentSync::class)->push('HELPDESK_TICKET_COMMENT', (int) $c->id, $externalId, (int) $c->external_action_id); }
                catch (\Throwable $e) { Log::warning('📤 [MOVIDESK ANEXO] push falhou', ['comment' => $c->id, 'e' => $e->getMessage()]); }
            } else {
                break; // falhou → tenta de novo no próximo ciclo (mantém ordem)
            }
        }
    }

    /** Converte HTML do comentário em texto (preserva quebras) para enviar como `description`.
     * Movidesk aceita só texto na escrita (htmlDescription é read-only) — então tratamos também
     * TABELAS (assinaturas montadas em <table>): célula → espaço, linha/tabela/bloco → quebra,
     * senão a assinatura sai como texto corrido grudado. */
    public function htmlToText(string $html): string
    {
        $s = $html;
        // Remove a ASSINATURA padrão (montada em <table>) — não deve ir pro Movidesk.
        // Greedy do 1º <table> ao último </table> cobre tabelas aninhadas da assinatura.
        $stripped = preg_replace('/<table\b.*<\/table>/is', '', $s);
        if ($stripped !== null) { $s = $stripped; }
        // Células de tabela viram espaço (mantém itens da mesma linha separados).
        $s = preg_replace('/<\s*\/\s*(td|th)\s*>/i', ' ', $s);
        // Quebras explícitas.
        $s = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $s);
        // ABERTURA de tabela → quebra (separa a mensagem da assinatura montada em <table>).
        $s = preg_replace('/<\s*table[^>]*>/i', "\n", $s);
        // Fim de linha de tabela / tabela / blocos → quebra de linha.
        $s = preg_replace('/<\s*\/\s*(p|div|li|tr|table|h[1-6]|blockquote|section|header|footer)\s*>/i', "\n", $s);
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace("\xC2\xA0", ' ', $s);          // &nbsp; → espaço normal
        $s = preg_replace('/[ \t]{2,}/', ' ', $s);       // colapsa espaços repetidos
        $s = preg_replace('/[ \t]*\n[ \t]*/', "\n", $s); // limpa espaços em volta das quebras
        $s = preg_replace("/\n{3,}/", "\n\n", $s);       // no máximo 1 linha em branco
        $s = trim($s);
        // O Movidesk renderiza `description` como HTML → \n colapsa em espaço (as linhas ficavam
        // grudadas). Escapa o texto e converte as quebras em <br> para quebrar de verdade.
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return str_replace("\n", '<br>', $s);
    }

    /** Importa as ações do Movidesk como interações do HD (dedup por external_action_id). */
    public function importActions(HelpDeskTicket $ticket, array $actions, ?string $skipActionId = null): int
    {
        if (!$actions) return 0;

        // Dedup por external_action_id em QUALQUER origem — inclui comentários NATIVOS que já
        // empurramos p/ o Movidesk (guardam o id da ação criada) → evita reimportar (eco).
        $ticketComments = HelpDeskTicketComment::withTrashed()->where('ticket_id', $ticket->id)->get();
        $existing = $ticketComments->whereNotNull('external_action_id')
            ->pluck('external_action_id')->filter()->map(fn ($v) => (string) $v)->flip();

        // RECONCILIAÇÃO dos históricos (migração dev1 veio SEM external_action_id): indexa os
        // comentários ainda SEM id por created_at (= createdDate da ação, idêntico ao segundo).
        // Ao reencontrar a ação, faz BACKFILL do external_action_id no comentário existente em vez
        // de duplicar. Evita a duplicação de interações ao re-sincronizar tickets já migrados.
        $byDate = $ticketComments->whereNull('external_action_id')
            ->groupBy(fn ($c) => \Carbon\Carbon::parse($c->created_at)->format('Y-m-d H:i:s'));

        $n = 0;
        foreach ($actions as $a) {
            $aid = (string) ($a['id'] ?? '');
            if ($aid === '' || $existing->has($aid)) continue;
            if ($skipActionId !== null && $aid === $skipActionId) continue; // abertura = descrição, não duplicar

            // Reconciliação: já existe um comentário histórico desta ação (mesmo created_at)?
            $createdKey = isset($a['createdDate']) ? \Carbon\Carbon::parse($a['createdDate'])->format('Y-m-d H:i:s') : null;
            if ($createdKey && $byDate->has($createdKey) && $byDate->get($createdKey)->isNotEmpty()) {
                $match = $byDate->get($createdKey)->shift(); // consome (não reusar p/ outra ação)
                $match->external_action_id = $aid;
                if (empty($match->idempotency_key)) {
                    $match->idempotency_key = 'movidesk-' . $this->movideskCompanyTag((int) ($ticket->origin_company_id ?: $ticket->company_id)) . ($ticket->external_ref ?: ('c' . $ticket->movidesk_copy_id)) . '-a' . $aid;
                }
                $match->saveQuietly(); // sem observers (não re-empurrar nada)
                $existing->put($aid, true);
                continue; // NÃO cria duplicata
            }

            $body = (string) ($a['htmlDescription'] ?? '');
            if (trim(strip_tags($body)) === '' && empty($a['attachments'])) continue; // ação vazia

            $isPublic = (bool) ($a['isPublic'] ?? true);
            $authorId = $this->resolveAgentUser($a['createdBy']['email'] ?? null);

            $comment = HelpDeskTicketComment::create([
                'ticket_id'          => $ticket->id,
                'author_user_id'     => $authorId, // null => interação de origem externa (cliente)
                'body'               => $body,
                'visibility'         => $isPublic ? 'customer' : 'internal',
                'is_system'          => false,
                'channel'            => 'movidesk',
                'source'             => 'movidesk',
                'external_action_id' => $aid,
                // action.id do Movidesk é sequencial POR ticket (1,2,3…) — a chave precisa do id do ticket.
                'idempotency_key'    => 'movidesk-' . $this->movideskCompanyTag((int) ($ticket->origin_company_id ?: $ticket->company_id)) . ($ticket->external_ref ?: ('c' . $ticket->movidesk_copy_id)) . '-a' . $aid,
            ]);
            // Imagem inline (URL S3 Movidesk) vem com assinatura que EXPIRA em horas. Enfileira p/
            // re-hospedar FORA da transação (download é lento) — baixa agora enquanto a URL é válida.
            // Arquivos anexados na ação → baixa p/ o Minutor (fora da transação).
            if (!empty($a['attachments'])) {
                $this->pendingFilePulls[] = ['e' => 'HELPDESK_TICKET_COMMENT', 'id' => (int) $comment->id, 'atts' => $a['attachments'], 'vis' => $isPublic ? 'customer' : 'internal', 'actor' => $authorId];
            }
            if (preg_match('~s3[.-][^/]*amazonaws\.com/[^?"\']*movidesk~i', $body)) {
                $this->pendingImageComments[] = ['c' => (int) $comment->id, 't' => (int) $ticket->id];
            }
            $existing->put($aid, true);
            $n++;
        }
        return $n;
    }

    /**
     * Re-hospeda imagens inline (URL S3 do Movidesk) dos comentários recém-criados: baixa enquanto
     * a URL assinada ainda é válida, salva como anexo da interação (AttachmentService) e reescreve o
     * <img src> pro nosso endpoint de download. Roda FORA da transação (download lento). Best-effort:
     * URL expirada (403) ou falha → deixa o <img> como está, sem quebrar o import.
     */
    public function rehostPendingImages(): int
    {
        // Anexos (arquivos) das ações importadas — download fora da transação.
        if ($this->pendingFilePulls) {
            $pulls = $this->pendingFilePulls; $this->pendingFilePulls = [];
            $sync = app(MovideskAttachmentSync::class);
            foreach ($pulls as $p) {
                try { $sync->pull($p['e'], $p['id'], $p['atts'], $p['vis'], $p['actor']); }
                catch (\Throwable $e) { Log::warning('📥 [MOVIDESK ANEXO] pull falhou', ['e' => $e->getMessage()]); }
            }
        }
        if (!$this->pendingImageComments) return 0;
        $pending = $this->pendingImageComments;
        $this->pendingImageComments = [];
        $svc = app(\App\Attachments\AttachmentService::class);
        $done = 0;
        foreach ($pending as $p) {
            $comment = HelpDeskTicketComment::find($p['c']);
            if (!$comment) continue;
            try {
                if ($this->rehostCommentImages($comment, (int) $p['t'], $svc)) $done++;
            } catch (\Throwable $e) {
                Log::warning('🖼️ [MOVIDESK HD] rehost de imagens falhou', ['comment' => $p['c'], 'error' => $e->getMessage()]);
            }
        }
        if ($done) Log::info('🖼️ [MOVIDESK HD] imagens inline re-hospedadas', ['comentarios' => $done]);
        return $done;
    }

    private function rehostCommentImages(HelpDeskTicketComment $comment, int $ticketId, \App\Attachments\AttachmentService $svc): bool
    {
        $body = (string) $comment->body;
        if (!preg_match_all('/<img\b[^>]*>/i', $body, $tags)) return false;
        $changed = false;
        $count = 0;
        foreach ($tags[0] as $tag) {
            if ($count >= 10) break; // teto por interação
            if (!preg_match('/src=(["\'])(.*?)\1/i', $tag, $sm)) continue;
            $src = html_entity_decode($sm[2]);
            // só URLs S3 do Movidesk (as que expiram); ignora data:, nossas (/api/...), externas
            if (!preg_match('~^https?://[^/]*s3[.-][^/]*amazonaws\.com/[^?]*movidesk~i', $src)) continue;
            $count++;
            $resp = \Illuminate\Support\Facades\Http::timeout(25)->get($src);
            if (!$resp->ok()) continue; // 403 expirada → mantém
            $ct = strtolower((string) ($resp->header('Content-Type') ?: ''));
            if (strncmp($ct, 'image/', 6) !== 0) continue;
            $bytes = $resp->body();
            if (strlen($bytes) < 64) continue;
            $ext = match (true) {
                str_contains($ct, 'png')  => 'png',
                str_contains($ct, 'gif')  => 'gif',
                str_contains($ct, 'webp') => 'webp',
                str_contains($ct, 'svg')  => 'svg',
                default                   => 'jpg',
            };
            $tmp = tempnam(sys_get_temp_dir(), 'mvimg');
            file_put_contents($tmp, $bytes);
            try {
                $uf = new \Illuminate\Http\UploadedFile($tmp, 'movidesk-' . substr(md5($src), 0, 10) . '.' . $ext, $ct, null, true);
                $att = $svc->store($this->imageActorFor($comment), [
                    'entity_type' => 'HELPDESK_TICKET_COMMENT', 'entity_id' => $comment->id,
                    'category'    => 'image',
                    'visibility'  => $comment->visibility,
                    'file'        => $uf,
                ]);
            } finally {
                @unlink($tmp);
            }
            // Usa <img src> DIRETO pro endpoint de download da interação (mesmo mecanismo do preview
            // de anexo, que carrega no navegador via sessão/cookie). O src S3 original expira; troca
            // pelo nosso endpoint estável. (data-att-id exigiria o bundle FE novo + fetch autenticado.)
            $url = "/api/v1/help-desk/tickets/{$ticketId}/comments/{$comment->id}/attachments/{$att->id}/download";
            $newTag = preg_replace('/src=(["\']).*?\1/i', 'src="' . $url . '"', $tag, 1);
            $body = str_replace($tag, $newTag, $body);
            $changed = true;
        }
        if ($changed) {
            $comment->body = $body;
            $comment->saveQuietly();
        }
        return $changed;
    }

    /** Ator (User) p/ uploaded_by do anexo — autor da interação se mapeado, senão 1º usuário (campo de auditoria). */
    private function imageActorFor(HelpDeskTicketComment $comment): \App\Models\User
    {
        if ($comment->author_user_id) {
            $u = \App\Models\User::find($comment->author_user_id);
            if ($u) return $u;
        }
        return $this->imageActor ??= \App\Models\User::query()->orderBy('id')->firstOrFail();
    }

    // ── Resolução de entidades ────────────────────────────────────────────────
    /** Cliente Minutor a partir do VÍNCULO DEDICADO do HD (hd_customer_id). Null se a org não estiver vinculada. */
    /**
     * Registra a ENTRADA de um ticket novo de cliente migrado (NÃO cria na ERPSERV; a Conecta
     * importa do Movidesk). result='routed' = ERPSERV só logou a entrada. Best-effort.
     */
    private function logMigratedEntry(string $externalId, int $customerId, array $md): void
    {
        try {
            $log = \App\Models\ConectaTicketMigrationLog::firstOrNew(['source_system' => 'movidesk', 'external_ref' => (string) $externalId]);
            $log->customer_id = $customerId;
            $log->movidesk_id = (string) $externalId;
            $log->subject     = mb_substr((string) ($md['subject'] ?? ('Chamado Movidesk #' . $externalId)), 0, 255);
            $log->kind        = 'novo';
            $log->result      = 'routed';
            $log->sent_at     = now();
            $log->save();
        } catch (\Throwable $e) {
            Log::warning('📥 [MOVIDESK HD] falha ao logar entrada de migrado', ['id' => $externalId, 'err' => $e->getMessage()]);
        }
    }

    private function resolveCustomer($orgId, ?int $companyId = null): ?int
    {
        $org = $this->orgFor($orgId, $companyId);
        return $org && $org->hd_customer_id ? (int) $org->hd_customer_id : null;
    }

    /** Organização Movidesk (com hd_customer_id/hd_linked_at) a partir do id do Movidesk.
     *  Multi-conta Movidesk: escopa por empresa (ids de org colidem entre contas). */
    private function orgFor($orgId, ?int $companyId = null): ?MovideskOrganization
    {
        if (!$orgId) return null;
        return MovideskOrganization::where('movidesk_id', (string) $orgId)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->first();
    }

    /** Número do chamado p/ ticket Movidesk importado. ERPSERV (empresa legada) = id CRU
     *  (retrocompat); demais empresas = prefixo do slug (ids de ticket colidem entre contas
     *  Movidesk). Desde 2026-11-09 todas as empresas usam o número CRU do Movidesk (sem "BIZ-"). */
    private function ticketNumberFor(int $companyId, string $externalId): string
    {
        // Número = o MESMO do Movidesk de origem, em TODAS as empresas (sem prefixo) e NUNCA muda (nem
        // ao transferir de empresa). Unicidade POR ORIGEM (origin_company_id, ticket_number).
        return $externalId;
    }

    /** Tag de empresa p/ chaves internas (idempotency_key). ERPSERV = '' (retrocompat);
     *  outras empresas = "c<id>-". Ids de ação do Movidesk colidem entre contas. */
    private function movideskCompanyTag(int $companyId): string
    {
        static $erpId = null;
        if ($erpId === null) { $erpId = (int) (\App\Models\Company::where('slug', 'erpserv')->value('id') ?? 1); }
        return $companyId === $erpId ? '' : ('c' . $companyId . '-');
    }

    /** id da organização Movidesk do chamado (1º client). */
    private function extractOrgId(array $md, ?int $companyId = null)
    {
        $client = $md['clients'][0] ?? [];
        if (!empty($client['organization']['id'])) return $client['organization']['id'];
        // Solicitante é a PRÓPRIA organização (personType 2 = empresa) → o id dele é o da org.
        // (Só contas secundárias — ERPSERV mantém o comportamento histórico.)
        if ($companyId && !$this->teamFilterApplies($companyId)
            && (int) ($client['personType'] ?? 0) === 2 && !empty($client['id'])) return $client['id'];
        return null;
    }

    /** Cliente pelo e-mail do solicitante: regra de associação por domínio (na empresa) → contato. */
    private function resolveCustomerByEmail(?string $email, int $companyId): ?int
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || !str_contains($email, '@')) return null;
        $domain = \App\Models\HelpDeskAssociationRule::normalize($email);
        $cid = \App\Models\HelpDeskAssociationRule::withoutGlobalScopes()
            ->whereNull('deleted_at')->where('enabled', true)->where('domain', $domain)
            ->where('company_id', $companyId)->whereNotNull('customer_id')->value('customer_id');
        if ($cid) return (int) $cid;
        $cid = User::whereRaw('lower(email) = ?', [$email])->whereNotNull('customer_id')->value('customer_id');
        return $cid ? (int) $cid : null;
    }

    /** true se o chamado foi CRIADO antes de a org ser vinculada ao HD (deve ser ignorado). */
    private function createdBeforeLink(array $md, MovideskOrganization $org): bool
    {
        $created = $md['createdDate'] ?? null;
        if (!$created || !$org->hd_linked_at) return false;
        try { return Carbon::parse($created)->lt($org->hd_linked_at); }
        catch (\Throwable) { return false; }
    }

    /** Write-back (Fase 2) habilitado? Empurra classificação do Minutor p/ o Movidesk. */
    private function writebackEnabled(): bool
    {
        return (bool) SystemSetting::get('movidesk_hd_writeback_enabled', false);
    }

    /**
     * Justificativa ÚNICA global usada em TODA troca de status enviada ao Movidesk (que exige uma
     * justificativa cadastrada e associada ao status). Deve existir no Movidesk e estar associada a
     * todos os status que a integração empurra. Vazio → push de status desligado.
     */
    private function statusJustification(): ?string
    {
        $v = trim((string) SystemSetting::get('movidesk_hd_status_justification', ''));
        return $v !== '' ? $v : null;
    }

    /** Empurrar INTERAÇÕES (comentários) do Minutor como ações no Movidesk? */
    /** Cache por execução: user_id → movidesk person id (evita repetir lookup/API). */
    private array $authorMdCache = [];
    /** Comentários novos com imagem inline S3 a re-hospedar (fora da transação). [{c:commentId,t:ticketId}] */
    private array $pendingImageComments = [];
    /** Anexos (arquivos) de ações do Movidesk a baixar FORA da transação. [{e,id,atts,vis,actor}] */
    private array $pendingFilePulls = [];
    private ?\App\Models\User $imageActor = null;

    /**
     * Id da PESSOA no Movidesk do autor (p/ createdBy da ação):
     * 1) movidesk_agents.user_id; 2) movidesk_agents por e-mail; 3) pessoa no Movidesk por e-mail (API).
     * null → ação vai sem autor (comportamento antigo).
     */
    public function authorMovideskId(?int $userId): ?string
    {
        if (!$userId) return null;
        if (array_key_exists($userId, $this->authorMdCache)) return $this->authorMdCache[$userId];
        $id = MovideskAgent::where('user_id', $userId)->whereNotNull('movidesk_id')->value('movidesk_id');
        if (!$id) {
            $email = User::where('id', $userId)->value('email');
            if ($email) {
                $id = MovideskAgent::where('email', $email)->whereNotNull('movidesk_id')->value('movidesk_id')
                    ?: $this->movidesk->findPersonIdByEmail($email);
            }
        }
        return $this->authorMdCache[$userId] = ($id ? (string) $id : null);
    }

    private function pushCommentsEnabled(): bool
    {
        // Ligado + corte de data definido (sem corte nada sai: protege o histórico migrado).
        return MovideskPushPolicy::enabled();
    }

    /** Id da ação recém-criada no Movidesk: 1ª ação > $afterId do mesmo tipo e mesmo texto. */
    public function locateCreatedActionId(int $externalId, int $afterId, int $type, string $sentHtml): ?int
    {
        try {
            $full = $this->movidesk->fetchTicket($externalId);
        } catch (\Throwable) {
            return null;
        }
        $norm = fn ($v) => preg_replace('/\s+/u', ' ', trim(html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], ' ', (string) $v)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $want = mb_substr($norm($sentHtml), 0, 80);
        $fallback = null;
        $acts = $full['actions'] ?? [];
        usort($acts, fn ($a, $b) => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        foreach ($acts as $a) {
            $id = (int) ($a['id'] ?? 0);
            if ($id <= $afterId || (int) ($a['type'] ?? 0) !== $type) continue;
            $got = mb_substr($norm($a['description'] ?? ($a['htmlDescription'] ?? '')), 0, 80);
            if ($want !== '' && $got === $want) return $id;
            $fallback ??= $id;
        }
        return $fallback;
    }

    /**
     * Merge de 3 vias por campo (nome). Minutor VENCE no conflito (empurra p/ Movidesk).
     * @return array{setHd:bool,hd:?string,push:?string,shadow:?string}
     *   setHd=true → aplicar $hd no Minutor; push!=null → enviar ao Movidesk; shadow → novo valor-sombra.
     */
    private function merge3(bool $created, ?string $hdVal, ?string $mdVal, ?string $shadow): array
    {
        $n = fn ($v) => mb_strtolower(trim((string) $v));
        if ($created) {
            // Chamado novo: adota o Movidesk como ponto de partida (sem empurrar).
            return ['setHd' => true, 'hd' => $mdVal, 'push' => null, 'shadow' => $mdVal];
        }
        if ($shadow === null) {
            // Sombra ainda não inicializada (campo estava vazio no Movidesk / ticket antigo).
            // Se o Minutor JÁ tem um valor próprio diferente do Movidesk, é uma edição do atendente
            // → empurra (Minutor vence). Senão, adota o Movidesk.
            if ($n($hdVal) !== '' && $n($hdVal) !== $n($mdVal)) {
                return ['setHd' => false, 'hd' => null, 'push' => ($hdVal ?? ''), 'shadow' => $hdVal];
            }
            return ['setHd' => true, 'hd' => $mdVal, 'push' => null, 'shadow' => $mdVal];
        }
        if ($n($hdVal) !== $n($shadow)) {
            // Minutor mudou desde o último sync → empurra p/ Movidesk.
            return ['setHd' => false, 'hd' => null, 'push' => ($hdVal ?? ''), 'shadow' => $hdVal];
        }
        if ($n($mdVal) !== $n($shadow)) {
            // Só o Movidesk mudou → puxa p/ o Minutor.
            return ['setHd' => true, 'hd' => $mdVal, 'push' => null, 'shadow' => $mdVal];
        }
        return ['setHd' => false, 'hd' => null, 'push' => null, 'shadow' => $shadow];
    }

    /** Nome do registro de um cadastro (categoria/serviço) por id. */
    private function nameOfId(string $table, int $id): ?string
    {
        $row = \Illuminate\Support\Facades\DB::table($table)->where('id', $id)->first(['name']);
        return $row?->name;
    }

    /** Prioridade do HD (baixa|normal|alta|urgente) → nome da urgência no Movidesk. */
    private function priorityToUrgency(string $priority): ?string
    {
        return match (mb_strtolower(trim($priority))) {
            'baixa'   => 'Baixa',
            'normal'  => 'Média',
            'alta'    => 'Alta',
            'urgente' => 'Urgente',
            default   => null,
        };
    }

    /** Status Movidesk (base/text) para onde ESTE status HD empurra (linha is_outbound_default do de-para). */
    private function outboundStatus(int $companyId, int $statusId): ?array
    {
        $row = HelpDeskMovideskStatusMap::query()
            ->where('is_outbound_default', true)
            ->where('helpdesk_status_id', $statusId)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->first(['movidesk_base_status', 'movidesk_status_text', 'movidesk_justification']);
        if (!$row) return null;
        return ['base' => $row->movidesk_base_status, 'text' => $row->movidesk_status_text, 'justification' => $row->movidesk_justification];
    }

    /** Casa um nome (categoria/serviço) com o cadastro do HD da empresa, por nome (case-insensitive). */
    private function matchByName(string $table, int $companyId, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;
        $row = \Illuminate\Support\Facades\DB::table($table)
            ->when(\Illuminate\Support\Facades\Schema::hasColumn($table, 'company_id'), fn ($q) => $q->where('company_id', $companyId))
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first(['id']);
        return $row?->id ? (int) $row->id : null;
    }

    /** Urgência do Movidesk → prioridade do HD (baixa|normal|alta|urgente). */
    private function mapUrgency(string $urgency): ?string
    {
        return match (mb_strtolower(trim($urgency))) {
            'baixa'                       => 'baixa',
            'média', 'media', 'normal'    => 'normal',
            'alta'                        => 'alta',
            'urgente', 'crítica', 'critica' => 'urgente',
            default                       => null,
        };
    }

    /** Nível (N1/N2/N3) a partir do custom field 13485 do Movidesk. */
    private function extractNivel(array $md): ?string
    {
        foreach (($md['customFieldValues'] ?? []) as $f) {
            if ((int) ($f['customFieldId'] ?? 0) === 13485) {
                $v = $f['items'][0]['customFieldItem'] ?? null;
                return $v ? trim((string) $v) : null;
            }
        }
        return null;
    }

    /** [customFieldRuleId, line] do campo Nível (13485) — necessários no PATCH. Fallback: 21751 / 1. */
    private function nivelMeta(array $md): array
    {
        foreach (($md['customFieldValues'] ?? []) as $f) {
            if ((int) ($f['customFieldId'] ?? 0) === 13485) {
                return [(int) ($f['customFieldRuleId'] ?? 21751), (int) ($f['line'] ?? 1)];
            }
        }
        return [21751, 1];
    }

    /** Mapeia o responsável (owner do Movidesk) para o usuário do Minutor PELO E-MAIL (case-insensitive). */
    private function resolveAgentUser(?string $email): ?int
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') return null;
        $u = User::whereRaw('lower(email) = ?', [$email])->first(['id']);
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
        $a = $this->openingClientAction($md);
        return $a ? (string) ($a['htmlDescription'] ?? '') : null;
    }

    /** id da ação de ABERTURA (a mesma usada como descrição inicial) — para não duplicar como interação. */
    /**
     * Primeira ação NÃO-vazia escrita pelo CLIENTE (autor NÃO é agente). Se a abertura
     * foi feita por um AGENTE (ex. chamado aberto pelo consultor PARA o cliente), retorna
     * null → essa ação NÃO vira "descrição do cliente": entra como interação normal,
     * atribuída ao agente (igual ao Movidesk: "Flavio Augusto · Ação pública").
     */
    private function openingClientAction(array $md): ?array
    {
        foreach (($md['actions'] ?? []) as $a) {
            $b = (string) ($a['htmlDescription'] ?? '');
            if (trim(strip_tags($b)) === '') continue;
            if ($this->resolveAgentUser($a['createdBy']['email'] ?? null)) continue; // autor é agente → não é descrição do cliente
            return $a;
        }
        return null;
    }

    private function openingActionId(array $md): ?string
    {
        $a = $this->openingClientAction($md);
        return $a ? ((string) ($a['id'] ?? '') ?: null) : null;
    }

    // ── Cursor ────────────────────────────────────────────────────────────────
    /** Cursor POR EMPRESA: a principal (ERPSERV) mantém a chave histórica; as demais usam
     *  'movidesk_hd_import_since_c<id>' — sem isso o import da 2ª conta avançava o cursor da ERPSERV. */
    private function cursorKey(?int $companyId): string
    {
        return $this->teamFilterApplies($companyId) ? 'movidesk_hd_import_since' : "movidesk_hd_import_since_c{$companyId}";
    }

    private function cursor(?int $companyId = null): Carbon
    {
        $raw = SystemSetting::get($this->cursorKey($companyId), null);
        if ($raw) { try { return Carbon::parse($raw); } catch (\Throwable) {} }
        // 1ª execução: pega os últimos 7 dias (fila viva), não o histórico todo.
        return now()->subDays(7);
    }

    private function saveCursor(Carbon $ts, ?int $companyId = null): void
    {
        SystemSetting::set($this->cursorKey($companyId), $ts->toIso8601String(), 'string', 'movidesk', 'Cursor do import HD Movidesk (Promax)');
    }
}
