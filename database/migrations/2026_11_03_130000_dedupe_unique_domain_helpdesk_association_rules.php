<?php

use Illuminate\Database\Migrations\Migration;

/**
 * REVERTIDA (2026-11-03): a trava "1 domínio = 1 empresa" estava ERRADA — um domínio PODE
 * estar associado a mais de uma empresa (ERPSERV e BIZIFY). Esta migration originalmente
 * deduplicava domínios e criava um índice único em `domain`; ambos foram desfeitos.
 * Mantida como NO-OP para não reaplicar o dedupe/índice em bases novas ou na homolog.
 * (Em produção já havia executado; o índice foi dropado e as 12 regras restauradas à mão.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // no-op (ver cabeçalho)
    }

    public function down(): void
    {
        // no-op
    }
};
