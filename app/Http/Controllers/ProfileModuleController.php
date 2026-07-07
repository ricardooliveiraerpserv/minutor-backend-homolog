<?php

namespace App\Http\Controllers;

use App\Models\ProfileModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cadastro de Perfil → Módulos de navegação. Admin define quais módulos
 * (Serviços / Administrativo) cada perfil enxerga. NÃO mexe em permissões.
 */
class ProfileModuleController extends Controller
{
    private function denyIfNotAdmin(Request $request): ?JsonResponse
    {
        $u = $request->user();
        return ($u && $u->isAdmin()) ? null : response()->json(['message' => 'Acesso negado'], 403);
    }

    /** Lista os perfis (exceto cliente) com seus módulos efetivos (linha OU default). */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) {
            return $deny;
        }
        $data = [];
        foreach (ProfileModule::PROFILES as $p) {
            $data[] = ['profile' => $p, 'modules' => ProfileModule::forProfile($p)];
        }
        return response()->json(['data' => $data]);
    }

    /** Upsert dos módulos de um perfil. */
    public function update(Request $request, string $profile): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) {
            return $deny;
        }
        if (!in_array($profile, ProfileModule::PROFILES, true)) {
            return response()->json(['message' => 'Perfil inválido'], 422);
        }
        $v = $request->validate([
            'modules'   => 'present|array',
            'modules.*' => 'in:servicos,administrativo',
        ]);
        $modules = array_values(array_intersect(ProfileModule::MODULES, $v['modules']));

        $rec = ProfileModule::updateOrCreate(['profile' => $profile], ['modules' => $modules]);

        return response()->json(['ok' => true, 'data' => ['profile' => $rec->profile, 'modules' => $rec->modules]]);
    }
}
