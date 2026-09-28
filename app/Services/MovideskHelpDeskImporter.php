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
                // Só age para ORGS VINCULADAS no Help Desk (hd_customer_id). Org sem vínculo → ignora.
                $org = $this->orgFor($this->extractOrgId($full));
                if ($this->requireLinkedOrg() && !($org && $org->hd_customer_id)) {
                    $stats['skipped']++; $this->bumpCursor($lite, $maxUpdate); continue;
                }
                // "A partir do vínculo apenas": ignora chamados CRIADOS antes de a org ser vinculada.
                if ($org && $org->hd_linked_at && $this->createdBeforeLink($full, $org)) {
                    $stats['skipped']++; $this->bumpCursor($lite, $maxUpdate); continue;
                }
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
                // Número do chamado = EXATAMENTE o número do Movidesk (não gera sequência própria).
                'ticket_number' => $externalId,
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

        // RESPONSÁVEL: sincroniza o assignee com o owner do Movidesk (mapeado por e-mail → usuário).
        // Só reaplica quando o responsável MUDOU no Movidesk — não desfaz reatribuição feita no Minutor.
        $ownerEmail = mb_strtolower(trim((string) ($md['owner']['email'] ?? '')));
        if ($created || mb_strtolower(trim((string) $ticket->external_owner_email)) !== $ownerEmail) {
            $ticket->assignee_id = $ownerEmail !== '' ? $this->resolveAgentUser($ownerEmail) : null;
            $ticket->external_owner_email = $ownerEmail ?: null;
        }

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
        else { if ($m['setHd']) { $ticket->category_id = $m['hd'] ? $this->matchByName('helpdesk_categories', $companyId, $m['hd']) : null; } $ticket->external_category = $m['shadow']; }

        // Serviço — ENTRADA apenas: o Movidesk aceita o PATCH (200) mas IGNORA a troca de serviço
        // (valida pelo contexto do chamado). Então adotamos o serviço do Movidesk quando ele muda.
        $mdSvc = trim((string) ($md['serviceFirstLevel'] ?? ($md['serviceFull'][0] ?? ($md['serviceSecondLevel'] ?? '')))) ?: null;
        if ($created || $ticket->external_service !== $mdSvc) {
            $sid = $mdSvc ? $this->matchByName('helpdesk_services', $companyId, $mdSvc) : null;
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
        $hdOut = $ticket->status_id ? $this->outboundStatus($companyId, (int) $ticket->status_id) : null;
        $hdSig = $hdOut ? trim(($hdOut['base'] ?? '') . '|' . ($hdOut['text'] ?? '')) : null;
        if ($created) {
            $sid = HelpDeskMovideskStatusMap::resolveInbound($companyId, $base, $statusText)
                ?? HelpDeskMovideskStatusMap::resolveInbound(null, $base, $statusText)
                ?? optional(HelpDeskStatus::query()->where('company_id', $companyId)->where('is_default', true)->first())->id;
            if ($sid) { $ticket->status_id = $sid; }
            $ticket->external_status = $mdSig;
        } elseif ($hdSig !== null && $hdSig !== $ticket->external_status) {
            // Minutor mudou o status. Só empurra se houver justificativa (senão o Movidesk rejeita).
            if (!empty($hdOut['justification']) && !empty($hdOut['text'])) {
                $patch['status'] = $hdOut['text'];
                if (!empty($hdOut['base'])) { $patch['baseStatus'] = $hdOut['base']; }
                $patch['justification'] = $hdOut['justification'];
                $pushShadow['external_status'] = $hdSig;
            }
            // sem justificativa: não empurra e não puxa (mantém o status do Minutor).
        } elseif ($mdSig !== $ticket->external_status) {
            // Só o Movidesk mudou → puxa p/ o Minutor.
            $sid = HelpDeskMovideskStatusMap::resolveInbound($companyId, $base, $statusText)
                ?? HelpDeskMovideskStatusMap::resolveInbound(null, $base, $statusText);
            if ($sid) { $ticket->status_id = $sid; }
            $ticket->external_status = $mdSig;
        }

        $ticket->external_synced_at = now();
        // company_id não é fillable — garante empresa mesmo em update.
        if ((int) $ticket->company_id !== $companyId) { $ticket->forceFill(['company_id' => $companyId]); }
        $ticket->save();

        // WRITE-BACK: empurra Categoria/Urgência/Nível (e Status quando há justificativa) p/ o Movidesk
        // (só metadado, nunca interação). A sombra só avança se o PATCH tiver sucesso (falha é re-tentada).
        if ($patch && $this->writebackEnabled()) {
            if ($this->movidesk->patchTicket((int) $externalId, $patch) && $pushShadow) {
                foreach ($pushShadow as $col => $val) { $ticket->$col = $val; }
                $ticket->save();
            }
        }

        // A ação de ABERTURA vira a "descrição inicial" do chamado — NÃO deve virar também uma
        // interação (senão duplica: descrição + comentário #1 iguais).
        $comments = $this->importActions($ticket, $md['actions'] ?? [], $this->openingActionId($md));

        // SAÍDA de INTERAÇÕES: empurra p/ o Movidesk os comentários NATIVOS do Minutor ainda não enviados.
        if ($this->pushCommentsEnabled()) {
            $this->pushComments($ticket, (int) $externalId, $md['actions'] ?? []);
        }

        return ['created' => $created, 'comments' => $comments];
    }

    /** Empurra comentários nativos do Minutor (não importados) como ações no Movidesk. */
    private function pushComments(HelpDeskTicket $ticket, int $externalId, array $mdActions): void
    {
        // Comentários criados NO MINUTOR (source null), não-sistema, ainda não enviados (sem action id).
        $pending = HelpDeskTicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->whereNull('source')
            ->where('is_system', false)
            ->whereNull('external_action_id')
            ->orderBy('id')
            ->get(['id', 'body', 'visibility']);
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
            $ok = $this->movidesk->addActions($externalId, [['type' => $type, 'description' => $text]]);
            if ($ok) {
                $c->external_action_id = (string) (++$maxId); // id sequencial da ação recém-criada
                $c->save();
            } else {
                break; // falhou → tenta de novo no próximo ciclo (mantém ordem)
            }
        }
    }

    /** Converte HTML do comentário em texto (preserva quebras) para enviar como `description`.
     * Movidesk aceita só texto na escrita (htmlDescription é read-only) — então tratamos também
     * TABELAS (assinaturas montadas em <table>): célula → espaço, linha/tabela/bloco → quebra,
     * senão a assinatura sai como texto corrido grudado. */
    private function htmlToText(string $html): string
    {
        $s = $html;
        // Células de tabela viram espaço (mantém itens da mesma linha separados).
        $s = preg_replace('/<\s*\/\s*(td|th)\s*>/i', ' ', $s);
        // Quebras explícitas.
        $s = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $s);
        // Fim de linha de tabela / tabela / blocos → quebra de linha.
        $s = preg_replace('/<\s*\/\s*(p|div|li|tr|table|h[1-6]|blockquote|section|header|footer)\s*>/i', "\n", $s);
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace("\xC2\xA0", ' ', $s);          // &nbsp; → espaço normal
        $s = preg_replace('/[ \t]{2,}/', ' ', $s);       // colapsa espaços repetidos
        $s = preg_replace('/[ \t]*\n[ \t]*/', "\n", $s); // limpa espaços em volta das quebras
        $s = preg_replace("/\n{3,}/", "\n\n", $s);       // no máximo 1 linha em branco
        return trim($s);
    }

    /** Importa as ações do Movidesk como interações do HD (dedup por external_action_id). */
    private function importActions(HelpDeskTicket $ticket, array $actions, ?string $skipActionId = null): int
    {
        if (!$actions) return 0;

        // Dedup por external_action_id em QUALQUER origem — inclui comentários NATIVOS que já
        // empurramos p/ o Movidesk (guardam o id da ação criada) → evita reimportar (eco).
        $existing = HelpDeskTicketComment::withTrashed()
            ->where('ticket_id', $ticket->id)
            ->whereNotNull('external_action_id')
            ->pluck('external_action_id')
            ->filter()->map(fn ($v) => (string) $v)->flip();

        $n = 0;
        foreach ($actions as $a) {
            $aid = (string) ($a['id'] ?? '');
            if ($aid === '' || $existing->has($aid)) continue;
            if ($skipActionId !== null && $aid === $skipActionId) continue; // abertura = descrição, não duplicar

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
                // action.id do Movidesk é sequencial POR ticket (1,2,3…) — a chave precisa do id do ticket.
                'idempotency_key'    => 'movidesk-' . $ticket->external_ref . '-a' . $aid,
            ]);
            $existing->put($aid, true);
            $n++;
        }
        return $n;
    }

    // ── Resolução de entidades ────────────────────────────────────────────────
    /** Cliente Minutor a partir do VÍNCULO DEDICADO do HD (hd_customer_id). Null se a org não estiver vinculada. */
    private function resolveCustomer($orgId): ?int
    {
        $org = $this->orgFor($orgId);
        return $org && $org->hd_customer_id ? (int) $org->hd_customer_id : null;
    }

    /** Organização Movidesk (com hd_customer_id/hd_linked_at) a partir do id do Movidesk. */
    private function orgFor($orgId): ?MovideskOrganization
    {
        if (!$orgId) return null;
        return MovideskOrganization::where('movidesk_id', (string) $orgId)->first();
    }

    /** id da organização Movidesk do chamado (1º client). */
    private function extractOrgId(array $md)
    {
        return $md['clients'][0]['organization']['id'] ?? null;
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

    /** Empurrar INTERAÇÕES (comentários) do Minutor como ações no Movidesk? */
    private function pushCommentsEnabled(): bool
    {
        return (bool) SystemSetting::get('movidesk_hd_push_comments_enabled', false);
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
        foreach (($md['actions'] ?? []) as $a) {
            $b = (string) ($a['htmlDescription'] ?? '');
            if (trim(strip_tags($b)) !== '') return $b;
        }
        return null;
    }

    /** id da ação de ABERTURA (a mesma usada como descrição inicial) — para não duplicar como interação. */
    private function openingActionId(array $md): ?string
    {
        foreach (($md['actions'] ?? []) as $a) {
            $b = (string) ($a['htmlDescription'] ?? '');
            if (trim(strip_tags($b)) !== '') return (string) ($a['id'] ?? '') ?: null;
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
