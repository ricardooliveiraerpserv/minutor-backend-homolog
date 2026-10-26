<?php

namespace App\Http\Controllers;

use App\Models\WorkflowEmailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Envios de e-mail dos workflows (tela "Envios por rotina"). Resumo agrupado por rotina
 * + lista filtrável. Fonte: log central `workflow_email_logs` (alimentado pelo WorkflowMailer
 * e pelo alerta de consumo).
 */
class WorkflowEmailLogController extends Controller
{
    private function ensureAdmin(Request $request): void
    {
        abort_unless(optional($request->user())->isAdmin(), 403, 'Apenas administradores.');
    }

    /** GET /workflow-email-logs/summary — contagem por rotina (total, enviados, falhas, sem destinatário, último). */
    public function summary(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $rows = WorkflowEmailLog::query()
            ->selectRaw("routine_key,
                MAX(domain) as domain, MAX(label) as label,
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE status = 'sent') as sent,
                COUNT(*) FILTER (WHERE status = 'failed') as failed,
                COUNT(*) FILTER (WHERE status = 'no_recipient') as no_recipient,
                MAX(sent_at) as last_sent_at")
            ->groupBy('routine_key')
            ->orderByRaw('MAX(sent_at) DESC NULLS LAST')
            ->get()
            ->map(fn ($r) => [
                'routine_key'  => $r->routine_key,
                'domain'       => $r->domain,
                'label'        => $r->label ?: $r->routine_key,
                'total'        => (int) $r->total,
                'sent'         => (int) $r->sent,
                'failed'       => (int) $r->failed,
                'no_recipient' => (int) $r->no_recipient,
                'last_sent_at' => $r->last_sent_at ? \Illuminate\Support\Carbon::parse($r->last_sent_at)->toIso8601String() : null,
            ]);

        return response()->json(['data' => $rows]);
    }

    /** GET /workflow-email-logs — lista filtrável (routine, status, período, busca de destinatário/cliente). */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $q = WorkflowEmailLog::query()
            ->with(['customer:id,name', 'project:id,code,name'])
            ->when($request->filled('routine'), fn ($qq) => $qq->where('routine_key', $request->input('routine')))
            ->when($request->filled('status'),  fn ($qq) => $qq->where('status', $request->input('status')))
            ->when($request->filled('from'),    fn ($qq) => $qq->whereDate('sent_at', '>=', $request->input('from')))
            ->when($request->filled('until'),   fn ($qq) => $qq->whereDate('sent_at', '<=', $request->input('until')))
            ->when($request->filled('search'), function ($qq) use ($request) {
                $s = '%' . strtolower($request->input('search')) . '%';
                $qq->where(function ($w) use ($s) {
                    $w->whereRaw('LOWER(recipients_to::text) LIKE ?', [$s])
                        ->orWhereRaw('LOWER(recipients_cc::text) LIKE ?', [$s])
                        ->orWhereRaw('LOWER(COALESCE(subject, \'\')) LIKE ?', [$s])
                        ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$s]));
                });
            })
            ->orderByDesc('sent_at')->orderByDesc('id');

        $page = $q->paginate(min((int) $request->input('pageSize', 30), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (WorkflowEmailLog $l) => [
                'id'          => $l->id,
                'routine_key' => $l->routine_key,
                'label'       => $l->label ?: $l->routine_key,
                'domain'      => $l->domain,
                'subject'     => $l->subject,
                'status'      => $l->status,
                'error'       => $l->error,
                'to'          => $l->recipients_to ?? [],
                'cc'          => $l->recipients_cc ?? [],
                'customer'    => optional($l->customer)->name,
                'project'     => $l->project ? trim(($l->project->code ? $l->project->code . ' · ' : '') . $l->project->name) : null,
                'sent_at'     => optional($l->sent_at)->toIso8601String(),
            ]),
            'hasNext' => $page->hasMorePages(),
            'total'   => $page->total(),
        ]);
    }
}
