<?php

namespace App\Http\Controllers;

use App\Models\Company;
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
}
