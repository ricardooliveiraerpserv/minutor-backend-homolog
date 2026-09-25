<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEnvMembership;
use App\Models\EnvGroupPermission;
use App\Models\EnvPermission;
use App\Models\VaultMember;
use App\Services\EnvAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestão da ACL fina por ambiente. Só quem tem `admin` no ambiente gerencia.
 * Lista os membros do cliente-vault com as permissões EFETIVAS (default do papel ou
 * custom) e permite salvar overrides por usuário.
 */
class EnvPermissionController extends Controller
{
    use ResolvesEnvMembership;

    public function index(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envAuthorized($request, $envId, 'admin');

        $members = VaultMember::with('user:id,name,email')
            ->where('vault_id', $env->vault_id)->get();
        $custom = EnvPermission::where('environment_id', $env->id)->get()->keyBy('user_id');

        $rows = $members->map(function ($m) use ($env, $custom) {
            $eff = EnvAccess::effectiveFor($m->user, $env);

            return [
                'user_id'    => $m->user_id,
                'name'       => $m->user?->name,
                'email'      => $m->user?->email,
                'role'       => $m->role,
                'has_custom' => $custom->has($m->user_id),
                'source'     => $eff['source'],
                'can_view'   => $eff['view'],
                'can_reveal' => $eff['reveal'],
                'can_copy'   => $eff['copy'],
                'can_manage' => $eff['manage'],
                'can_admin'  => $eff['admin'],
            ];
        });

        return response()->json($rows);
    }

    public function upsert(Request $request, int $envId, int $userId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envAuthorized($request, $envId, 'admin');

        // Alvo tem que ser membro do cliente-vault
        abort_unless(VaultMember::where('vault_id', $env->vault_id)->where('user_id', $userId)->exists(), 422, 'Usuário não é membro deste cofre.');

        $data = $request->validate([
            'can_view'   => 'required|boolean',
            'can_reveal' => 'required|boolean',
            'can_copy'   => 'required|boolean',
            'can_manage' => 'required|boolean',
            'can_admin'  => 'required|boolean',
        ]);

        EnvPermission::updateOrCreate(
            ['environment_id' => $env->id, 'user_id' => $userId],
            $data
        );

        return response()->json(['saved' => true]);
    }

    /** Remove o override → volta ao default do papel. */
    public function destroy(Request $request, int $envId, int $userId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envAuthorized($request, $envId, 'admin');
        EnvPermission::where('environment_id', $env->id)->where('user_id', $userId)->delete();

        return response()->json(['reset' => true]);
    }

    // ── ACL de GRUPO (herança automática) ──────────────────────────────────────

    /** Grupos de Consultores + a permissão de grupo neste ambiente (membros herdam). */
    public function groupIndex(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envAuthorized($request, $envId, 'admin');

        $custom = EnvGroupPermission::where('environment_id', $env->id)->get()->keyBy('consultant_group_id');
        $groups = DB::table('consultant_groups')->orderBy('name')->get()->map(function ($g) use ($custom) {
            $p = $custom->get($g->id);

            return [
                'group_id'   => $g->id,
                'name'       => $g->name,
                'members'    => DB::table('consultant_group_user')->where('consultant_group_id', $g->id)->count(),
                'has_perm'   => (bool) $p,
                'can_view'   => (bool) ($p->can_view ?? false),
                'can_reveal' => (bool) ($p->can_reveal ?? false),
                'can_copy'   => (bool) ($p->can_copy ?? false),
                'can_manage' => (bool) ($p->can_manage ?? false),
                'can_admin'  => (bool) ($p->can_admin ?? false),
            ];
        })->filter(fn ($g) => $g['members'] > 0)->values();

        return response()->json($groups);
    }

    public function groupUpsert(Request $request, int $envId, int $groupId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envAuthorized($request, $envId, 'admin');
        abort_unless(DB::table('consultant_groups')->where('id', $groupId)->exists(), 422, 'Grupo inexistente.');

        $data = $request->validate([
            'can_view'   => 'required|boolean',
            'can_reveal' => 'required|boolean',
            'can_copy'   => 'required|boolean',
            'can_manage' => 'required|boolean',
            'can_admin'  => 'required|boolean',
        ]);
        EnvGroupPermission::updateOrCreate(
            ['environment_id' => $env->id, 'consultant_group_id' => $groupId],
            $data
        );

        return response()->json(['saved' => true]);
    }

    /** Remove a permissão do grupo → o grupo deixa de herdar (volta ao default do papel). */
    public function groupDestroy(Request $request, int $envId, int $groupId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envAuthorized($request, $envId, 'admin');
        EnvGroupPermission::where('environment_id', $env->id)->where('consultant_group_id', $groupId)->delete();

        return response()->json(['reset' => true]);
    }
}
