<?php

namespace App\Services;

use App\Models\FollowUp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dispara cobrança de Follow Up pro n8n (mirror TimesheetN8nNotifier). Best-effort:
 * falha de webhook não interrompe o fluxo.
 */
class FollowUpN8nNotifier
{
    public function notify(FollowUp $f, string $kind): void
    {
        if (!config('services.n8n.followup_webhook_enabled')) return;
        if (app()->environment('testing')) return;

        $url = config('services.n8n.followup_webhook_url');
        if (!$url) return;

        $f->loadMissing(['responsible', 'project', 'customer']);
        $payload = [
            'kind'          => $kind, // d5|d3|d1|due|overdue
            'follow_up_id'  => (string) $f->id,
            'titulo'        => $f->title,
            'responsavel'   => optional($f->responsible)->name ?? '',
            'email'         => optional($f->responsible)->email ?? '',
            'projeto'       => optional($f->project)->name ?? '—',
            'empresa'       => optional($f->customer)->name ?? '—',
            'prazo'         => optional($f->due_date)->format('d/m/Y') ?? '',
            'prioridade'    => $f->priority,
            'dias_atraso'   => (string) ($f->days_overdue ?? 0),
        ];

        try {
            Http::timeout(5)->acceptJson()->asJson()->post($url, $payload)->throw();
        } catch (\Throwable $e) {
            Log::warning('FollowUp n8n webhook failed', ['follow_up_id' => $f->id, 'error' => $e->getMessage()]);
        }
    }
}
