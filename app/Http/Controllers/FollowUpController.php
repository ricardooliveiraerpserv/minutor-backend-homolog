<?php

namespace App\Http\Controllers;

use App\Models\FollowUp;
use App\Models\FollowUpEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FollowUpController extends Controller
{
    private const WITH = [
        'responsible:id,name,email', 'requester:id,name,email', 'createdBy:id,name',
        'customer:id,name', 'contract:id,code', 'project:id,name,code', 'stage:id,name', 'delivery:id,title',
        'client:id,name,email',
    ];

    /**
     * Restringe a query ao contexto do usuário (mirror ProjectController::index).
     * admin / followups.view_all → tudo.
     * coordenador → follow-ups de projetos que coordena OU envolvido (resp/solic/criador).
     * consultor → só onde é responsável/solicitante/criador.
     */
    private function scope(Builder $q, $user): Builder
    {
        if ($user->isAdmin() || $user->hasAccess('followups.view_all')) {
            return $q;
        }

        // Cliente só vê Follow Ups em que está ENVOLVIDO (regra: sem cliente envolvido,
        // o cliente não vê os comentários/anexos — nem o próprio Follow Up).
        if (method_exists($user, 'isCliente') && $user->isCliente()) {
            return $q->where('client_involved', true)
                ->where(function (Builder $w) use ($user) {
                    $w->where('client_user_id', $user->id)->orWhere('client_email', $user->email);
                });
        }

        $involved = function (Builder $sub) use ($user) {
            $sub->where('responsible_user_id', $user->id)
                ->orWhere('requester_user_id', $user->id)
                ->orWhere('created_by', $user->id)
                // Consultor ENVOLVIDO: responsável da atividade vinculada OU alocado à
                // etapa do Follow Up (alocação é única por etapa+usuário).
                ->orWhereHas('delivery', fn (Builder $d) => $d->where('responsible_user_id', $user->id))
                ->orWhereExists(function ($q) use ($user) {
                    $q->selectRaw('1')->from('stage_allocations as sa')
                        ->where('sa.user_id', $user->id)
                        ->whereColumn('sa.stage_id', 'follow_ups.stage_id');
                });
        };

        if ($user->isCoordenador()) {
            return $q->where(function (Builder $w) use ($user, $involved) {
                $w->whereHas('project.coordinators', fn ($p) => $p->where('users.id', $user->id))
                  ->orWhere($involved);
            });
        }

        return $q->where($involved);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $pageSize = min((int) $request->get('pageSize', 50), 200);

        $q = FollowUp::with(self::WITH);
        $this->scope($q, $user);

        foreach (['status', 'category', 'priority', 'project_id', 'stage_id', 'delivery_id', 'contract_id', 'customer_id', 'responsible_user_id'] as $f) {
            if ($request->filled($f)) $q->where($f, $request->get($f));
        }
        if ($request->boolean('overdue')) $q->overdue();
        if ($request->filled('search')) {
            $s = $request->get('search');
            $q->where(fn ($w) => $w->where('title', 'ilike', "%{$s}%")->orWhere('description', 'ilike', "%{$s}%"));
        }

        $q->orderByRaw("CASE status WHEN 'completed' THEN 1 WHEN 'cancelled' THEN 1 ELSE 0 END")
          ->orderByRaw('due_date IS NULL')
          ->orderBy('due_date')
          ->orderBy('kanban_order');

        $page = $q->paginate($pageSize);
        return response()->json(['items' => $page->items(), 'hasNext' => $page->hasMorePages()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->hasAccess('followups.manage')) {
            return response()->json(['message' => 'Sem permissão para criar Follow Ups.'], 403);
        }

        $data = $this->validateData($request, true);

        // Regra: todo Follow Up tem vínculo (empresa/contrato/projeto/etapa/atividade).
        if (empty($data['delivery_id']) && empty($data['stage_id']) && empty($data['project_id'])
            && empty($data['contract_id']) && empty($data['customer_id'])) {
            return response()->json(['message' => 'Follow Up precisa de um vínculo (no mínimo a Empresa).'], 422);
        }

        $data = $this->applyOrigin($data);
        $data['created_by'] = $user->id;
        $data['status'] = $data['status'] ?? FollowUp::STATUS_PENDING;
        if (empty($data['responsible_user_id'])) $data['responsible_user_id'] = $user->id;

        $f = FollowUp::create($data);
        return response()->json($f->load(self::WITH), 201);
    }

    /**
     * Denormaliza os pais a partir do vínculo mais profundo e define origin_type.
     * atividade → preenche stage_id + project_id; etapa → preenche project_id.
     */
    private function applyOrigin(array $data): array
    {
        // Cronograma (atividade): activity_id é a origem; stage/project/empresa entram automáticos.
        if (!empty($data['delivery_id'])) {
            $d = \App\Models\StageDelivery::with('stage.project:id,customer_id')->find($data['delivery_id']);
            if ($d) {
                $data['stage_id']    = $d->stage_id;
                $data['project_id']  = optional($d->stage)->project_id;
                $data['customer_id'] = optional(optional($d->stage)->project)->customer_id ?? ($data['customer_id'] ?? null);
            }
            $data['origin_type'] = FollowUp::ORIGIN_ACTIVITY;
        } elseif (!empty($data['stage_id'])) {
            $s = \App\Models\ProjectStage::with('project:id,customer_id')->find($data['stage_id']);
            if ($s) {
                $data['project_id']  = $s->project_id;
                $data['customer_id'] = optional($s->project)->customer_id ?? ($data['customer_id'] ?? null);
            }
            $data['origin_type'] = FollowUp::ORIGIN_STAGE;
        } elseif (!empty($data['project_id'])) {
            $p = \App\Models\Project::find($data['project_id']);
            if ($p) $data['customer_id'] = $p->customer_id ?? ($data['customer_id'] ?? null);
            $data['origin_type'] = FollowUp::ORIGIN_PROJECT;
        } elseif (!empty($data['contract_id'])) {
            $c = \App\Models\Contract::find($data['contract_id']);
            if ($c) $data['customer_id'] = $c->customer_id ?? ($data['customer_id'] ?? null);
            $data['origin_type'] = FollowUp::ORIGIN_CONTRACT;
        } elseif (!empty($data['customer_id'])) {
            $data['origin_type'] = FollowUp::ORIGIN_COMPANY;
        }
        return $data;
    }

    public function show(Request $request, FollowUp $followUp): JsonResponse
    {
        if (!$this->canSee($request->user(), $followUp)) {
            return response()->json(['message' => 'Sem acesso a este Follow Up.'], 403);
        }
        return response()->json($followUp->load(self::WITH));
    }

    public function update(Request $request, FollowUp $followUp): JsonResponse
    {
        $user = $request->user();
        if (!$this->canSee($user, $followUp)) {
            return response()->json(['message' => 'Sem acesso a este Follow Up.'], 403);
        }
        $followUp->update($this->validateData($request, false));
        return response()->json($followUp->fresh()->load(self::WITH));
    }

    public function destroy(FollowUp $followUp): JsonResponse
    {
        $followUp->delete(); // soft delete
        return response()->json(['deleted' => true]);
    }

    /** Drag-and-drop: coluna → (status, waiting_subtype) + reordenação. */
    public function kanbanMove(Request $request, FollowUp $followUp): JsonResponse
    {
        $data = $request->validate([
            'to_column' => 'required|string',
            'order'     => 'nullable|integer|min:0',
        ]);

        [$status, $subtype] = match ($data['to_column']) {
            'pending'        => [FollowUp::STATUS_PENDING, null],
            'in_progress'    => [FollowUp::STATUS_IN_PROGRESS, null],
            'waiting_client' => [FollowUp::STATUS_WAITING_THIRD, 'client'],
            'waiting_partner'=> [FollowUp::STATUS_WAITING_THIRD, 'partner'],
            'completed'      => [FollowUp::STATUS_COMPLETED, null],
            'cancelled'      => [FollowUp::STATUS_CANCELLED, null],
            default          => [null, null],
        };
        if ($status === null) {
            return response()->json(['message' => 'Coluna inválida.'], 422);
        }

        $followUp->update([
            'status'          => $status,
            'waiting_subtype' => $subtype,
            'kanban_order'    => $data['order'] ?? $followUp->kanban_order,
        ]);

        return response()->json($followUp->fresh()->load(self::WITH));
    }

    public function timeline(FollowUp $followUp): JsonResponse
    {
        $items = $followUp->events()->with('actor:id,name,email')->limit(200)->get();
        return response()->json(['items' => $items]);
    }

    public function storeComment(Request $request, FollowUp $followUp): JsonResponse
    {
        $user = $request->user();
        if (!$this->canSee($user, $followUp)) {
            return response()->json(['message' => 'Sem acesso a este Follow Up.'], 403);
        }

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
            'actor_user_id' => $user?->id,
            'type'          => FollowUpEvent::TYPE_COMMENT,
            'payload'       => array_filter(['text' => $text !== '' ? $text : null]),
        ], $att));

        return response()->json($event->load('actor:id,name,email'), 201);
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        // Escopo opcional (aba do projeto / etapa / atividade).
        $base = function () use ($user, $request) {
            $q = $this->scope(FollowUp::query(), $user);
            foreach (['project_id', 'stage_id', 'delivery_id', 'contract_id', 'customer_id'] as $f) {
                if ($request->filled($f)) $q->where($f, $request->get($f));
            }
            return $q;
        };

        return response()->json([
            'pendentes'          => (clone $base())->where('status', FollowUp::STATUS_PENDING)->count(),
            'em_andamento'       => (clone $base())->where('status', FollowUp::STATUS_IN_PROGRESS)->count(),
            'atrasados'          => (clone $base())->overdue()->count(),
            'criticos'           => (clone $base())->open()->where('priority', 'critica')->count(),
            'aguardando_cliente' => (clone $base())->where('status', FollowUp::STATUS_WAITING_THIRD)->where('waiting_subtype', 'client')->count(),
            'aguardando_parceiro'=> (clone $base())->where('status', FollowUp::STATUS_WAITING_THIRD)->where('waiting_subtype', 'partner')->count(),
            'concluidos'         => (clone $base())->where('status', FollowUp::STATUS_COMPLETED)->count(),
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────
    private function canSee($user, FollowUp $f): bool
    {
        if ($user->isAdmin() || $user->hasAccess('followups.view_all')) return true;
        if (method_exists($user, 'isCliente') && $user->isCliente()) return $f->clientCanSee($user);
        return $this->scope(FollowUp::whereKey($f->id), $user)->exists();
    }

    private function validateData(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        return $request->validate([
            'title'               => "$req|string|max:255",
            'description'         => 'nullable|string|max:5000',
            'status'              => ['sometimes', Rule::in(FollowUp::STATUSES)],
            'waiting_subtype'     => ['nullable', Rule::in(FollowUp::WAITING_SUBTYPES)],
            'category'            => 'sometimes|nullable|string|max:120', // nome vindo do cadastro de categorias
            'priority'            => ['sometimes', Rule::in(FollowUp::PRIORITIES)],
            'due_date'            => 'nullable|date',
            'responsible_user_id' => 'nullable|integer|exists:users,id',
            'requester_user_id'   => 'nullable|integer|exists:users,id',
            'client_involved'     => 'nullable|boolean',
            'client_user_id'      => 'nullable|integer|exists:users,id',
            'client_email'        => 'nullable|email|max:180',
            'customer_id'         => 'nullable|integer|exists:customers,id',
            'contract_id'         => 'nullable|integer|exists:contracts,id',
            'project_id'          => 'nullable|integer|exists:projects,id',
            'stage_id'            => 'nullable|integer|exists:project_stages,id',
            'delivery_id'         => 'nullable|integer|exists:stage_deliveries,id',
        ]);
    }
}
