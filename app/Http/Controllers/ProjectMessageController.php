<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMessage;
use App\Models\ProjectMessageMention;
use App\Models\ProjectMessageRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectMessageController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$this->userCanAccessProject($user, $project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $messages = ProjectMessage::where('project_id', $project->id)
            ->with(['author:id,name,profile_photo', 'reads' => fn($q) => $q->where('user_id', $user->id)])
            ->withExists(['mentions as is_mentioned' => fn($q) => $q->where('mentioned_user_id', $user->id)])
            ->latest()
            ->paginate(50);

        return response()->json($messages);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$this->userCanAccessProject($user, $project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $request->validate([
            'message'  => 'required|string|max:2000',
            'priority' => 'in:normal,high',
        ]);

        $msg = ProjectMessage::create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'message'    => $request->message,
            'priority'   => $request->input('priority', 'normal'),
        ]);

        // Parse mention tokens @[id:Name]
        preg_match_all('/@\[(\d+):([^\]]+)\]/', $request->message, $matches);
        foreach (array_unique($matches[1]) as $mentionedId) {
            ProjectMessageMention::firstOrCreate([
                'message_id'        => $msg->id,
                'mentioned_user_id' => (int) $mentionedId,
            ]);
        }

        $msg->load('author:id,name,profile_photo');
        $msg->is_mentioned = false;

        return response()->json($msg, 201);
    }

    public function markRead(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$this->userCanAccessProject($user, $project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $unreadIds = ProjectMessage::where('project_id', $project->id)
            ->whereDoesntHave('reads', fn($q) => $q->where('user_id', $user->id))
            ->pluck('id');

        if ($unreadIds->isNotEmpty()) {
            $rows = $unreadIds->map(fn($id) => ['message_id' => $id, 'user_id' => $user->id])->toArray();
            ProjectMessageRead::upsert($rows, ['message_id', 'user_id']);
        }

        return response()->json(['marked' => $unreadIds->count()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ProjectMessage::query();

        // Scope to user's projects
        if ($user->isCoordenador()) {
            $query->whereHas('project', fn($q) => $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $user->id)));
        } elseif (!$user->isAdmin()) {
            $query->whereHas('project', fn($q) => $q->whereHas('consultants', fn($sq) => $sq->where('users.id', $user->id)));
        }

        $count = $query->where(fn($q) => $q
            ->whereDoesntHave('reads', fn($r) => $r->where('user_id', $user->id))
            ->orWhereHas('mentions', fn($r) => $r->where('mentioned_user_id', $user->id)
                ->whereDoesntHave('message.reads', fn($rr) => $rr->where('user_id', $user->id)))
        )->count();

        return response()->json(['count' => $count]);
    }

    private function userCanAccessProject($user, Project $project): bool
    {
        if ($user->isCoordenador()) {
            return $project->coordinators()->where('users.id', $user->id)->exists();
        }
        return $project->consultants()->where('users.id', $user->id)->exists();
    }
}
