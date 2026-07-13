<?php

namespace App\Http\Controllers;

use App\Enums\PresenceStatus;
use App\Models\User;
use App\Models\UserPresence;
use App\Services\Inbox\PresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function __construct(protected PresenceService $service)
    {
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|string|in:online,away,offline',
        ]);

        $status = isset($data['status']) ? PresenceStatus::from($data['status']) : PresenceStatus::Online;
        $presence = $this->service->heartbeat($request->user(), $status);

        return response()->json([
            'status'       => $presence->status->value,
            'last_seen_at' => $presence->last_seen_at?->toIso8601String(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $userIds = $request->query('user_ids');
        $ids = is_string($userIds) ? array_filter(array_map('intval', explode(',', $userIds))) : [];

        return response()->json(['data' => $this->service->snapshot($ids)]);
    }

    /**
     * Usuários REALMENTE online/ausentes agora — pela tabela de presença, não por ordem
     * alfabética. (O /conversations/users limita aos 50 primeiros por nome, então quem está
     * online mas fora desse recorte nunca aparecia no painel.)
     */
    public function online(Request $request): JsonResponse
    {
        $me = $request->user();
        $cutoff = now()->subMinutes(PresenceService::OFFLINE_AFTER_MINUTES);

        $rows = UserPresence::query()
            ->where('last_seen_at', '>=', $cutoff)
            ->when($me, fn ($q) => $q->where('user_id', '!=', $me->id))
            ->orderByDesc('last_seen_at')
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id'))
            ->where('enabled', true)
            ->get()
            ->keyBy('id');

        $data = [];
        foreach ($rows as $p) {
            $u = $users->get($p->user_id);
            if (! $u) {
                continue; // usuário desabilitado/removido
            }
            $status = $this->service->effectiveStatus($p);
            if ($status === PresenceStatus::Offline) {
                continue; // stale — passou da janela
            }
            $data[] = [
                'id'            => $u->id,
                'name'          => $u->name,
                'email'         => $u->email,
                'profile_photo' => $u->profile_photo_url,
                'presence'      => [
                    'status'       => $status->value,
                    'last_seen_at' => $p->last_seen_at?->toIso8601String(),
                ],
            ];
        }

        return response()->json(['data' => $data]);
    }
}
