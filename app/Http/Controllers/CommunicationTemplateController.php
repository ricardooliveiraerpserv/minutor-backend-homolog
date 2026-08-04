<?php

namespace App\Http\Controllers;

use App\Models\CommunicationTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Modelos (templates) reutilizáveis da Central de Comunicação. */
class CommunicationTemplateController extends Controller
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
        $rows = CommunicationTemplate::when(!$u->isAdmin(), fn ($q) => $q->where('owner_id', $u->id))
            ->orderBy('nome')->get(['id', 'nome', 'tipo_comunicacao', 'title', 'message', 'structure']);
        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->manager($request);
        $v = $request->validate([
            'nome'             => 'required|string|max:200',
            'tipo_comunicacao' => 'nullable|in:aviso,formal,marketing',
            'title'            => 'nullable|string|max:250',
            'message'          => 'nullable|string',
            'structure'        => 'nullable|array',
        ]);
        $t = CommunicationTemplate::create(array_merge($v, ['owner_id' => $u->id]));
        return response()->json(['data' => ['id' => $t->id, 'nome' => $t->nome]], 201);
    }

    public function update(Request $request, CommunicationTemplate $communicationTemplate): JsonResponse
    {
        $u = $this->manager($request);
        abort_unless($u->isAdmin() || $communicationTemplate->owner_id === $u->id, 403);

        $v = $request->validate([
            'nome'             => 'required|string|max:200',
            'tipo_comunicacao' => 'nullable|in:aviso,formal,marketing',
            'title'            => 'nullable|string|max:250',
            'message'          => 'nullable|string',
            'structure'        => 'nullable|array',
        ]);

        // structure só é sobrescrita quando enviada (edição pela aba "Novo envio");
        // a edição simples (nome/tipo/título/mensagem) preserva a formatação rica existente.
        if (!$request->has('structure')) {
            unset($v['structure']);
        }

        $communicationTemplate->update($v);
        return response()->json(['data' => ['id' => $communicationTemplate->id, 'nome' => $communicationTemplate->nome]]);
    }

    public function destroy(Request $request, CommunicationTemplate $communicationTemplate): JsonResponse
    {
        $u = $this->manager($request);
        abort_unless($u->isAdmin() || $communicationTemplate->owner_id === $u->id, 403);
        $communicationTemplate->delete();
        return response()->json(null, 204);
    }
}
