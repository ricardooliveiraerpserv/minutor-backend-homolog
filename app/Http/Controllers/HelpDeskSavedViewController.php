<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskSavedView;
use App\Services\HelpDeskAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visualizações salvas da fila do Help Desk (conjuntos de filtros nomeados).
 * Pessoais (do dono) + compartilhadas (todos os agentes). Gate por perfil de acesso:
 * criar pessoal = tickets.personal_views; criar/editar compartilhada = tickets.shared_views.
 */
class HelpDeskSavedViewController extends Controller
{
    public function __construct(private HelpDeskAccessPolicy $access)
    {
    }

    /** Lista: as do usuário + todas as compartilhadas. */
    public function index(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $views = HelpDeskSavedView::with('user:id,name')
            ->where('user_id', $uid)
            ->orWhere('is_shared', true)
            ->orderBy('name')
            ->get()
            ->map(fn (HelpDeskSavedView $v) => [
                'id'         => $v->id,
                'name'       => $v->name,
                'filters'    => $v->filters,
                'is_shared'  => $v->is_shared,
                'is_own'     => (int) $v->user_id === $uid,
                'owner_name' => $v->user?->name,
            ]);

        return response()->json([
            'data'         => $views,
            'can_personal' => $this->access->canCreatePersonalViews($request->user()),
            'can_shared'   => $this->access->canCreateSharedViews($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name'      => 'required|string|max:120',
            'filters'   => 'required|array',
            'is_shared' => 'boolean',
        ]);
        $shared = (bool) ($v['is_shared'] ?? false);

        // Gate: compartilhada exige tickets.shared_views; pessoal exige tickets.personal_views.
        abort_unless(
            $shared ? $this->access->canCreateSharedViews($request->user())
                    : $this->access->canCreatePersonalViews($request->user()),
            403,
            $shared ? 'Seu perfil não permite criar visualizações compartilhadas.'
                    : 'Seu perfil não permite salvar visualizações.'
        );

        $view = HelpDeskSavedView::create([
            'user_id'   => $request->user()->id,
            'name'      => $v['name'],
            'filters'   => $v['filters'],
            'is_shared' => $shared,
        ]);

        return response()->json(['data' => ['id' => $view->id]], 201);
    }

    public function update(Request $request, HelpDeskSavedView $savedView): JsonResponse
    {
        // Só o dono (ou admin) edita.
        abort_unless(
            (int) $savedView->user_id === (int) $request->user()->id || $request->user()->isAdmin(),
            403,
            'Você só pode editar as suas visualizações.'
        );
        $v = $request->validate([
            'name'      => 'sometimes|string|max:120',
            'filters'   => 'sometimes|array',
            'is_shared' => 'sometimes|boolean',
        ]);

        // Tornar compartilhada exige a permissão.
        if (($v['is_shared'] ?? $savedView->is_shared) && !$savedView->is_shared) {
            abort_unless($this->access->canCreateSharedViews($request->user()), 403, 'Seu perfil não permite criar visualizações compartilhadas.');
        }

        $savedView->fill($v)->save();
        return response()->json(['data' => ['id' => $savedView->id]]);
    }

    public function destroy(Request $request, HelpDeskSavedView $savedView): JsonResponse
    {
        abort_unless(
            (int) $savedView->user_id === (int) $request->user()->id || $request->user()->isAdmin(),
            403,
            'Você só pode excluir as suas visualizações.'
        );
        $savedView->delete();
        return response()->json(null, 204);
    }
}
