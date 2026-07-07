<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lembretes pessoais do usuário (CRUD). Tudo escopado ao próprio usuário. */
class ReminderController extends Controller
{
    /** Lembretes do dia: pendentes (sem data ou até hoje) + os concluídos hoje. */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        $today = now()->toDateString();

        $rows = Reminder::where('user_id', $u->id)
            ->where(function ($q) use ($today) {
                $q->where('completed', false)->where(fn ($w) => $w->whereNull('due_date')->orWhere('due_date', '<=', $today))
                  ->orWhere(fn ($w) => $w->where('completed', true)->whereDate('updated_at', $today));
            })
            ->orderBy('completed')
            ->orderByRaw('due_time is null')   // com horário primeiro
            ->orderBy('due_time')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (Reminder $r) => $this->serialize($r))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        $v = $this->validatePayload($request, true);
        $r = Reminder::create(array_merge($v, ['user_id' => $u->id, 'completed' => (bool) ($v['completed'] ?? false)]));
        return response()->json(['data' => $this->serialize($r)], 201);
    }

    public function update(Request $request, Reminder $reminder): JsonResponse
    {
        $this->authorizeOwner($request, $reminder);
        $reminder->update($this->validatePayload($request, false));
        return response()->json(['data' => $this->serialize($reminder->fresh())]);
    }

    public function destroy(Request $request, Reminder $reminder): JsonResponse
    {
        $this->authorizeOwner($request, $reminder);
        $reminder->delete();
        return response()->json(null, 204);
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'text'      => ($creating ? 'required' : 'sometimes') . '|string|max:500',
            'due_date'  => 'nullable|date',
            'due_time'  => 'nullable|date_format:H:i',
            'completed' => 'nullable|boolean',
        ]);
    }

    private function authorizeOwner(Request $request, Reminder $reminder): void
    {
        abort_unless($request->user() && $reminder->user_id === $request->user()->id, 403);
    }

    private function serialize(Reminder $r): array
    {
        return [
            'id'        => $r->id,
            'text'      => $r->text,
            'due_date'  => $r->due_date?->toDateString(),
            'due_time'  => $r->due_time ? substr((string) $r->due_time, 0, 5) : null,
            'completed' => $r->completed,
        ];
    }
}
