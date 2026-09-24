<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskStatus;
use App\Services\HelpDeskPortalColumns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Config das colunas do Kanban do PORTAL DO CLIENTE (global). As rotas de leitura/escrita ficam
 * sob `block.cliente` (só interno edita). O portal do cliente consome via `portal()`.
 */
class HelpDeskPortalColumnsController extends Controller
{
    /** Editor (ADMIN): config atual + status disponíveis p/ montar as colunas. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Apenas o administrador configura o Kanban do cliente.');

        return response()->json([
            'columns'  => HelpDeskPortalColumns::get(),
            'statuses' => HelpDeskStatus::where('active', true)
                ->orderBy('sort_order')->orderBy('id')
                ->get(['key', 'label', 'color', 'is_terminal'])
                ->map(fn ($s) => [
                    'key'         => $s->key,
                    'label'       => $s->label,
                    'cor'         => $s->color,
                    'is_terminal' => (bool) $s->is_terminal,
                ])->values(),
        ]);
    }

    /** Salva a config global (ADMIN). */
    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Apenas o administrador configura o Kanban do cliente.');

        $data = $request->validate([
            'columns'              => 'required|array|min:1',
            'columns.*.label'      => 'required|string|max:60',
            'columns.*.cor'        => 'nullable|string',
            'columns.*.fallback'   => 'boolean',
            'columns.*.rule'       => 'nullable|string|in:scheduled',
            'columns.*.statuses'   => 'array',
            'columns.*.statuses.*' => 'string',
        ]);

        return response()->json(['columns' => HelpDeskPortalColumns::save($data['columns'])]);
    }

    /** Portal do cliente: só as colunas (label, cor, statuses[key], fallback). */
    public function portal(): JsonResponse
    {
        return response()->json(['data' => HelpDeskPortalColumns::get()]);
    }
}
