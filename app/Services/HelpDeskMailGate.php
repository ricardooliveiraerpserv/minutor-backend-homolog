<?php

namespace App\Services;

use App\Models\HelpDeskTicket;
use App\Models\SystemSetting;

/**
 * TRAVA TEMPORÁRIA de homologação do tráfego de e-mail do Help Desk.
 *
 * Quando o flag `helpdesk_email_erpserv_only` está LIGADO, o HD só envia e-mail dos
 * chamados cujo CLIENTE é a ERPSERV; para os demais clientes (e para chamados sem
 * cliente identificado) NÃO envia nada. Serve para homologar o tráfego de e-mail em
 * produção sem disparar para clientes reais.
 *
 * Default DESLIGADO = comportamento normal (envia para todos). Reverter 100% = desligar
 * o flag no banco (sem deploy): SystemSetting::set('helpdesk_email_erpserv_only', false, 'boolean').
 */
class HelpDeskMailGate
{
    public const FLAG = 'helpdesk_email_erpserv_only';

    /** CNPJ da ERPSERV (identificação estável — o id do customer pode variar por ambiente). */
    private const ERPSERV_CGC = '23870826000107';

    public static function enabled(): bool
    {
        // Fail-safe: se não der pra ler a config (ex.: tabela ausente em outro schema),
        // assume trava DESLIGADA → não bloqueia o envio normal.
        try {
            return (bool) SystemSetting::get(self::FLAG, false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Chamado de ORIGEM MOVIDESK: source_system='movidesk'. A trava NÃO afeta esses — "fica da
     * mesma forma". OBS: chamados criados por e-mail (ingestão) têm source_system='email:graph'
     * e external_ref='gm:...' — NÃO são Movidesk; são criados no Minutor → respeitam a trava.
     */
    private static function isMovidesk(HelpDeskTicket $ticket): bool
    {
        return $ticket->source_system === 'movidesk';
    }

    /** true = pode enviar e-mail deste chamado; false = bloqueado pela trava. */
    public static function allows(?HelpDeskTicket $ticket): bool
    {
        if (!self::enabled()) {
            return true; // trava desligada → envia normal
        }
        if (!$ticket) {
            return false;
        }
        // Movidesk fica da mesma forma: segue o fluxo normal (envia), a trava não mexe nele.
        if (self::isMovidesk($ticket)) {
            return true;
        }
        // Chamado CRIADO DIRETO no Minutor: durante a homologação, só envia se o cliente for ERPSERV.
        $c = $ticket->loadMissing('customer')->customer;
        if (!$c) {
            return false; // nativo sem cliente identificado → não envia na homologação
        }
        return strcasecmp(trim((string) $c->name), 'ERPSERV') === 0
            || (string) $c->cgc === self::ERPSERV_CGC;
    }

    /**
     * Clientes que NÃO recebem nenhum e-mail do Help Desk (gatilhos/respostas/avisos) — ex.: VEDAMOTORS
     * (decisão Ricardo 14/11/2026). Avisos internos (responsável/equipe) seguem normais.
     * Setting `helpdesk_email_blocked_customers` = lista de customer_id (JSON ou "1,2").
     */
    public static function blockedCustomerIds(): array
    {
        try {
            $raw = SystemSetting::get('helpdesk_email_blocked_customers', []);
        } catch (\Throwable $e) {
            return [];
        }
        if (is_string($raw)) $raw = json_decode($raw, true) ?? array_filter(explode(',', $raw));
        return array_values(array_filter(array_map('intval', (array) $raw)));
    }

    /** true = o CLIENTE deste chamado não recebe e-mail. */
    public static function clientBlocked(?HelpDeskTicket $ticket): bool
    {
        if (!$ticket || !$ticket->customer_id) return false;
        return in_array((int) $ticket->customer_id, self::blockedCustomerIds(), true);
    }

    /** Remove de $emails os endereços do lado CLIENTE de um chamado bloqueado (solicitante, cópias, usuários do cliente). */
    public static function stripClientEmails(HelpDeskTicket $ticket, array $emails): array
    {
        if (!self::clientBlocked($ticket)) return $emails;
        $deny = array_map('mb_strtolower', array_filter(array_merge([(string) $ticket->requester_email], (array) $ticket->cc_emails)));
        $clientUsers = \App\Models\User::where('customer_id', $ticket->customer_id)->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->all();
        $deny = array_merge($deny, $clientUsers);
        return array_values(array_filter($emails, fn ($e) => !in_array(mb_strtolower(trim((string) $e)), $deny, true)));
    }
}
