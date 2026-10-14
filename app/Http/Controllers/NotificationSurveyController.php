<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\NotificationRead;
use App\Models\NotificationSurvey;
use App\Models\NotificationSurveyResponse;
use App\Models\NotificationSurveyAnswer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * PESQUISA (survey) = publicação type='survey' com formulário de várias perguntas
 * (texto livre / pessoa / múltipla escolha). Anonimato definido na criação.
 * Fluxo espelha a enquete, mas com N perguntas e respostas abertas.
 */
class NotificationSurveyController extends Controller
{
    // ───────────────────────── Payloads (pop-up + resultados) ─────────────────────────

    /** Form da pesquisa p/ o pop-up/Central + se o usuário já respondeu. */
    public static function formPayload(NotificationSurvey $survey, ?User $u): array
    {
        $survey->loadMissing('questions.options');
        $myResponse = $u
            ? NotificationSurveyResponse::where('survey_id', $survey->id)->where('user_id', $u->id)->first()
            : null;
        // Anônima: o vínculo some, mas o ack no notification_reads marca que já respondeu.
        $ackedResponded = false;
        $repopAt = null; // muda quando o admin revoga (touch no read) → o pop-up reabre só p/ essa pessoa
        if ($u) {
            $read = NotificationRead::where('notification_id', $survey->notification_id)->where('user_id', $u->id)->first();
            $ackedResponded = (bool) ($read && $read->ack_at);
            $repopAt = $read?->updated_at?->toIso8601String();
        }

        // Lista de pessoas p/ perguntas tipo "pessoa" (evita fetch extra no pop-up).
        $needPeople = $survey->questions->contains(fn ($q) => $q->type === 'person');
        $people = $needPeople
            ? User::query()->whereNull('customer_id')->where('enabled', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()
            : collect();

        return [
            'survey_id'     => $survey->id,
            'anonymous'     => (bool) $survey->anonymous,
            'closed'        => $survey->isClosed(),
            'has_responded' => (bool) $myResponse || $ackedResponded,
            'repop_at'      => $repopAt,
            'people'        => $people,
            'questions'     => $survey->questions->map(fn ($q) => [
                'id'       => $q->id,
                'label'    => $q->label,
                'type'     => $q->type,
                'required' => (bool) $q->required,
                'multiple' => (bool) $q->multiple,
                'options'  => $q->type === 'choice'
                    ? $q->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label])->values()
                    : [],
            ])->values(),
        ];
    }

    /** Agregação de resultados (admin): por pergunta + lista de respondentes + totais. */
    public static function resultsPayload(NotificationSurvey $survey): array
    {
        $survey->loadMissing(['questions.options', 'responses.user', 'responses.answers.answerUser', 'responses.answers.option']);
        $anon = (bool) $survey->anonymous;

        // Admin SEMPRE vê quem respondeu — mesmo em pesquisa anônima (identificação
        // interna/confidencial; o respondente não sabe que registramos). null só p/
        // respostas legadas gravadas antes de passarmos a vincular as anônimas.
        $respondentName = function (NotificationSurveyResponse $r) {
            return $r->user?->name;
        };

        // Respostas por pergunta (p/ montar agregados por pergunta).
        $answersByQuestion = $survey->responses
            ->flatMap(fn ($r) => $r->answers->map(fn ($a) => ['a' => $a, 'r' => $r]))
            ->groupBy(fn ($x) => $x['a']->question_id);

        $questions = $survey->questions->map(function ($q) use ($answersByQuestion, $respondentName) {
            $rows = $answersByQuestion->get($q->id, collect());
            $base = ['id' => $q->id, 'label' => $q->label, 'type' => $q->type, 'required' => (bool) $q->required];

            if ($q->type === 'choice') {
                $byOpt = $rows->groupBy(fn ($x) => $x['a']->option_id);
                $base['options'] = $q->options->map(fn ($o) => [
                    'id' => $o->id, 'label' => $o->label, 'count' => (int) ($byOpt->get($o->id, collect())->count()),
                    // Quem votou nesta opção (nomes dos respondentes) p/ a legenda do gráfico.
                    'voters' => $byOpt->get($o->id, collect())->map(fn ($x) => $respondentName($x['r']))->filter()->values(),
                ])->values();
            } elseif ($q->type === 'person') {
                $base['people'] = $rows->groupBy(fn ($x) => $x['a']->answer_user_id)
                    ->map(fn ($grp, $uid) => [
                        'user_id' => (int) $uid,
                        'name'    => optional($grp->first()['a']->answerUser)->name ?? '—',
                        'count'   => $grp->count(),
                        // Quem votou nesta pessoa (nomes dos respondentes).
                        'voters'  => $grp->map(fn ($x) => $respondentName($x['r']))->filter()->values(),
                    ])
                    ->sortByDesc('count')->values();
            } else { // text
                $base['answers'] = $rows->map(fn ($x) => [
                    'text'       => (string) $x['a']->answer_text,
                    'respondent' => $respondentName($x['r']),
                ])->filter(fn ($a) => trim($a['text']) !== '')->values();
            }
            return $base;
        })->values();

        $questionsById = $survey->questions->keyBy('id');
        $respondents = $survey->responses->sortByDesc('submitted_at')->map(function ($r) use ($respondentName, $questionsById) {
            // Resposta por pergunta (string de exibição; múltipla escolha concatenada).
            $ans = [];
            foreach ($r->answers as $a) {
                $q = $questionsById->get($a->question_id);
                if (!$q) continue;
                $disp = $q->type === 'person' ? (optional($a->answerUser)->name ?? '—')
                    : ($q->type === 'choice' ? (optional($a->option)->label ?? '—')
                    : (string) $a->answer_text);
                $ans[$a->question_id] = isset($ans[$a->question_id]) ? ($ans[$a->question_id] . ', ' . $disp) : $disp;
            }
            return [
                'response_id'  => $r->id,
                'user_id'      => $r->user_id,
                'respondent'   => $respondentName($r),
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'answers'      => $ans, // { question_id: "texto exibível" }
            ];
        })->values();

        return [
            'survey_id'   => $survey->id,
            'anonymous'   => $anon,
            'total'       => $survey->responses->count(),
            'closed'      => $survey->isClosed(),
            'questions'   => $questions,
            'respondents' => $respondents,
        ];
    }

    // ───────────────────────── Submit (app logado) ─────────────────────────

    public function respond(Request $request, NotificationSurvey $survey): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_if($survey->isClosed(), 422, 'O prazo desta pesquisa já encerrou.');

        // 1 resposta por pessoa (identificada OU via ack quando anônima).
        $already = NotificationSurveyResponse::where('survey_id', $survey->id)->where('user_id', $u->id)->exists();
        $read = NotificationRead::where('notification_id', $survey->notification_id)->where('user_id', $u->id)->first();
        if ($already || ($read && $read->ack_at)) {
            return response()->json(['success' => false, 'message' => 'Você já respondeu esta pesquisa.'], 422);
        }

        $this->saveResponse($survey, $u, (array) $request->input('answers', []));
        $this->markAck($survey, $u->id);

        return response()->json(['data' => ['responded' => true]]);
    }

    // ───────────────────────── Resultados (admin) ─────────────────────────

    public function results(Request $request, NotificationSurvey $survey): JsonResponse
    {
        $this->authorizeAdmin($request, $survey);
        return response()->json(['data' => self::resultsPayload($survey)]);
    }

    /**
     * REVOGA a resposta de UM destinatário: apaga a resposta (e respostas por pergunta) e
     * limpa o ack → ele volta a PENDENTE e pode responder de novo (pop-up/e-mail reaparecem).
     * Em pesquisa anônima não dá p/ identificar a resposta por pessoa → só limpa o ack.
     */
    public function revoke(Request $request, NotificationSurvey $survey): JsonResponse
    {
        $this->authorizeAdmin($request, $survey);
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $uid  = (int) $data['user_id'];

        // O vínculo é SEMPRE gravado (mesmo anônima, p/ identificação interna) →
        // dá p/ apagar a resposta por pessoa. (answers caem por cascade.)
        NotificationSurveyResponse::where('survey_id', $survey->id)->where('user_id', $uid)->delete();
        // Volta a pendente: limpa o ack (bumpa updated_at → repop_at muda → o pop-up reabre SÓ
        // p/ essa pessoa, sem mexer nos demais) e recebe o e-mail de novo AGORA.
        $read = NotificationRead::where('notification_id', $survey->notification_id)->where('user_id', $uid)->first();
        if ($read) {
            $read->ack_at = null;
            $read->acked_version = null;
            $read->touch();      // garante updated_at novo mesmo se os campos já estavam null
            $read->save();
        }

        // Reenvia o e-mail da pesquisa para essa pessoa imediatamente (best-effort).
        $resent = false;
        $user = User::find($uid);
        if ($user) {
            try {
                $resent = app(\App\Http\Controllers\NotificationController::class)
                    ->emailNotificationToUser($survey->notification->loadMissing('survey'), $user);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Falha ao reenviar e-mail após revogar pesquisa', ['survey_id' => $survey->id, 'user_id' => $uid, 'erro' => $e->getMessage()]);
            }
        }

        return response()->json(['data' => ['revoked' => true, 'user_id' => $uid, 'email_resent' => $resent]]);
    }

    // ───────────────────────── E-mail (link assinado, sem login) ─────────────────────────

    /** URL assinada do botão "Responder pesquisa" no e-mail (por usuário). */
    public static function emailLink(NotificationSurvey $survey, User $u): string
    {
        $expires = $survey->expires_at && $survey->expires_at->isFuture() ? $survey->expires_at : now()->addWeek();
        return URL::temporarySignedRoute('surveys.email-form', $expires, ['survey' => $survey->id, 'user' => $u->id]);
    }

    /** Página HTML da pesquisa (GET assinado). */
    public function emailForm(Request $request, NotificationSurvey $survey)
    {
        $u = User::find((int) $request->query('user'));
        $survey->loadMissing(['questions.options', 'notification.company']);
        $title = e($survey->notification?->title ?? 'Pesquisa');
        $brand = $this->brandAssets($survey);

        if (!$u) {
            return response($this->emailResultPage($title, false, 'Link inválido.', $brand))->header('Content-Type', 'text/html; charset=UTF-8');
        }
        if ($survey->isClosed()) {
            return response($this->emailResultPage($title, false, 'O prazo desta pesquisa já encerrou.', $brand))->header('Content-Type', 'text/html; charset=UTF-8');
        }
        $already = NotificationSurveyResponse::where('survey_id', $survey->id)->where('user_id', $u->id)->exists();
        $read = NotificationRead::where('notification_id', $survey->notification_id)->where('user_id', $u->id)->first();
        if ($already || ($read && $read->ack_at)) {
            return response($this->emailResultPage($title, false, 'Você já respondeu esta pesquisa. Obrigado!', $brand))->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return response($this->emailFormPage($survey, $u, $title, $brand))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Submissão da pesquisa pelo e-mail (POST assinado). */
    public function emailSubmit(Request $request, NotificationSurvey $survey)
    {
        $u = User::find((int) $request->query('user'));
        $survey->loadMissing(['questions.options', 'notification.company']);
        $title = e($survey->notification?->title ?? 'Pesquisa');
        $brand = $this->brandAssets($survey);

        if (!$u || $survey->isClosed()) {
            return response($this->emailResultPage($title, false, 'Não foi possível registrar (link inválido ou prazo encerrado).', $brand))->header('Content-Type', 'text/html; charset=UTF-8');
        }
        $already = NotificationSurveyResponse::where('survey_id', $survey->id)->where('user_id', $u->id)->exists();
        $read = NotificationRead::where('notification_id', $survey->notification_id)->where('user_id', $u->id)->first();
        if ($already || ($read && $read->ack_at)) {
            return response($this->emailResultPage($title, false, 'Você já respondeu esta pesquisa. Obrigado!', $brand))->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $this->saveResponse($survey, $u, $this->normalizeEmailAnswers($request, $survey));
        $this->markAck($survey, $u->id);

        return response($this->emailResultPage($title, true, 'Pesquisa respondida. Muito obrigado! ✅', $brand))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    // ───────────────────────── Helpers ─────────────────────────

    /**
     * Grava a resposta + respostas de cada pergunta. O user_id é SEMPRE gravado
     * (mesmo anônima) — rastreio interno/confidencial: o respondente é informado
     * que é anônima, mas o admin consegue ver quem respondeu nos resultados.
     */
    private function saveResponse(NotificationSurvey $survey, User $u, array $answersInput): void
    {
        $survey->loadMissing('questions.options');
        $byQuestion = collect($answersInput)->keyBy(fn ($a) => (int) ($a['question_id'] ?? 0));

        DB::transaction(function () use ($survey, $u, $byQuestion) {
            $response = NotificationSurveyResponse::create([
                'survey_id'    => $survey->id,
                'user_id'      => $u->id,
                'submitted_at' => now(),
            ]);

            $rows = [];
            foreach ($survey->questions as $q) {
                $ans = $byQuestion->get($q->id, []);
                if ($q->type === 'text') {
                    $txt = trim((string) ($ans['text'] ?? ''));
                    if ($txt !== '') {
                        $rows[] = ['response_id' => $response->id, 'question_id' => $q->id, 'answer_text' => $txt, 'answer_user_id' => null, 'option_id' => null, 'created_at' => now(), 'updated_at' => now()];
                    }
                } elseif ($q->type === 'person') {
                    $uid = isset($ans['user_id']) && $ans['user_id'] !== '' ? (int) $ans['user_id'] : null;
                    if ($uid) {
                        $rows[] = ['response_id' => $response->id, 'question_id' => $q->id, 'answer_text' => null, 'answer_user_id' => $uid, 'option_id' => null, 'created_at' => now(), 'updated_at' => now()];
                    }
                } else { // choice
                    $validOptIds = $q->options->pluck('id')->all();
                    $picked = $ans['option_ids'] ?? ($ans['option_id'] ?? []);
                    $picked = is_array($picked) ? $picked : [$picked];
                    $picked = array_values(array_unique(array_filter(array_map('intval', $picked))));
                    if (!$q->multiple) $picked = array_slice($picked, 0, 1);
                    foreach ($picked as $oid) {
                        if (in_array($oid, $validOptIds, true)) {
                            $rows[] = ['response_id' => $response->id, 'question_id' => $q->id, 'answer_text' => null, 'answer_user_id' => null, 'option_id' => $oid, 'created_at' => now(), 'updated_at' => now()];
                        }
                    }
                }
            }
            if ($rows) NotificationSurveyAnswer::insert($rows);
        });
    }

    /** Marca que o usuário respondeu (sai do pop-up e evita reenvio/duplicidade). */
    private function markAck(NotificationSurvey $survey, int $uid): void
    {
        $n = $survey->notification;
        $r = NotificationRead::firstOrNew(['notification_id' => $survey->notification_id, 'user_id' => $uid]);
        $r->viewed_at     = $r->viewed_at ?? now();
        $r->ack_at        = now();
        $r->acked_version = $n?->version ?? 1;
        $r->save();
    }

    /** Normaliza os campos do form HTML do e-mail para o formato de answers. */
    private function normalizeEmailAnswers(Request $request, NotificationSurvey $survey): array
    {
        $raw = (array) $request->input('q', []); // q[<question_id>] = valor | [valores]
        $out = [];
        foreach ($survey->questions as $q) {
            $v = $raw[$q->id] ?? null;
            if ($q->type === 'text') {
                $out[] = ['question_id' => $q->id, 'text' => is_array($v) ? '' : (string) $v];
            } elseif ($q->type === 'person') {
                $out[] = ['question_id' => $q->id, 'user_id' => is_array($v) ? ($v[0] ?? '') : $v];
            } else {
                $out[] = ['question_id' => $q->id, 'option_ids' => is_array($v) ? $v : ($v !== null && $v !== '' ? [$v] : [])];
            }
        }
        return $out;
    }

    private function authorizeAdmin(Request $request, NotificationSurvey $survey): void
    {
        $user = $request->user();
        $n = $survey->notification;
        $isOwner = $n && (int) $n->created_by === (int) ($user->id ?? 0);
        abort_unless($user && ($user->isAdmin() || (method_exists($user, 'isAdministrativo') && $user->isAdministrativo()) || $isOwner), 403);
    }

    /** Lista de usuários internos p/ o <select> de "pessoa" na página de e-mail. */
    private function internalUsers(): \Illuminate\Support\Collection
    {
        return User::query()->whereNull('customer_id')->where('enabled', true)
            ->orderBy('name')->get(['id', 'name']);
    }

    // ── Marca (ERPSERV × BIZIFY) p/ brandar as páginas de e-mail ──

    /** Resolve a marca da pesquisa pela empresa da notificação (slug erpserv/bizify) + logo base64. */
    private function brandAssets(NotificationSurvey $survey): array
    {
        $survey->loadMissing('notification.company');
        $isBizify = $survey->notification?->company?->slug === 'bizify';
        $file = $isBizify ? 'logo-bizify.png' : 'logo-erpserv-soft.png';
        $path = public_path($file);
        if (!is_file($path)) $path = public_path('logo-erpserv-soft.png');
        $logo = is_file($path) ? 'data:image/png;base64,' . base64_encode(file_get_contents($path)) : '';
        return [
            'is_bizify' => $isBizify,
            'logo'      => $logo,
            'name'      => $isBizify ? 'Bizify' : 'ERPSERV Consultoria',
            'accent'    => $isBizify ? '#7c3aed' : '#06b6d4', // roxo bizify / ciano erpserv
        ];
    }

    /** Bloco do logo no header (o logo Bizify tem fundo claro → vai num chip branco). */
    private function brandLogoHtml(array $brand): string
    {
        if (empty($brand['logo'])) return '';
        if (!empty($brand['is_bizify'])) {
            return '<div style="display:inline-block;background:#ffffff;padding:9px 20px;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.25)">'
                . '<img src="' . $brand['logo'] . '" alt="Bizify" style="height:36px;display:block"></div>';
        }
        return '<img src="' . $brand['logo'] . '" alt="ERPSERV" style="height:46px;display:block;margin:0 auto">';
    }

    // ── Páginas HTML do e-mail (dark, mesmo estilo das demais) ──

    private function emailResultPage(string $title, bool $ok, string $msg, array $brand = []): string
    {
        $accent = $brand['accent'] ?? '#06b6d4';
        $color  = $ok ? $accent : '#b91c1c';
        $logo   = $this->brandLogoHtml($brand);
        $name   = $brand['name'] ?? '';
        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $title . '</title></head>'
            . '<body style="margin:0;font-family:Segoe UI,Arial,sans-serif;background:#0b0f14;color:#e5e7eb;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px">'
            . '<div style="max-width:460px;width:100%;padding:34px 32px;text-align:center;background:#111827;border:1px solid #1f2937;border-top:4px solid ' . $accent . ';border-radius:16px">'
            . ($logo ? '<div style="margin:0 0 20px">' . $logo . '</div>' : '')
            . '<div style="font-size:38px;line-height:1;margin-bottom:10px;color:' . $color . '">' . ($ok ? '&#10003;' : '&#9888;') . '</div>'
            . '<h1 style="font-size:18px;margin:0 0 6px;color:#fff">' . $title . '</h1>'
            . '<p style="font-size:14px;color:#9ca3af;margin:0">' . $msg . '</p>'
            . ($name ? '<p style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;margin:18px 0 0">' . $name . '</p>' : '')
            . '<p style="font-size:12px;color:#6b7280;margin:6px 0 0">Você já pode fechar esta janela.</p>'
            . '</div></body></html>';
    }

    private function emailFormPage(NotificationSurvey $survey, User $u, string $title, array $brand = []): string
    {
        $expires = $survey->expires_at && $survey->expires_at->isFuture() ? $survey->expires_at : now()->addWeek();
        $action = e(URL::temporarySignedRoute('surveys.email-submit', $expires, ['survey' => $survey->id, 'user' => $u->id]));
        $anon = (bool) $survey->anonymous;
        $accent = $brand['accent'] ?? '#06b6d4';
        $needPeople = $survey->questions->contains(fn ($q) => $q->type === 'person');
        // Itens do combobox de pessoa (busca ao vivo); data-name em minúsculo p/ o filtro.
        $peopleItems = '';
        if ($needPeople) {
            foreach ($this->internalUsers() as $p) {
                $peopleItems .= '<div class="popt" data-id="' . (int) $p->id . '" data-name="' . e(mb_strtolower($p->name)) . '" onclick="pp(this)"'
                    . ' style="padding:10px 12px;cursor:pointer;color:#e5e7eb;font-size:14px;border-bottom:1px solid #1f2937">' . e($p->name) . '</div>';
            }
        }

        $ident = $anon
            ? '<p style="font-size:13px;color:#9ca3af;margin:0;text-align:center">Esta pesquisa é <b>anônima</b> — sua identidade não é registrada.</p>'
            : '<p style="font-size:13px;color:#9ca3af;margin:0;text-align:center">Respondendo como <b>' . e($u->name) . '</b>.</p>';

        $qsHtml = '';
        foreach ($survey->questions as $idx => $q) {
            $req = $q->required ? ' <span style="color:#f87171">*</span>' : '';
            $reqAttr = $q->required ? ' required' : '';
            $field = '';
            if ($q->type === 'text') {
                $field = '<input name="q[' . $q->id . ']" placeholder="Sua resposta"' . $reqAttr . ' style="width:100%;box-sizing:border-box;padding:11px;background:#0b0f14;border:1px solid #374151;border-radius:8px;color:#e5e7eb;font-size:14px">';
            } elseif ($q->type === 'person') {
                // Combobox: input de busca + lista filtrável; o valor escolhido vai no hidden q[id].
                $reqData = $q->required ? ' data-required="1"' : '';
                $field = '<div class="pcombo"' . $reqData . ' style="position:relative">'
                    . '<input type="hidden" name="q[' . $q->id . ']" value="">'
                    . '<input type="text" class="psearch" placeholder="Buscar pessoa…" autocomplete="off" oninput="pf(this)" onfocus="pf(this)" style="width:100%;box-sizing:border-box;padding:11px;background:#0b0f14;border:1px solid #374151;border-radius:8px;color:#e5e7eb;font-size:14px">'
                    . '<div class="plist" style="display:none;position:absolute;left:0;right:0;z-index:5;margin-top:4px;max-height:220px;overflow:auto;background:#0b0f14;border:1px solid #374151;border-radius:8px">' . $peopleItems . '</div>'
                    . '</div>';
            } else { // choice
                $multi = (bool) $q->multiple;
                $inputs = '';
                foreach ($q->options as $o) {
                    $name = $multi ? ('q[' . $q->id . '][]') : ('q[' . $q->id . ']');
                    $type = $multi ? 'checkbox' : 'radio';
                    $rq = (!$multi && $q->required) ? ' required' : '';
                    $inputs .= '<label style="display:flex;align-items:center;gap:8px;padding:9px 11px;margin:5px 0;background:#0b0f14;border:1px solid #374151;border-radius:8px;cursor:pointer;font-size:14px">'
                        . '<input type="' . $type . '" name="' . $name . '" value="' . (int) $o->id . '"' . $rq . ' style="accent-color:' . $accent . '"> ' . e($o->label) . '</label>';
                }
                $field = $inputs;
            }
            $qsHtml .= '<div style="margin:0 0 20px"><div style="font-size:14px;font-weight:600;color:#fff;margin:0 0 8px">' . ($idx + 1) . '. ' . e($q->label) . $req . '</div>' . $field . '</div>';
        }

        $logoHtml = $this->brandLogoHtml($brand);
        $brandName = $brand['name'] ?? '';
        $header = '<div style="text-align:center;margin:0 0 22px">'
            . ($logoHtml ? '<div style="margin:0 0 12px">' . $logoHtml . '</div>' : '')
            . ($brandName ? '<div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#64748b">Pesquisa interna · ' . e($brandName) . '</div>' : '')
            . '</div>';
        $year = now()->format('Y');
        $footer = '<p style="text-align:center;font-size:11px;color:#4b5563;margin:20px 0 0">'
            . ($brandName ? e($brandName) . ' · ' : '') . '© ' . $year . ' · enviado pelo Minutor</p>';

        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $title . '</title></head>'
            . '<body style="margin:0;font-family:Segoe UI,Arial,sans-serif;background:#0b0f14;background-image:radial-gradient(1200px 400px at 50% -8%, ' . $accent . '22, transparent 70%);color:#e5e7eb;display:flex;min-height:100vh;align-items:flex-start;justify-content:center;padding:32px 16px">'
            . '<div style="max-width:620px;width:100%;padding:30px 28px;background:#111827;border:1px solid #1f2937;border-top:4px solid ' . $accent . ';border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.45)">'
            . $header
            . '<h1 style="font-size:21px;margin:0 0 6px;color:#fff;text-align:center">' . $title . '</h1>'
            . $ident
            . '<div style="height:1px;background:#1f2937;margin:4px 0 20px"></div>'
            . '<form method="POST" action="' . $action . '" onsubmit="return vf(this)">'
            . $qsHtml
            . '<button type="submit" style="width:100%;padding:14px;background:' . $accent . ';color:#fff;border:none;border-radius:10px;font-weight:700;font-size:15px;cursor:pointer">Enviar respostas</button>'
            . '</form>'
            . $footer
            . '</div>'
            . '<script>'
            // Filtra a lista do combobox de pessoa ao digitar e a abre.
            . 'function pf(inp){var box=inp.parentNode,list=box.querySelector(".plist"),q=inp.value.toLowerCase();'
            . 'list.querySelectorAll(".popt").forEach(function(o){o.style.display=o.getAttribute("data-name").indexOf(q)===-1?"none":"block";});list.style.display="block";}'
            // Seleciona a pessoa clicada: grava o id no hidden e fecha a lista.
            . 'function pp(o){var box=o.parentNode.parentNode;box.querySelector("input[type=hidden]").value=o.getAttribute("data-id");box.querySelector(".psearch").value=o.textContent;box.querySelector(".plist").style.display="none";box.querySelector(".psearch").style.borderColor="#374151";}'
            // Fecha listas ao clicar fora.
            . 'document.addEventListener("click",function(e){if(!e.target.closest(".pcombo"))document.querySelectorAll(".plist").forEach(function(l){l.style.display="none";});});'
            // Valida pessoas obrigatórias (hidden não dispara required nativo).
            . 'function vf(f){var ok=true;f.querySelectorAll(".pcombo[data-required]").forEach(function(c){if(!c.querySelector("input[type=hidden]").value){ok=false;c.querySelector(".psearch").style.borderColor="#f87171";}});if(!ok)alert("Selecione a pessoa nas perguntas obrigatórias.");return ok;}'
            . '</script>'
            . '</body></html>';
    }
}
