<?php

namespace App\Http\Controllers;

use App\Models\FollowUp;
use App\Models\FollowUpEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acesso do CLIENTE aos Follow Ups onde está envolvido (client_involved).
 *
 * O Follow Up é a unidade de comunicação/acompanhamento. O cliente envolvido
 * pode ver, comentar, anexar e concluir; e abrir um RESUMO da atividade
 * vinculada (sem informações internas do projeto).
 */
class ClientFollowUpController extends Controller
{
    /** Todos os acompanhamentos do cliente (cross-projeto) — "Meus Acompanhamentos". */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isCliente()) {
            return response()->json(['message' => 'Endpoint exclusivo do perfil cliente.'], 403);
        }

        $items = FollowUp::where('client_involved', true)
            ->where(function ($q) use ($user) {
                $q->where('client_user_id', $user->id)->orWhere('client_email', $user->email);
            })
            ->where('status', '!=', FollowUp::STATUS_CANCELLED)
            ->with(['project:id,name', 'delivery:id,title', 'responsible:id,name', 'createdBy:id,name'])
            ->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
            ->orderByRaw('due_date asc nulls last')
            ->get(['id', 'title', 'description', 'status', 'due_date', 'responsible_user_id', 'project_id', 'delivery_id', 'created_by', 'created_at'])
            ->map(fn ($f) => [
                'id'             => $f->id,
                'title'          => $f->title,
                'status'         => $f->status,
                'due_date'       => $f->due_date?->toDateString(),
                'responsible'    => $f->responsible?->name,
                'author'         => $f->createdBy?->name,
                'project_id'     => $f->project_id,
                'project_name'   => $f->project?->name,
                'delivery_id'    => $f->delivery_id,
                'delivery_title' => $f->delivery?->title,
            ]);

        return response()->json(['items' => $items]);
    }

    public function show(FollowUp $followUp, Request $request): JsonResponse
    {
        if (($err = $this->ensure($followUp, $request)) !== null) return $err;

        $followUp->loadMissing(['responsible:id,name', 'delivery:id,title,stage_id', 'delivery.stage:id,name']);

        return response()->json($this->summarize($followUp));
    }

    public function timeline(FollowUp $followUp, Request $request): JsonResponse
    {
        if (($err = $this->ensure($followUp, $request)) !== null) return $err;

        $items = $followUp->events()->with('actor:id,name,email')->limit(200)->get();
        return response()->json(['items' => $items]);
    }

    public function storeComment(FollowUp $followUp, Request $request): JsonResponse
    {
        if (($err = $this->ensure($followUp, $request)) !== null) return $err;

        $data = $request->validate([
            'text'       => 'nullable|string|max:5000',
            'attachment' => 'nullable|file|max:20480|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
        ]);
        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '' && !$request->hasFile('attachment')) {
            return response()->json(['message' => 'Comentário precisa de texto ou anexo.'], 422);
        }

        $att = [];
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $att = [
                'attachment_path'          => $file->store("follow-up-attachments/{$followUp->id}", 'public'),
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime'          => $file->getMimeType(),
                'attachment_size'          => $file->getSize(),
            ];
        }

        $event = FollowUpEvent::create(array_merge([
            'follow_up_id'  => $followUp->id,
            'actor_user_id' => $request->user()->id,
            'type'          => FollowUpEvent::TYPE_COMMENT,
            'payload'       => array_filter(['text' => $text !== '' ? $text : null, 'from_client' => true]),
        ], $att));

        return response()->json($event->load('actor:id,name,email'), 201);
    }

    /** Cliente conclui o Follow Up (status → completed). */
    public function complete(FollowUp $followUp, Request $request): JsonResponse
    {
        if (($err = $this->ensure($followUp, $request)) !== null) return $err;

        if ($followUp->status === FollowUp::STATUS_COMPLETED) {
            return response()->json(['message' => 'Follow Up já concluído.'], 422);
        }
        // Observer registra o evento de conclusão.
        $followUp->update(['status' => FollowUp::STATUS_COMPLETED]);

        return response()->json($this->summarize($followUp->fresh()->load(['responsible:id,name', 'delivery:id,title,stage_id', 'delivery.stage:id,name'])));
    }

    /** Resumo READ-ONLY da atividade vinculada (sem horas/valores/internos). */
    public function activitySummary(FollowUp $followUp, Request $request): JsonResponse
    {
        if (($err = $this->ensure($followUp, $request)) !== null) return $err;

        $d = $followUp->delivery;
        if (!$d) return response()->json(['message' => 'Este Follow Up não está vinculado a uma atividade.'], 404);

        $d->loadMissing(['stage:id,name,project_id', 'stage.project:id,name', 'responsible:id,name']);

        return response()->json([
            'id'               => $d->id,
            'title'            => $d->title,
            'description'      => $d->description,
            'status'           => $d->status,
            'planned_start_at' => $d->planned_start_at?->toDateString(),
            'due_date'         => $d->due_date?->toDateString(),
            'completed_at'     => $d->completed_at?->toDateString(),
            'responsible_name' => $d->responsible?->name,
            'stage_name'       => $d->stage?->name,
            'project_name'     => $d->stage?->project?->name,
        ]);
    }

    private function summarize(FollowUp $f): array
    {
        return [
            'id'             => $f->id,
            'title'          => $f->title,
            'description'    => $f->description,
            'status'         => $f->status,
            'due_date'       => $f->due_date?->toDateString(),
            'responsible'    => $f->responsible?->name,
            'delivery_id'    => $f->delivery_id,
            'delivery_title' => $f->delivery?->title,
            'created_at'     => $f->created_at?->toIso8601String(),
        ];
    }

    private function ensure(FollowUp $followUp, Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isCliente()) {
            return response()->json(['message' => 'Endpoint exclusivo do perfil cliente.'], 403);
        }
        if (!$followUp->clientCanSee($user)) {
            return response()->json(['message' => 'Você não está envolvido neste Follow Up.'], 403);
        }
        return null;
    }
}
