<?php

use App\Models\Timesheet;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Limpa HTML e remove o título do ticket (subject) das observations
        // geradas pelo Movidesk antes da correção do buildObservation.
        // Formato antigo: "<h4>SUBJECT</h4><br/><p>HTML action</p>"
        // Após strip_tags: "SUBJECT texto da ação"
        // Após remoção do subject: "texto da ação"

        Timesheet::where('origin', 'webhook')
            ->whereNotNull('observation')
            ->where('observation', 'like', '<%')   // contém HTML
            ->join('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->select('timesheets.id', 'timesheets.observation', 'movidesk_tickets.titulo')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $plain = html_entity_decode(
                        strip_tags($row->observation),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    );
                    $plain = preg_replace('/\s+/', ' ', trim($plain));

                    // Remove o título do início se presente
                    if ($row->titulo) {
                        $escapedTitulo = preg_quote(trim($row->titulo), '/');
                        $plain = preg_replace('/^' . $escapedTitulo . '\s*/i', '', $plain);
                        $plain = trim($plain);
                    }

                    Timesheet::where('id', $row->id)->update(['observation' => $plain]);
                }
            });

        // Também corrige observations que não têm HTML mas têm o subject prefixado
        // (foram importadas com a versão que fazia strip_tags mas mantinha subject)
        Timesheet::where('origin', 'webhook')
            ->whereNotNull('observation')
            ->where('observation', 'not like', '<%')
            ->join('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->select('timesheets.id', 'timesheets.observation', 'movidesk_tickets.titulo')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    if (!$row->titulo) return;

                    $escapedTitulo = preg_quote(trim($row->titulo), '/');
                    $plain = preg_replace('/^' . $escapedTitulo . '\s*/i', '', $row->observation);
                    $plain = trim(preg_replace('/\s+/', ' ', $plain));

                    if ($plain !== $row->observation) {
                        Timesheet::where('id', $row->id)->update(['observation' => $plain]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Não é possível reverter limpeza de dados
    }
};
