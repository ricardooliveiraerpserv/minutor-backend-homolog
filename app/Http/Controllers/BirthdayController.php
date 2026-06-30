<?php

namespace App\Http\Controllers;

use App\Models\BirthdayMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parabenização de aniversários: quem faz aniversário hoje + envio/leitura de mensagens
 * de parabéns entre a equipe. Engajamento interno — só usuários internos (cliente não participa).
 */
class BirthdayController extends Controller
{
    /** Aniversariantes de hoje + estado do usuário atual (é aniversário dele? quantas msgs recebeu?). */
    public function today(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);

        $today = now();
        $birthdays = User::query()
            ->where('enabled', true)
            ->where('type', '!=', 'cliente')
            ->whereNotNull('birth_date')
            ->whereRaw('extract(month from birth_date) = ?', [$today->month])
            ->whereRaw('extract(day from birth_date) = ?', [$today->day])
            ->orderBy('name')
            ->get(['id', 'name', 'full_name']);

        // Quais já recebi parabéns deste usuário hoje (p/ desabilitar reenvio).
        $sentToday = BirthdayMessage::where('from_user_id', $u->id)
            ->whereDate('created_at', $today->toDateString())
            ->pluck('to_user_id')->all();

        $list = $birthdays->map(fn (User $b) => [
            'id'         => $b->id,
            'name'       => $b->name,
            'is_me'      => $b->id === $u->id,
            'already_sent' => in_array($b->id, $sentToday, true),
        ])->values();

        $isMyBirthday = $birthdays->contains('id', $u->id);
        $received = $isMyBirthday
            ? BirthdayMessage::where('to_user_id', $u->id)->whereDate('created_at', $today->toDateString())->count()
            : 0;

        return response()->json(['data' => [
            'birthdays'        => $list,
            'is_my_birthday'   => $isMyBirthday,
            'received_count'   => $received,
        ]]);
    }

    /** Envia parabéns ao aniversariante (só no dia do aniversário dele). */
    public function sendMessage(Request $request, User $user): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_if($user->id === $u->id, 422, 'Você não pode parabenizar a si mesmo.');
        abort_unless($this->isBirthdayToday($user), 422, 'Hoje não é o aniversário desta pessoa.');

        $v = $request->validate([
            'message' => 'nullable|string|max:200',
        ]);
        $message = trim((string) ($v['message'] ?? ''));

        // Uma mensagem por remetente por dia (evita poluição) — reenvio atualiza a anterior.
        $msg = BirthdayMessage::firstOrNew([
            'from_user_id' => $u->id,
            'to_user_id'   => $user->id,
        ]);
        // só considera "de hoje" — se a anterior é de outro dia, cria nova
        if ($msg->exists && optional($msg->created_at)->toDateString() !== now()->toDateString()) {
            $msg = new BirthdayMessage(['from_user_id' => $u->id, 'to_user_id' => $user->id]);
        }
        $msg->message = $message !== '' ? $message : '🎉';
        $msg->save();

        return response()->json(['data' => ['sent' => true]], 201);
    }

    /** Lista mensagens recebidas pelo aniversariante (só ele mesmo ou admin). */
    public function messages(Request $request, User $user): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_unless($u->id === $user->id || $u->isAdmin(), 403, 'Você só pode ver as suas mensagens.');

        $rows = BirthdayMessage::with('fromUser:id,name')
            ->where('to_user_id', $user->id)
            ->whereDate('created_at', now()->toDateString())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (BirthdayMessage $m) => [
                'id'        => $m->id,
                'from_name' => $m->fromUser?->name ?? 'Alguém',
                'message'   => $m->message,
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    /** Aniversário do usuário é hoje? (mesmo dia e mês). */
    private function isBirthdayToday(User $user): bool
    {
        if (!$user->birth_date) return false;
        $today = now();
        return (int) $user->birth_date->month === $today->month
            && (int) $user->birth_date->day === $today->day;
    }
}
