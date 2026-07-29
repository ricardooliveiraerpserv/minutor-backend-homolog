<?php

namespace App\Http\Controllers;

use App\Models\CommunicationGroup;
use App\Models\CommunicationGroupBlock;
use App\Models\CommunicationGroupRecipient;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Grupos de distribuição estruturados em BLOCOS por cliente (Central de Comunicação).
 * Gate igual ao das listas: admin/coordenador/administrativo; não-admin só vê os seus.
 */
class CommunicationGroupController extends Controller
{
    private const MANAGERS = ['admin', 'coordenador', 'administrativo'];

    private function manager(Request $request): User
    {
        $u = $request->user();
        abort_unless($u && in_array($u->type, self::MANAGERS, true), 403);
        return $u;
    }

    /** Restringe o grupo ao dono (exceto admin, que vê todos). */
    private function ownedGroup(User $u, int $id): CommunicationGroup
    {
        $g = CommunicationGroup::findOrFail($id);
        abort_unless($u->isAdmin() || $g->owner_id === $u->id, 403);
        return $g;
    }

    public function index(Request $request): JsonResponse
    {
        $u = $this->manager($request);
        $rows = CommunicationGroup::when(!$u->isAdmin(), fn ($q) => $q->where('owner_id', $u->id))
            ->withCount('blocks')
            ->with(['blocks:id,group_id'])
            ->orderBy('nome')->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'nome' => $g->nome,
                'blocks_count' => $g->blocks_count,
                'recipients_count' => CommunicationGroupRecipient::whereIn('block_id', $g->blocks->pluck('id'))->count(),
            ]);
        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $group): JsonResponse
    {
        $u = $this->manager($request);
        $g = $this->ownedGroup($u, $group);
        $g->load(['blocks.customer:id,name', 'blocks.recipients']);
        return response()->json(['data' => $this->serialize($g)]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->manager($request);
        $v = $request->validate(['nome' => 'required|string|max:200']);
        $g = CommunicationGroup::create(['nome' => trim($v['nome']), 'owner_id' => $u->id]);
        return response()->json(['data' => ['id' => $g->id, 'nome' => $g->nome]], 201);
    }

    public function update(Request $request, int $group): JsonResponse
    {
        $u = $this->manager($request);
        $g = $this->ownedGroup($u, $group);
        $v = $request->validate(['nome' => 'required|string|max:200']);
        $g->update(['nome' => trim($v['nome'])]);
        return response()->json(['data' => ['id' => $g->id, 'nome' => $g->nome]]);
    }

    public function destroy(Request $request, int $group): JsonResponse
    {
        $u = $this->manager($request);
        $g = $this->ownedGroup($u, $group);
        $g->delete(); // cascade em blocos/destinatários
        return response()->json(['ok' => true]);
    }

    /** Adiciona um bloco (cliente) ao grupo. */
    public function addBlock(Request $request, int $group): JsonResponse
    {
        $u = $this->manager($request);
        $g = $this->ownedGroup($u, $group);
        $v = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
            'label'       => 'nullable|string|max:200',
        ]);
        $label = $v['label'] ?? null;
        if (!$label && !empty($v['customer_id'])) {
            $label = Customer::find($v['customer_id'])?->name;
        }
        $sort = (int) ($g->blocks()->max('sort_order')) + 1;
        $block = CommunicationGroupBlock::create([
            'group_id' => $g->id,
            'customer_id' => $v['customer_id'] ?? null,
            'label' => $label,
            'sort_order' => $sort,
        ]);
        $block->load(['customer:id,name', 'recipients']);
        return response()->json(['data' => $this->serializeBlock($block)], 201);
    }

    public function deleteBlock(Request $request, int $group, int $block): JsonResponse
    {
        $u = $this->manager($request);
        $g = $this->ownedGroup($u, $group);
        $b = CommunicationGroupBlock::where('group_id', $g->id)->findOrFail($block);
        $b->delete();
        return response()->json(['ok' => true]);
    }

    /** Substitui os destinatários de um bloco (sync completo). */
    public function saveBlock(Request $request, int $group, int $block): JsonResponse
    {
        $u = $this->manager($request);
        $g = $this->ownedGroup($u, $group);
        $b = CommunicationGroupBlock::where('group_id', $g->id)->findOrFail($block);
        $v = $request->validate([
            'label'                  => 'nullable|string|max:200',
            'recipients'             => 'present|array',
            'recipients.*.email'     => 'required|email',
            'recipients.*.name'      => 'nullable|string|max:200',
            'recipients.*.user_id'   => 'nullable|integer|exists:users,id',
            'recipients.*.kind'      => 'nullable|in:cadastrado,manual',
        ]);

        DB::transaction(function () use ($b, $v) {
            if (array_key_exists('label', $v)) $b->update(['label' => $v['label']]);
            $b->recipients()->delete();
            $seen = [];
            foreach ($v['recipients'] as $r) {
                $email = mb_strtolower(trim($r['email']));
                if ($email === '' || isset($seen[$email])) continue; // dedup por bloco
                $seen[$email] = true;
                CommunicationGroupRecipient::create([
                    'block_id' => $b->id,
                    'user_id'  => $r['user_id'] ?? null,
                    'email'    => $email,
                    'name'     => $r['name'] ?? null,
                    'kind'     => $r['kind'] ?? (!empty($r['user_id']) ? 'cadastrado' : 'manual'),
                ]);
            }
        });

        $b->load(['customer:id,name', 'recipients']);
        return response()->json(['data' => $this->serializeBlock($b)]);
    }

    /** Copia um bloco (com destinatários) para outro grupo. */
    public function copyBlock(Request $request, int $group, int $block): JsonResponse
    {
        $u = $this->manager($request);
        $g = $this->ownedGroup($u, $group);
        $b = CommunicationGroupBlock::where('group_id', $g->id)->with('recipients')->findOrFail($block);
        $v = $request->validate(['target_group_id' => 'required|integer']);
        $target = $this->ownedGroup($u, (int) $v['target_group_id']);
        abort_if($target->id === $g->id, 422, 'Escolha um grupo diferente para copiar o bloco.');

        $new = DB::transaction(function () use ($b, $target) {
            $sort = (int) $target->blocks()->max('sort_order') + 1;
            $nb = CommunicationGroupBlock::create([
                'group_id' => $target->id,
                'customer_id' => $b->customer_id,
                'label' => $b->label,
                'sort_order' => $sort,
            ]);
            foreach ($b->recipients as $r) {
                CommunicationGroupRecipient::create([
                    'block_id' => $nb->id,
                    'user_id'  => $r->user_id,
                    'email'    => $r->email,
                    'name'     => $r->name,
                    'kind'     => $r->kind,
                ]);
            }
            return $nb;
        });

        $new->load(['customer:id,name', 'recipients']);
        return response()->json(['data' => $this->serializeBlock($new), 'target_group_id' => $target->id], 201);
    }

    /** Resolve o grupo em destinatários pro Novo Envio (customer_ids/user_ids/external_emails). */
    public function resolve(Request $request, int $group): JsonResponse
    {
        $u = $this->manager($request);
        $g = $this->ownedGroup($u, $group);
        $g->load('blocks.recipients');

        $customerIds = $g->blocks->pluck('customer_id')->filter()->unique()->values();
        $recs = $g->blocks->flatMap->recipients;
        $userIds = $recs->where('kind', 'cadastrado')->pluck('user_id')->filter()->unique()->values();
        $external = $recs->where('kind', 'manual')->pluck('email')->map(fn ($e) => mb_strtolower(trim($e)))->filter()->unique()->values();

        return response()->json(['data' => [
            'customer_ids'    => $customerIds,
            'user_ids'        => $userIds,
            'external_emails' => $external,
        ]]);
    }

    private function serialize(CommunicationGroup $g): array
    {
        return [
            'id' => $g->id,
            'nome' => $g->nome,
            'blocks' => $g->blocks->map(fn ($b) => $this->serializeBlock($b))->values(),
        ];
    }

    private function serializeBlock(CommunicationGroupBlock $b): array
    {
        return [
            'id' => $b->id,
            'customer_id' => $b->customer_id,
            'customer_name' => $b->customer?->name,
            'label' => $b->label,
            'sort_order' => $b->sort_order,
            'recipients' => $b->recipients->map(fn ($r) => [
                'id' => $r->id,
                'user_id' => $r->user_id,
                'email' => $r->email,
                'name' => $r->name,
                'kind' => $r->kind,
            ])->values(),
        ];
    }
}
