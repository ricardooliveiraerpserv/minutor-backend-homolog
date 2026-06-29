<?php

namespace App\Http\Controllers;

use App\Models\ProfileCargo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vínculo Cargo × Perfil. Admin registra o cargo padrão de cada perfil (users.type).
 * Esse cargo alimenta a assinatura/perfil do usuário. NÃO altera permissões nem user.type.
 */
class ProfileCargoController extends Controller
{
    private function denyIfNotAdmin(Request $request): ?JsonResponse
    {
        $u = $request->user();
        return ($u && $u->isAdmin()) ? null : response()->json(['message' => 'Acesso negado'], 403);
    }

    /** Lista os perfis com seu cargo efetivo (linha cadastrada OU default). */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) {
            return $deny;
        }
        $data = [];
        foreach (ProfileCargo::PROFILES as $p) {
            $data[] = ['profile' => $p, 'cargo' => ProfileCargo::forProfile($p)];
        }
        return response()->json(['data' => $data]);
    }

    /** Upsert do cargo de um perfil. */
    public function update(Request $request, string $profile): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) {
            return $deny;
        }
        if (!in_array($profile, ProfileCargo::PROFILES, true)) {
            return response()->json(['message' => 'Perfil inválido'], 422);
        }
        $v = $request->validate(['cargo' => 'required|string|max:120']);

        $rec = ProfileCargo::updateOrCreate(['profile' => $profile], ['cargo' => trim($v['cargo'])]);

        return response()->json(['ok' => true, 'data' => ['profile' => $rec->profile, 'cargo' => $rec->cargo]]);
    }
}
