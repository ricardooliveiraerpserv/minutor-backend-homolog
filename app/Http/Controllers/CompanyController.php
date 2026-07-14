<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Multi-empresa — contexto do usuário. (Gestão administrativa de empresas
 * vem no módulo Empresas, fase 6.)
 */
class CompanyController extends Controller
{
    /** Empresas do usuário logado (com o papel em cada) + qual é a ativa. */
    public function myCompanies(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $user->companies()
            ->where('companies.status', 'active')
            ->orderBy('companies.name')
            ->get()
            ->map(fn (Company $c) => [
                'id'     => $c->id,
                'name'   => $c->name,
                'slug'   => $c->slug,
                'color'  => $c->color,
                'type'   => $c->type,
                'role'   => $c->pivot->role,
                'active' => $c->id === $user->current_company_id,
            ]);

        return response()->json([
            'data'              => $data,
            'active_company_id' => $user->current_company_id,
        ]);
    }

    /** Troca a empresa ativa (default persistido) — sem logout. */
    public function setCompany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
        ]);
        $user = $request->user();

        if (!$user->belongsToCompany((int) $data['company_id'])) {
            return response()->json(['message' => 'Você não tem acesso a esta empresa.'], 403);
        }

        $user->current_company_id = (int) $data['company_id'];
        $user->save();

        return response()->json([
            'message'           => 'Empresa ativa alterada.',
            'active_company_id' => $user->current_company_id,
        ]);
    }

    // ── Módulo Empresas (gestão administrativa) ──────────────────────────────
    private const ROLES = ['admin', 'administrativo', 'coordenador', 'consultor', 'cliente', 'parceiro_admin'];

    private function denyNonAdmin(Request $request): ?JsonResponse
    {
        return $request->user()?->isAdmin() ? null : response()->json(['message' => 'Sem permissão.'], 403);
    }

    /** Lista TODAS as empresas (gestão) com contagem de usuários. */
    public function index(Request $request): JsonResponse
    {
        if ($r = $this->denyNonAdmin($request)) return $r;

        $data = Company::withCount('users')->orderBy('name')->get()->map(fn (Company $c) => [
            'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'color' => $c->color, 'cnpj' => $c->cnpj,
            'type' => $c->type, 'status' => $c->status, 'users_count' => $c->users_count,
        ]);
        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($r = $this->denyNonAdmin($request)) return $r;

        $data = $request->validate([
            'name'   => 'required|string|max:255',
            'color'  => 'nullable|string|max:9',
            'cnpj'   => 'nullable|string|max:20',
            'type'   => 'required|in:internal,external',
            'status' => 'required|in:active,inactive',
        ]);
        $company = Company::create([...$data, 'slug' => Company::makeSlug($data['name'])]);
        return response()->json(['data' => $company], 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        if ($r = $this->denyNonAdmin($request)) return $r;

        $data = $request->validate([
            'name'   => 'sometimes|string|max:255',
            'color'  => 'nullable|string|max:9',
            'cnpj'   => 'nullable|string|max:20',
            'type'   => 'sometimes|in:internal,external',
            'status' => 'sometimes|in:active,inactive',
        ]);
        $company->update($data);
        return response()->json(['data' => $company->fresh()]);
    }

    /** Usuários vinculados à empresa, com o papel. */
    public function companyUsers(Request $request, Company $company): JsonResponse
    {
        if ($r = $this->denyNonAdmin($request)) return $r;

        $users = $company->users()->orderBy('name')->get()->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->pivot->role,
        ]);
        return response()->json(['data' => $users]);
    }

    public function attachUser(Request $request, Company $company): JsonResponse
    {
        if ($r = $this->denyNonAdmin($request)) return $r;

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role'    => 'required|in:' . implode(',', self::ROLES),
        ]);
        $company->users()->syncWithoutDetaching([$data['user_id'] => ['role' => $data['role']]]);
        return response()->json(['message' => 'Usuário vinculado.']);
    }

    public function updateUserRole(Request $request, Company $company, User $user): JsonResponse
    {
        if ($r = $this->denyNonAdmin($request)) return $r;

        $data = $request->validate(['role' => 'required|in:' . implode(',', self::ROLES)]);
        if ($data['role'] !== 'admin' && $this->isLastAdmin($company, $user->id)) {
            return response()->json(['message' => 'A empresa precisa de ao menos 1 admin.'], 422);
        }
        $company->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
        return response()->json(['message' => 'Papel atualizado.']);
    }

    public function detachUser(Request $request, Company $company, User $user): JsonResponse
    {
        if ($r = $this->denyNonAdmin($request)) return $r;

        if ($this->isLastAdmin($company, $user->id)) {
            return response()->json(['message' => 'A empresa precisa de ao menos 1 admin.'], 422);
        }
        $company->users()->detach($user->id);
        return response()->json(['message' => 'Usuário removido.']);
    }

    /** É o último admin da empresa? (bloqueia rebaixar/remover). */
    private function isLastAdmin(Company $company, int $userId): bool
    {
        $adminIds = $company->users()->wherePivot('role', 'admin')->pluck('users.id')->all();
        return in_array($userId, $adminIds, true) && count($adminIds) <= 1;
    }
}
