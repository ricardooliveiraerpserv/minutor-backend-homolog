<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Limpa HTML e remove o título do ticket (subject) das observations
        // geradas pelo Movidesk antes da correção do buildObservation.
        // Usa DB::table() para evitar problemas de coluna 'id' ambígua com joins no PostgreSQL.

        // Parte 1: observations com HTML (começam com '<')
        $rows = DB::table('timesheets')
            ->join('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->where('timesheets.origin', 'webhook')
            ->whereNotNull('timesheets.observation')
            ->where('timesheets.observation', 'like', '<%')
            ->whereNull('timesheets.deleted_at')
            ->select('timesheets.id', 'timesheets.observation', 'movidesk_tickets.titulo')
            ->get();

        foreach ($rows as $row) {
            $plain = html_entity_decode(
                strip_tags($row->observation),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            $plain = preg_replace('/\s+/', ' ', trim($plain));

            if ($row->titulo) {
                $escapedTitulo = preg_quote(trim($row->titulo), '/');
                $plain = preg_replace('/^' . $escapedTitulo . '\s*/i', '', $plain);
                $plain = trim($plain);
            }

            DB::table('timesheets')->where('id', $row->id)->update(['observation' => $plain]);
        }

        // Parte 2: observations sem HTML mas com subject prefixado
        $rows2 = DB::table('timesheets')
            ->join('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->where('timesheets.origin', 'webhook')
            ->whereNotNull('timesheets.observation')
            ->where('timesheets.observation', 'not like', '<%')
            ->whereNull('timesheets.deleted_at')
            ->select('timesheets.id', 'timesheets.observation', 'movidesk_tickets.titulo')
            ->get();

        foreach ($rows2 as $row) {
            if (!$row->titulo) continue;

            $escapedTitulo = preg_quote(trim($row->titulo), '/');
            $plain = preg_replace('/^' . $escapedTitulo . '\s*/i', '', $row->observation);
            $plain = trim(preg_replace('/\s+/', ' ', $plain));

            if ($plain !== $row->observation) {
                DB::table('timesheets')->where('id', $row->id)->update(['observation' => $plain]);
            }
        }
    }

    public function down(): void
    {
        // Não é possível reverter limpeza de dados
    }
};
