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

    /** true = pode enviar e-mail deste chamado; false = bloqueado pela trava. */
    public static function allows(?HelpDeskTicket $ticket): bool
    {
        if (!self::enabled()) {
            return true; // trava desligada → envia normal
        }
        $c = $ticket?->loadMissing('customer')->customer;
        if (!$c) {
            return false; // sem cliente identificado → não envia durante a homologação
        }
        return strcasecmp(trim((string) $c->name), 'ERPSERV') === 0
            || (string) $c->cgc === self::ERPSERV_CGC;
    }
}
