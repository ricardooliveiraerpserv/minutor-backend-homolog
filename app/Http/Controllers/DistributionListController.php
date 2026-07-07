<?php

namespace App\Http\Controllers;

use App\Models\DistributionList;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Listas de distribuição reutilizáveis da Central de Comunicação. */
class DistributionListController extends Controller
{
    private const MANAGERS = ['admin', 'coordenador', 'administrativo'];

    private function manager(Request $request): User
    {
        $u = $request->user();
        abort_unless($u && in_array($u->type, self::MANAGERS, true), 403);
        return $u;
    }

    public function index(Request $request): JsonResponse
    {
        $u = $this->manager($request);
        $rows = DistributionList::when(!$u->isAdmin(), fn ($q) => $q->where('owner_id', $u->id))
            ->orderBy('nome')->get()
            ->map(fn (DistributionList $l) => [
                'id' => $l->id, 'nome' => $l->nome,
                'customer_ids' => $l->customer_ids ?? [], 'user_ids' => $l->user_ids ?? [], 'external_emails' => $l->external_emails ?? [],
            ]);
        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->manager($request);
        $v = $this->validatePayload($request);
        $l = DistributionList::create(array_merge($v, ['owner_id' => $u->id]));
        return response()->json(['data' => ['id' => $l->id, 'nome' => $l->nome]], 201);
    }

    public function destroy(Request $request, DistributionList $distributionList): JsonResponse
    {
        $u = $this->manager($request);
        abort_unless($u->isAdmin() || $distributionList->owner_id === $u->id, 403);
        $distributionList->delete();
        return response()->json(null, 204);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'nome'             => 'required|string|max:200',
            'customer_ids'     => 'nullable|array',
            'user_ids'         => 'nullable|array',
            'external_emails'  => 'nullable|array',
            'external_emails.*' => 'email',
        ]);
    }
}
