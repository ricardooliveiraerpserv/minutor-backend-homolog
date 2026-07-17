<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskAccessProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Help Desk — Cadastro de Perfis de Acesso (agente|cliente). CRUD. */
class HelpDeskAccessProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = HelpDeskAccessProfile::query()
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->kind))
            ->when(!$request->boolean('all'), fn ($q) => $q->where('enabled', true))
            ->orderBy('kind')->orderBy('sort_order')->orderBy('name')
            ->get();
        return response()->json(['data' => $rows]);
    }

    private function rules(bool $creating): array
    {
        return [
            'name'        => ($creating ? 'required' : 'sometimes') . '|string|max:120',
            'kind'        => ($creating ? 'required' : 'sometimes') . '|in:agent,cliente',
            'is_default'  => 'nullable|boolean',
            'enabled'     => 'nullable|boolean',
            'permissions' => 'nullable|array',
            'sort_order'  => 'nullable|integer',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        return DB::transaction(function () use ($v) {
            $p = HelpDeskAccessProfile::create($v);
            $this->ensureSingleDefault($p);
            return response()->json(['data' => $p->fresh()], 201);
        });
    }

    public function update(Request $request, HelpDeskAccessProfile $accessProfile): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        return DB::transaction(function () use ($v, $accessProfile) {
            $accessProfile->update($v);
            $this->ensureSingleDefault($accessProfile);
            return response()->json(['data' => $accessProfile->fresh()]);
        });
    }

    public function destroy(HelpDeskAccessProfile $accessProfile): JsonResponse
    {
        // Garante ao menos um perfil ativo do tipo (regra Movidesk).
        $ativosDoTipo = HelpDeskAccessProfile::where('kind', $accessProfile->kind)->where('enabled', true)->where('id', '!=', $accessProfile->id)->count();
        abort_if($accessProfile->enabled && $ativosDoTipo === 0, 422, 'Pelo menos um perfil de acesso ' . ($accessProfile->kind === 'agent' ? 'de agente' : 'de cliente') . ' precisa estar ativo.');
        $accessProfile->delete();
        return response()->json(null, 204);
    }

    /**
     * Pessoas do Help Desk com seu perfil de acesso vinculado.
     * kind=agent → usuários internos (candidatos a agente); kind=cliente → usuários cliente.
     */
    public function people(Request $request): JsonResponse
    {
        $kind = $request->get('kind') === 'cliente' ? 'cliente' : 'agent';
        // customer_id + helpdesk_department_id: p/ a tela de Pessoas oferecer o
        // departamento (escopo por cliente). Ver HelpDeskDepartmentController.
        $q = User::query()->select('id', 'name', 'type', 'helpdesk_access_profile_id', 'customer_id', 'helpdesk_department_id');
        $kind === 'cliente'
            ? $q->where('type', 'cliente')
            : $q->whereIn('type', ['admin', 'administrativo', 'coordenador', 'consultor']);
        if ($request->filled('search')) $q->where('name', 'ilike', '%' . $request->search . '%');
        return response()->json(['data' => $q->orderBy('name')->limit(500)->get()]);
    }

    /** Vincula (ou remove) o perfil de acesso de um usuário, validando a compatibilidade de tipo. */
    public function setAccessProfile(Request $request, User $user): JsonResponse
    {
        $v = $request->validate(['access_profile_id' => 'nullable|exists:helpdesk_access_profiles,id']);
        if (!empty($v['access_profile_id'])) {
            $prof = HelpDeskAccessProfile::find($v['access_profile_id']);
            $userIsCliente = $user->type === 'cliente';
            abort_if($userIsCliente !== ($prof->kind === 'cliente'), 422, 'Perfil incompatível com o tipo do usuário (agente × cliente).');
        }
        $user->helpdesk_access_profile_id = $v['access_profile_id'] ?? null; // set direto (fora do mass-assign)
        $user->save();
        return response()->json(['data' => ['id' => $user->id, 'helpdesk_access_profile_id' => $user->helpdesk_access_profile_id]]);
    }

    /** Atualização em massa: aplica um perfil de acesso a vários usuários de uma vez. */
    public function bulkSetAccessProfile(Request $request): JsonResponse
    {
        $v = $request->validate([
            'user_ids'          => 'required|array|min:1',
            'user_ids.*'        => 'integer|exists:users,id',
            'access_profile_id' => 'nullable|exists:helpdesk_access_profiles,id',
        ]);

        $prof = !empty($v['access_profile_id']) ? HelpDeskAccessProfile::find($v['access_profile_id']) : null;
        $users = User::whereIn('id', $v['user_ids'])->get();
        $applied = 0; $skipped = 0;
        foreach ($users as $user) {
            // Respeita compatibilidade agente × cliente (pula incompatíveis em vez de abortar tudo).
            if ($prof && (($user->type === 'cliente') !== ($prof->kind === 'cliente'))) { $skipped++; continue; }
            $user->helpdesk_access_profile_id = $prof?->id;
            $user->save();
            $applied++;
        }
        return response()->json(['data' => ['applied' => $applied, 'skipped' => $skipped]]);
    }

    /** Apenas UM perfil padrão por tipo (agente|cliente). */
    private function ensureSingleDefault(HelpDeskAccessProfile $p): void
    {
        if ($p->is_default) {
            HelpDeskAccessProfile::where('kind', $p->kind)->where('id', '!=', $p->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
