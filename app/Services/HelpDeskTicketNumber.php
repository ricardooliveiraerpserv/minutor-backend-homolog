<?php

namespace App\Services;

use App\Models\HelpDeskCommTemplate;
use Illuminate\Support\Facades\DB;

/** Gera o número do chamado no formato configurado (prefixo + sequência com zero-padding). */
class HelpDeskTicketNumber
{
    /** Próximo número, incrementando a sequência de forma atômica. */
    public static function next(): string
    {
        return DB::transaction(function () {
            $cfg = HelpDeskCommTemplate::lockForUpdate()->first() ?? HelpDeskCommTemplate::current();
            $seq = (int) $cfg->ticket_sequence + 1;
            $cfg->update(['ticket_sequence' => $seq]);
            return self::format($seq, $cfg);
        });
    }

    /** Formata um valor de sequência com a config atual (sem incrementar). */
    public static function format(int $seq, ?HelpDeskCommTemplate $cfg = null): string
    {
        $cfg = $cfg ?? HelpDeskCommTemplate::current();
        $pad = max(1, (int) $cfg->ticket_padding);
        return (string) $cfg->ticket_prefix . str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);
    }
}
