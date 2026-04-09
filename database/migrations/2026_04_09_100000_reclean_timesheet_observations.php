<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Corrige registros onde o subject do ticket está prefixado na observation.
        // A migration anterior (2026_04_09_000000) tinha um bug: usava `return`
        // dentro do foreach ao invés de `continue`, abortando o chunk inteiro
        // ao encontrar o primeiro registro sem título.

        DB::table('timesheets')
            ->join('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->where('timesheets.origin', 'webhook')
            ->whereNotNull('timesheets.observation')
            ->whereNotNull('movidesk_tickets.titulo')
            ->where(DB::raw("LENGTH(movidesk_tickets.titulo)"), '>', 0)
            ->select('timesheets.id', 'timesheets.observation', 'movidesk_tickets.titulo')
            ->orderBy('timesheets.id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $titulo = trim($row->titulo);
                    if (!$titulo) {
                        continue;
                    }

                    // Strip HTML se ainda houver
                    $plain = html_entity_decode(
                        strip_tags($row->observation),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    );
                    $plain = trim(preg_replace('/\s+/', ' ', $plain));

                    // Remove o título do início se presente
                    $escaped = preg_quote($titulo, '/');
                    $cleaned = preg_replace('/^' . $escaped . '\s*/iu', '', $plain);
                    $cleaned = trim($cleaned);

                    if ($cleaned !== $row->observation) {
                        DB::table('timesheets')
                            ->where('id', $row->id)
                            ->update(['observation' => $cleaned]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Não é possível reverter limpeza de dados
    }
};
