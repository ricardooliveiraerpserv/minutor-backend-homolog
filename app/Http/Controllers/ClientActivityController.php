<?php

namespace App\Http\Controllers;

use App\Http\Controllers\ClientProjectController;
use App\Models\StageActivityEvent;
use App\Models\StageDelivery;
use App\Services\DeliveryApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acesso contextual do cliente a atividades onde está pontualmente envolvido.
 *
 * Rotas separadas das internas (`/activities/{id}/*`) para manter o
 * `BlockCliente` nas operacionais. Aqui validamos manualmente que o
 * `auth()->id() === delivery.client_user_id` e `client_involved=true`.
 *
 * Cliente NÃO acessa: outras atividades, board, etapas, projetos completos,
 * horas internas, riscos, capacidade.
 */
class ClientActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isCliente()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $items = StageDelivery::query()
            ->where(function ($q) use ($user) {
                $q->where(fn ($w) => $w->where('client_involved', true)->where('client_user_id', $user->id))
                  ->orWhere('responsible_user_id', $user->id);
            })
            ->with([
                'stage:id,project_id,name',
                'stage.project:id,name,customer_id',
                'responsible:id,name,email',
            ])
            ->orderByDesc('due_date')
            ->get()
            ->map(fn ($d) => $this->summarize($d));

        return response()->json(['items' => $items]);
    }

    public function show(StageDelivery $delivery, Request $request): JsonResponse
    {
        if (($err = $this->ensureAccess($delivery, $request)) !== null) return $err;

        $delivery->load(['responsible:id,name,email', 'stage:id,project_id,name', 'stage.project:id,name,customer_id', 'approvalDecider:id,name']);

        return response()->json($this->summarize($delivery, full: true));
    }

    public function timeline(StageDelivery $delivery, Request $request): JsonResponse
    {
        if (($err = $this->ensureAccess($delivery, $request)) !== null) return $err;

        $user = $request->user();

        // Eventos de status/aprovação são sempre visíveis; comentário só se marcado
        // para o público "cliente" (visibleTo). Admin/coord veem tudo (não é o caso aqui).
        // Cliente NÃO vê o "log" de status (criação/movimentação). Só a conversa
        // (comentários direcionados a ele) + as aprovações/rejeições.
        $events = StageActivityEvent::query()
            ->where('delivery_id', $delivery->id)
            ->whereIn('type', [
                StageActivityEvent::TYPE_APPROVAL_APPROVED,
                StageActivityEvent::TYPE_APPROVAL_REJECTED,
                StageActivityEvent::TYPE_COMMENT,
            ])
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->filter(fn ($e) => $e->visibleTo($user))
            ->values();

        return response()->json(['items' => $events, 'can_converse' => true]);
    }

    public function storeComment(StageDelivery $delivery, Request $request): JsonResponse
    {
        if (($err = $this->ensureAccess($delivery, $request)) !== null) return $err;

        $data = $request->validate([
            'text'       => 'nullable|string|max:5000',
            'attachment' => 'nullable|file|max:20480|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
        ]);

        $text = trim((string) ($data['text'] ?? ''));
        $hasAttach = $request->hasFile('attachment');

        if ($text === '' && !$hasAttach) {
            return response()->json(['message' => 'Comentário precisa de texto ou anexo.'], 422);
        }

        $attachmentData = [];
        if ($hasAttach) {
            $file = $request->file('attachment');
            $path = $file->store("stage-attachments/{$delivery->stage_id}", 'public');
            $attachmentData = [
                'attachment_path'          => $path,
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime'          => $file->getMimeType(),
                'attachment_size'          => $file->getSize(),
            ];
        }

        // Mensagem do cliente: visível pra ele mesmo + equipe (consultor); admin/coord sempre.
        $event = StageActivityEvent::create(array_merge([
            'stage_id'      => $delivery->stage_id,
            'delivery_id'   => $delivery->id,
            'actor_user_id' => $request->user()->id,
            'type'          => StageActivityEvent::TYPE_COMMENT,
            'audiences'     => [StageActivityEvent::AUDIENCE_CLIENTE, StageActivityEvent::AUDIENCE_CONSULTOR],
            'payload'       => array_filter([
                'text'         => $text !== '' ? $text : null,
                'from_client'  => true,
            ]),
        ], $attachmentData));

        return response()->json($event->load('actor:id,name,email'), 201);
    }

    /**
     * Aprova a atividade (cliente envolvido). Move pra Homologação.
     */
    public function approve(StageDelivery $delivery, Request $request, DeliveryApprovalService $service): JsonResponse
    {
        if (($err = $this->ensureApprovalAccess($delivery, $request)) !== null) return $err;
        if (($err = $this->ensurePending($delivery)) !== null) return $err;

        $data = $request->validate(['note' => 'nullable|string|max:5000']);
        $service->decide($delivery, $request->user(), true, $data['note'] ?? null, fromClient: true);

        return response()->json($this->summarize($delivery->fresh()->load(['responsible:id,name,email', 'stage:id,project_id,name', 'stage.project:id,name', 'approvalDecider:id,name']), full: true));
    }

    /**
     * Reprova / solicita ajustes (cliente envolvido). Volta pra Em andamento.
     */
    public function reject(StageDelivery $delivery, Request $request, DeliveryApprovalService $service): JsonResponse
    {
        if (($err = $this->ensureApprovalAccess($delivery, $request)) !== null) return $err;
        if (($err = $this->ensurePending($delivery)) !== null) return $err;

        $data = $request->validate(['note' => 'nullable|string|max:5000']);
        $service->decide($delivery, $request->user(), false, $data['note'] ?? null, fromClient: true);

        return response()->json($this->summarize($delivery->fresh()->load(['responsible:id,name,email', 'stage:id,project_id,name', 'stage.project:id,name', 'approvalDecider:id,name']), full: true));
    }

    private function ensurePending(StageDelivery $delivery): ?JsonResponse
    {
        if ($delivery->approval_status !== StageDelivery::APPROVAL_PENDING) {
            return response()->json(['message' => 'Esta atividade não está aguardando sua aprovação.'], 422);
        }
        return null;
    }

    /**
     * View limitada da atividade para o cliente.
     * NÃO inclui: hours_planned interno, alocações, predecessor, riscos, health.
     */
    private function summarize(StageDelivery $d, bool $full = false): array
    {
        $base = [
            'id'                => $d->id,
            'title'             => $d->title,
            'description'       => $d->description,
            'status'            => $d->status,
            'planned_start_at'  => $d->planned_start_at?->toDateString(),
            'due_date'          => $d->due_date?->toDateString(),
            'completed_at'      => $d->completed_at?->toIso8601String(),
            'responsible_name'  => $d->responsible?->name,
            'stage_name'        => $d->stage?->name,
            'project_name'      => $d->stage?->project?->name,
            'approval_status'   => $d->approval_status,
            'approval_note'     => $d->approval_note,
            'approval_decided_at' => $d->approval_decided_at?->toIso8601String(),
            'approval_decided_by_name' => $d->approvalDecider?->name,
        ];

        if ($full) {
            $u = request()->user();
            $uid = (int) $u?->id;
            $project = $d->stage?->project;
            $sameCustomer = $project && (int) $u?->customer_id === (int) $project->customer_id;
            $base['client_involved'] = (bool) $d->client_involved;
            $base['is_responsible'] = (int) $d->responsible_user_id === $uid;
            // Cliente do MESMO customer do projeto pode comentar em qualquer atividade (conversa por projeto, sem horas).
            $base['can_comment'] = (bool) $sameCustomer;
            // Aprovar continua restrito ao aprovador designado (envolvido/waiting_client sem cliente específico).
            $base['can_approve'] = $d->approval_status === StageDelivery::APPROVAL_PENDING
                && $project && ClientProjectController::canApprove($d, $uid, $project);
        }

        return $base;
    }

    /** Ver/conversar: cliente do MESMO customer do projeto pode abrir qualquer atividade (conversa por projeto). */
    private function ensureAccess(StageDelivery $delivery, Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }
        if (!$user->isCliente()) {
            return response()->json(['message' => 'Endpoint exclusivo do perfil cliente.'], 403);
        }
        $delivery->loadMissing('stage.project');
        $project = $delivery->stage?->project;
        if ($project && (int) $user->customer_id === (int) $project->customer_id) return null;
        // Fallback (compat): envolvido/responsável OU aprovador designado.
        $uid = (int) $user->id;
        if (ClientProjectController::canOpen($delivery, $uid)) return null;
        if ($project && ClientProjectController::canApprove($delivery, $uid, $project)) return null;
        return response()->json(['message' => 'Você não participa deste projeto.'], 403);
    }

    /** Aprovar/reprovar: restrito ao aprovador designado (NÃO basta ser do mesmo customer). */
    private function ensureApprovalAccess(StageDelivery $delivery, Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }
        if (!$user->isCliente()) {
            return response()->json(['message' => 'Endpoint exclusivo do perfil cliente.'], 403);
        }
        $uid = (int) $user->id;
        if (ClientProjectController::canOpen($delivery, $uid)) return null;
        $delivery->loadMissing('stage.project');
        $project = $delivery->stage?->project;
        if ($project && ClientProjectController::canApprove($delivery, $uid, $project)) return null;
        return response()->json(['message' => 'Você não pode aprovar esta atividade.'], 403);
    }

    /** Conversa e anexos: SÓ se o cliente for o responsável da atividade. */
    private function isResponsible(StageDelivery $delivery, Request $request): bool
    {
        return (int) $delivery->responsible_user_id === (int) $request->user()?->id;
    }
}
