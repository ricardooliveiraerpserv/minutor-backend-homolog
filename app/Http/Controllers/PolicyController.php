<?php

namespace App\Http\Controllers;

use App\Models\PolicyAssignment;
use App\Models\PolicyOverride;
use App\Models\PolicyRole;
use App\Models\User;
use App\Services\PolicyResolver;
use App\Support\PolicyCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Motor de Políticas — API genérica parametrizada por {module} (crm, projetos, …).
 * Configuração (perfis/atribuição/exceções) restrita a quem administra; /me é do
 * próprio usuário. Nesta fase o efetivo é apenas EXPOSTO (ainda não bloqueia nada).
 */
class PolicyController extends Controller
{
    use \App\Http\Traits\FiltersByActiveCompany;

    public function __construct(private PolicyResolver $resolver) {}

    private function companyId(): ?int
    {
        return $this->activeCompanyId();
    }

    private function assertModule(string $module): void
    {
        abort_unless(!empty(PolicyCatalog::keys($module)), 404, 'Módulo de política inexistente.');
    }

    private function assertAdmin(): User
    {
        $u = auth()->user();
        abort_unless($u && ($u->isAdmin() || $u->type === 'administrativo' || $this->resolver->can($u, 'crm', 'policy.manage')), 403, 'Sem acesso à configuração de políticas.');
        return $u;
    }

    /** Garante os perfis de sistema semeados (idempotente, por empresa). */
    private function ensureRolesSeeded(string $module): void
    {
        $cid = $this->companyId();
        foreach (PolicyCatalog::systemRoles($module) as $r) {
            PolicyRole::firstOrCreate(
                ['company_id' => $cid, 'module' => $module, 'name' => $r['name']],
                ['is_system' => true, 'archived' => false, 'defaults' => $r['defaults'], 'sort_order' => $r['sort']]
            );
        }
    }

    // ── Catálogo ────────────────────────────────────────────────────────────
    public function catalog(string $module): JsonResponse
    {
        $this->assertModule($module);
        $this->assertAdmin();
        return response()->json(['data' => [
            'module' => $module,
            'blocks' => PolicyCatalog::blocks($module),
        ]]);
    }

    // ── Perfis ──────────────────────────────────────────────────────────────
    public function roles(string $module): JsonResponse
    {
        $this->assertModule($module);
        $this->assertAdmin();
        $this->ensureRolesSeeded($module);
        $cid = $this->companyId();
        $roles = PolicyRole::where('module', $module)->where('company_id', $cid)->where('archived', false)
            ->orderBy('sort_order')->orderBy('name')->get();
        $counts = PolicyAssignment::where('module', $module)->select('role_id', DB::raw('count(*) c'))
            ->groupBy('role_id')->pluck('c', 'role_id');
        return response()->json(['data' => $roles->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name, 'is_system' => $r->is_system,
            'defaults' => $r->defaults ?? new \stdClass, 'sort_order' => $r->sort_order,
            'people' => (int) ($counts[$r->id] ?? 0),
        ])]);
    }

    /** Edita os padrões de um perfil (não-sistema, ou sistema com cuidado). */
    public function updateRole(Request $request, string $module, PolicyRole $role): JsonResponse
    {
        $this->assertModule($module);
        $this->assertAdmin();
        $v = $request->validate([
            'name'     => 'sometimes|string|max:80',
            'defaults' => 'sometimes|array',
            'archived' => 'sometimes|boolean',
        ]);
        if (isset($v['defaults'])) $v['defaults'] = $this->sanitizeMap($module, $v['defaults']);
        $from = $role->defaults;
        $role->update($v);
        $this->audit($module, 'role', $role->id, null, $from, $role->defaults);
        return response()->json(['data' => $role->fresh()]);
    }

    // ── Política de um usuário (aba "Política Comercial") ──────────────────────
    public function userShow(string $module, User $user): JsonResponse
    {
        $this->assertModule($module);
        $this->assertAdmin();
        $this->ensureRolesSeeded($module);
        $a = PolicyAssignment::where('user_id', $user->id)->where('module', $module)->first();
        $ov = PolicyOverride::where('user_id', $user->id)->where('module', $module)->get()
            ->mapWithKeys(fn ($o) => [$o->key => $o->value['v'] ?? null]);
        return response()->json(['data' => [
            'user'      => ['id' => $user->id, 'name' => $user->name, 'type' => $user->type, 'is_admin' => $user->isAdmin()],
            'role_id'   => $a?->role_id,
            'scope_ref' => $a?->scope_ref,
            'overrides' => $ov,
            'effective' => $this->resolver->effective($user, $module),
        ]]);
    }

    /** Define o perfil (e alcance) do usuário. */
    public function setAssignment(Request $request, string $module, User $user): JsonResponse
    {
        $this->assertModule($module);
        $this->assertAdmin();
        $cid = $this->companyId();
        $v = $request->validate([
            'role_id'   => 'nullable|exists:policy_roles,id',
            'scope_ref' => 'nullable|array',
        ]);
        $a = PolicyAssignment::firstOrNew(['user_id' => $user->id, 'module' => $module]);
        $from = $a->role_id;
        $a->company_id = $cid;
        $a->role_id = $v['role_id'] ?? null;
        $a->scope_ref = $v['scope_ref'] ?? $a->scope_ref;
        $a->save();
        $this->audit($module, 'user', $user->id, 'role', $from, $a->role_id);
        return $this->userShow($module, $user);
    }

    /** Grava/zera exceções pontuais do usuário. value=null remove a exceção. */
    public function setOverrides(Request $request, string $module, User $user): JsonResponse
    {
        $this->assertModule($module);
        $actor = $this->assertAdmin();
        $cid = $this->companyId();
        $v = $request->validate(['overrides' => 'required|array']);
        $caps = PolicyCatalog::keys($module);
        foreach ($v['overrides'] as $key => $value) {
            if (!isset($caps[$key])) continue; // ignora chave fora do catálogo
            if ($value === null || $value === '') {
                PolicyOverride::where('user_id', $user->id)->where('module', $module)->where('key', $key)->delete();
                continue;
            }
            $value = $this->coerce($caps[$key], $value);
            if ($value === null) continue; // valor inválido → ignora
            PolicyOverride::updateOrCreate(
                ['user_id' => $user->id, 'module' => $module, 'key' => $key],
                ['company_id' => $cid, 'value' => ['v' => $value], 'created_by_id' => $actor->id]
            );
        }
        $this->audit($module, 'user', $user->id, 'overrides', null, $v['overrides']);
        return $this->userShow($module, $user);
    }

    // ── Efetivo do usuário logado (FE esconde/mostra) ─────────────────────────
    public function me(Request $request): JsonResponse
    {
        $module = (string) $request->query('module', 'crm');
        $this->assertModule($module);
        return response()->json(['data' => $this->resolver->effective(auth()->user(), $module)]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────
    private function sanitizeMap(string $module, array $map): array
    {
        $caps = PolicyCatalog::keys($module);
        $out = [];
        foreach ($map as $k => $val) {
            if (!isset($caps[$k])) continue;
            $c = $this->coerce($caps[$k], $val);
            if ($c !== null) $out[$k] = $c;
        }
        return $out;
    }

    /** Coage/valida o valor conforme o tipo do controle. Retorna null se inválido. */
    private function coerce(array $cap, $value)
    {
        if ($cap['control'] === 'toggle') return (bool) $value;
        // scope
        $opts = $cap['options'] ?? ['all', 'team', 'own', 'none'];
        return in_array($value, $opts, true) ? $value : null;
    }

    private function audit(string $module, string $targetType, ?int $targetId, ?string $key, $from, $to): void
    {
        DB::table('policy_audit')->insert([
            'company_id' => $this->companyId(), 'module' => $module,
            'target_type' => $targetType, 'target_id' => $targetId, 'key' => $key,
            'from_value' => json_encode($from), 'to_value' => json_encode($to),
            'actor_id' => auth()->id(), 'created_at' => now(),
        ]);
    }
}
