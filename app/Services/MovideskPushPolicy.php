<?php

namespace App\Services;

use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\SystemSetting;
use Carbon\Carbon;

/**
 * POLÍTICA DE SAÍDA Minutor → Movidesk (go-live Promax, decisão Ricardo 2026-11-10).
 *
 * No go-live, a ERPSERV atende 100% pelo Minutor: e-mail de suporte entra direto no Minutor e só os
 * chamados da PROMAX continuam nascendo no Movidesk (espelhados no Minutor). Responder no Minutor um
 * chamado Promax GRAVA a resposta no Movidesk — é o Movidesk que avisa o cliente.
 *
 * ⚠️ As equipes Promax Bardahl / Manutenção Promax são o PESSOAL DA PROMAX. Enquanto o chamado está
 * com elas a ERPSERV não atende (o import nem traz — modo exclude). Só integra quando o responsável
 * é EQUIPE DA ERPSERV (a Promax transferiu p/ nós). Regra do Ricardo, reforçada em 2026-11-10.
 *
 * Travas (todas precisam passar):
 *  1. `movidesk_hd_push_comments_enabled` ligado (padrão DESLIGADO);
 *  2. SÓ a conta Movidesk da ERPSERV (origin_company_id = empresa principal). BIZIFY NUNCA;
 *  3. SÓ chamados com responsável em EQUIPE DA ERPSERV: equipe preenchida e FORA da lista Promax
 *     (`movidesk_hd_blocked_teams`). Sem equipe ou em equipe Promax → não escreve;
 *  4. SÓ comentários criados a partir de `movidesk_hd_push_since` (vazio = nada sai). Impede
 *     reenviar o histórico (~200 mil comentários migrados desde 2017 sem external_action_id);
 *  5. SÓ respostas ao cliente; nota interna só se `movidesk_hd_push_internal` ligado.
 *
 * Com o envio ligado, o Minutor NÃO manda e-mail ao cliente desses chamados (senão chega em dobro:
 * pelo Movidesk e pelo Minutor, e a resposta do cliente ao e-mail do Minutor não voltaria ao Movidesk).
 */
class MovideskPushPolicy
{
    public const DEFAULT_TEAMS = ['Promax Bardahl', 'Manutenção Promax'];

    public static function enabled(): bool
    {
        return (bool) SystemSetting::get('movidesk_hd_push_comments_enabled', false) && self::since() !== null;
    }

    /** Corte: só comentários criados a partir daqui vão ao Movidesk. null = nenhum. */
    public static function since(): ?Carbon
    {
        $v = trim((string) SystemSetting::get('movidesk_hd_push_since', ''));
        if ($v === '') return null;
        try { return Carbon::parse($v); } catch (\Throwable) { return null; }
    }

    public static function pushInternal(): bool
    {
        return (bool) SystemSetting::get('movidesk_hd_push_internal', false);
    }

    /** Empresa da conta Movidesk principal (ERPSERV). */
    public static function primaryCompanyId(): int
    {
        $c = (int) SystemSetting::get('movidesk_hd_import_company_id', 0);
        if ($c > 0) return $c;
        $def = HelpDeskStatus::query()->where('is_default', true)->orderBy('company_id')->first();
        return (int) ($def->company_id ?? 1);
    }

    /** @return string[] equipes Promax (minúsculas). */
    public static function promaxTeams(): array
    {
        $raw = SystemSetting::get('movidesk_hd_blocked_teams', self::DEFAULT_TEAMS);
        if (is_string($raw)) { $raw = json_decode($raw, true) ?: [$raw]; }
        return array_values(array_filter(array_map(fn ($t) => mb_strtolower(trim((string) $t)), (array) $raw)));
    }

    public static function isPromaxTeam(?string $team): bool
    {
        $t = mb_strtolower(trim((string) $team));
        return $t !== '' && in_array($t, self::promaxTeams(), true);
    }

    /** Responsável é EQUIPE DA ERPSERV? (equipe preenchida e que NÃO é da Promax). */
    public static function isErpservTeam(?string $team): bool
    {
        return trim((string) $team) !== '' && !self::isPromaxTeam($team);
    }

    /** Conta Movidesk de origem do chamado é a da ERPSERV? (BIZIFY e demais: nunca escreve.) */
    public static function isPrimaryAccount(HelpDeskTicket $ticket): bool
    {
        $origin = (int) ($ticket->origin_company_id ?: $ticket->company_id);
        return $origin === self::primaryCompanyId();
    }

    /**
     * Pode escrever no Movidesk deste chamado? $ownerTeam = equipe atual no Movidesk (quando já se
     * tem o ticket da API); sem ela usa a guardada no import (external_owner_team).
     */
    public static function ticketAllowed(HelpDeskTicket $ticket, ?string $ownerTeam = null): bool
    {
        if ($ticket->source_system !== 'movidesk' || !$ticket->external_ref) return false;
        if (!self::isPrimaryAccount($ticket)) return false;
        return self::isErpservTeam($ownerTeam ?? $ticket->external_owner_team);
    }

    /** Comentário elegível (corte de data + visibilidade). */
    public static function commentAllowed(HelpDeskTicketComment $c): bool
    {
        $since = self::since();
        if (!$since || !$c->created_at || $c->created_at->lt($since)) return false;
        return $c->visibility === 'customer' || self::pushInternal();
    }

    /** Aplica corte + visibilidade numa query de comentários pendentes. */
    public static function scopeComments($q)
    {
        $since = self::since();
        $q->where('created_at', '>=', $since ?: now()->addYears(100));
        if (!self::pushInternal()) $q->where('visibility', 'customer');
        return $q;
    }

    /**
     * O cliente deste chamado é atendido PELO MOVIDESK (envio ligado + conta ERPSERV + equipe ERPSERV)?
     * true → o Minutor não manda e-mail ao cliente (o Movidesk avisa).
     */
    public static function deliversViaMovidesk(?HelpDeskTicket $ticket): bool
    {
        if (!$ticket) return false;
        try {
            return self::enabled() && self::ticketAllowed($ticket);
        } catch (\Throwable) {
            return false;
        }
    }
}
