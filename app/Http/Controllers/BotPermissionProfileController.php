<?php

namespace App\Http\Controllers;

use App\Models\BotPermissionProfile;
use App\Services\Ai\Tools\MinutorToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD da política padrão do BOT por perfil de user.
 *
 * GET    /api/v1/bot/permission-profiles            → lista as 6 políticas
 * PUT    /api/v1/bot/permission-profiles/{type}     → atualiza a política
 */
class BotPermissionProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $items = BotPermissionProfile::query()->orderBy('id')->get();
        return response()->json([
            'data'       => $items,
            'all_scopes' => MinutorToolRegistry::ALL_SCOPES,
            'visibilities' => ['self', 'team', 'all'],
        ]);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        $profile = BotPermissionProfile::query()->where('profile_type', $type)->firstOrFail();

        $data = $request->validate([
            'label'           => 'sometimes|string|max:80',
            'description'     => 'sometimes|nullable|string|max:500',
            'can_use_bot'     => 'sometimes|boolean',
            'allowed_scopes'  => 'sometimes|nullable|array',
            'allowed_scopes.*'=> 'string|in:' . implode(',', MinutorToolRegistry::ALL_SCOPES),
            'visibility'      => 'sometimes|in:self,team,all',
            'scope_overrides' => 'sometimes|nullable|array',
        ]);

        $profile->update($data);
        return $this->index();
    }
}
