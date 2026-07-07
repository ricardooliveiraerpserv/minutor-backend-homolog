<?php

namespace App\Http\Controllers;

use App\Models\NotificationPoll;
use App\Models\NotificationPollVote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Enquetes da Central de Notificações — votação + resultados + auditoria de votos. */
class NotificationPollController extends Controller
{
    /** Registra o voto do usuário. Respeita multiple_choice, allow_change_vote e expiração. */
    public function vote(Request $request, NotificationPoll $poll): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_if($poll->isClosed(), 422, 'Esta enquete está encerrada.');

        $poll->load('options');
        $validIds = $poll->options->pluck('id')->all();

        $v = $request->validate([
            'option_ids'   => 'required_without:option_id|array',
            'option_ids.*' => 'integer',
            'option_id'    => 'required_without:option_ids|integer',
        ]);

        // Normaliza p/ lista (single = 1 item).
        $chosen = $poll->multiple_choice
            ? array_values(array_unique(array_map('intval', $v['option_ids'] ?? [])))
            : [(int) ($v['option_id'] ?? ($v['option_ids'][0] ?? 0))];
        $chosen = array_values(array_filter($chosen, fn ($id) => in_array($id, $validIds, true)));

        abort_if(empty($chosen), 422, 'Selecione ao menos uma opção válida.');
        abort_if(!$poll->multiple_choice && count($chosen) > 1, 422, 'Esta enquete aceita apenas uma opção.');

        $already = NotificationPollVote::where('poll_id', $poll->id)->where('user_id', $u->id)->exists();
        abort_if($already && !$poll->allow_change_vote, 422, 'Você já votou e a alteração de voto não está habilitada.');

        DB::transaction(function () use ($poll, $u, $chosen) {
            // Substitui o voto anterior (troca de voto / refazer seleção múltipla).
            NotificationPollVote::where('poll_id', $poll->id)->where('user_id', $u->id)->delete();
            foreach ($chosen as $optId) {
                NotificationPollVote::create(['poll_id' => $poll->id, 'option_id' => $optId, 'user_id' => $u->id]);
            }
        });

        return response()->json(['data' => self::results($poll, $u)]);
    }

    /** Resultados agregados + voto do usuário atual. */
    public function resultsEndpoint(Request $request, NotificationPoll $poll): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        return response()->json(['data' => self::results($poll, $u)]);
    }

    /** Auditoria detalhada: quem votou em quê e quando (admin). */
    public function voters(Request $request, NotificationPoll $poll): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $rows = NotificationPollVote::with(['user:id,name,email', 'option:id,label'])
            ->where('poll_id', $poll->id)->orderBy('created_at')->get()
            ->map(fn (NotificationPollVote $vt) => [
                'user_id'    => $vt->user_id,
                'user_name'  => $vt->user?->name,
                'user_email' => $vt->user?->email,
                'option_id'  => $vt->option_id,
                'option'     => $vt->option?->label,
                'voted_at'   => $vt->created_at?->toIso8601String(),
            ]);
        return response()->json(['data' => $rows]);
    }

    /**
     * Payload da enquete p/ um usuário: opções com contagem/percentual, total, voto do usuário,
     * flags (encerrada, já votou). REUSADO pelo NotificationController@index.
     *
     * @return array<string,mixed>
     */
    public static function results(NotificationPoll $poll, ?User $user): array
    {
        $poll->loadMissing('options');
        $counts = NotificationPollVote::where('poll_id', $poll->id)
            ->select('option_id', DB::raw('count(*) as c'))->groupBy('option_id')->pluck('c', 'option_id');
        $total  = (int) $counts->sum();

        $myVotes = $user
            ? NotificationPollVote::where('poll_id', $poll->id)->where('user_id', $user->id)->pluck('option_id')->all()
            : [];

        $options = $poll->options->map(function ($o) use ($counts, $total, $myVotes) {
            $c = (int) ($counts[$o->id] ?? 0);
            return [
                'id'      => $o->id,
                'label'   => $o->label,
                'order'   => $o->order,
                'votes'   => $c,
                'percent' => $total > 0 ? round($c * 100 / $total, 1) : 0.0,
                'mine'    => in_array($o->id, $myVotes, true),
            ];
        })->values();

        return [
            'poll_id'           => $poll->id,
            'notification_id'   => $poll->notification_id,
            'question'          => $poll->question,
            'multiple_choice'   => $poll->multiple_choice,
            'allow_change_vote' => $poll->allow_change_vote,
            'show_results'      => $poll->show_results,
            'expires_at'        => $poll->expires_at?->toIso8601String(),
            'closed'            => $poll->isClosed(),
            'options'           => $options,
            'total_votes'       => $total,
            'has_voted'         => count($myVotes) > 0,
            'my_option_ids'     => array_map('intval', $myVotes),
        ];
    }
}
