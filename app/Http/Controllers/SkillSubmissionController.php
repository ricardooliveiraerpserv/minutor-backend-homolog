<?php

namespace App\Http\Controllers;

use App\Models\SkillRespondent;
use App\Models\SkillSubmission;
use App\Models\SkillSurvey;
use App\Models\SkillSurveyInvite;
use App\Services\SkillSurveyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Respondente INTERNO (colaborador logado): responder / retomar / enviar a
 * própria pesquisa de competências. Autorização por posse (a submissão é dele).
 */
class SkillSubmissionController extends Controller
{
    public function __construct(private readonly SkillSurveyService $service)
    {
    }

    /** Pesquisas em que o colaborador logado foi convidado. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $invites = SkillSurveyInvite::query()
            ->where('user_id', $user->id)
            ->whereHas('survey', fn ($q) => $q->where('type', SkillSurvey::TYPE_INTERNAL)->whereIn('status', ['open'])
                ->where('public_token', '!=', SkillSurveyService::SELF_SURVEY_TOKEN))
            ->with('survey:id,title,description,status,deadline,type')
            ->get()
            ->map(fn ($i) => [
                'survey_id' => $i->survey_id,
                'invite_token' => $i->token,
                'title' => $i->survey?->title,
                'description' => $i->survey?->description,
                'deadline' => optional($i->survey?->deadline)->toDateString(),
                'status' => $i->status,
                'submitted_at' => $i->submitted_at,
            ]);

        return response()->json(['surveys' => $invites]);
    }

    /** Abre (inicia ou retoma) a pesquisa para o colaborador logado. */
    public function open(Request $request, int $surveyId): JsonResponse
    {
        $user = $request->user();
        $survey = SkillSurvey::with('matrixVersion')->findOrFail($surveyId);
        abort_if($survey->type !== SkillSurvey::TYPE_INTERNAL, 403, 'Pesquisa não é interna.');
        abort_if($survey->status !== SkillSurvey::STATUS_OPEN, 422, 'Pesquisa não está aberta.');

        // Convite: se o colaborador interno tem o link mas não foi convidado
        // explicitamente, cria o convite na hora (o link funciona p/ quem o recebe).
        $respondent = $this->service->internalRespondent($user);
        $invite = SkillSurveyInvite::firstOrCreate(
            ['survey_id' => $survey->id, 'user_id' => $user->id],
            [
                'respondent_id' => $respondent->id,
                'email' => $user->email ? mb_substr((string) $user->email, 0, 190) : null,
                'name' => $user->name ? mb_substr((string) $user->name, 0, 160) : 'Colaborador',
                'status' => SkillSurveyInvite::STATUS_OPENED,
                'opened_at' => now(),
            ]
        );

        if (in_array($invite->status, [SkillSurveyInvite::STATUS_PENDING, SkillSurveyInvite::STATUS_SENT], true)) {
            $invite->forceFill(['status' => SkillSurveyInvite::STATUS_OPENED, 'opened_at' => $invite->opened_at ?? now(), 'last_access_at' => now()])->save();
        } else {
            $invite->forceFill(['last_access_at' => now()])->save();
        }

        $respondent = $this->service->internalRespondent($user);
        $prefill = $this->service->internalCadastral($user);
        $submission = $this->service->startOrResume($survey, $respondent, $invite, $prefill, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json($this->submissionPayload($survey, $submission, $prefill, $invite));
    }

    /**
     * Abre a AUTO-AVALIAÇÃO para o colaborador logado atualizar as próprias
     * competências a qualquer momento (retoma rascunho ou cria nova submissão
     * de atualização pré-preenchida com o perfil atual).
     */
    public function selfUpdate(Request $request): JsonResponse
    {
        $user = $request->user();
        [$survey, $submission, $invite] = $this->service->openSelfUpdate($user, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        $prefill = $this->service->internalCadastral($user);

        return response()->json($this->submissionPayload($survey, $submission, $prefill, $invite));
    }

    public function autosave(Request $request, int $submissionId): JsonResponse
    {
        $submission = $this->ownedSubmission($request, $submissionId);
        $payload = $request->validate([
            'current_step' => 'nullable|integer|min:0',
            'cadastral' => 'nullable|array',
            'answers' => 'array',
            'answers.*.item_id' => 'required|integer',
            'answers.*.skill_id' => 'nullable|integer',
            'answers.*.level_id' => 'nullable|integer|exists:skill_levels,id',
            'answers.*.years_experience' => 'nullable|integer|min:0|max:60',
            'answers.*.atuacao' => 'nullable|array',
            'answers.*.notes' => 'nullable|string',
        ]);

        $submission = $this->service->autosave($submission, $payload);

        return response()->json([
            'saved' => true,
            'progress' => $submission->progress,
        ]);
    }

    public function submit(Request $request, int $submissionId): JsonResponse
    {
        $submission = $this->ownedSubmission($request, $submissionId);
        $submission = $this->service->submit($submission);

        return response()->json([
            'submitted' => true,
            'submission_id' => $submission->id,
            'submitted_at' => $submission->submitted_at,
        ]);
    }

    /** Revisão: pendências por categoria antes de enviar. */
    public function review(Request $request, int $submissionId): JsonResponse
    {
        $submission = $this->ownedSubmission($request, $submissionId);
        $total = $submission->matrixVersion->items()->count();
        $pending = $this->service->pendingByCategory($submission);
        $pendingCount = array_sum(array_column($pending, 'pending'));

        return response()->json([
            'total_items' => $total,
            'answered' => $total - $pendingCount,
            'pending_total' => $pendingCount,
            'pending_by_category' => $pending,
            'complete' => $pendingCount === 0,
        ]);
    }

    /** Histórico de avaliações do colaborador logado (nunca sobrescrito). */
    public function history(Request $request): JsonResponse
    {
        $respondent = SkillRespondent::where('type', SkillRespondent::TYPE_INTERNAL)
            ->where('user_id', $request->user()->id)->first();

        $submissions = $respondent
            ? $respondent->submissions()
                ->where('status', SkillSubmission::STATUS_SUBMITTED)
                ->with(['survey:id,title', 'matrixVersion:id,number,label'])
                ->orderByDesc('submitted_at')
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'survey' => $s->survey?->title,
                    'matrix_version' => $s->matrixVersion ? "v{$s->matrixVersion->number}" : null,
                    'submitted_at' => $s->submitted_at,
                    'answers_count' => $s->answers()->count(),
                ])
            : collect();

        return response()->json(['history' => $submissions]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function ownedSubmission(Request $request, int $submissionId): SkillSubmission
    {
        $submission = SkillSubmission::with('respondent', 'matrixVersion')->findOrFail($submissionId);
        abort_if(
            $submission->respondent?->type !== SkillRespondent::TYPE_INTERNAL
            || $submission->respondent?->user_id !== $request->user()->id,
            403,
            'Esta avaliação não pertence a você.'
        );

        return $submission;
    }

    private function submissionPayload(SkillSurvey $survey, SkillSubmission $submission, array $prefill, ?SkillSurveyInvite $invite): array
    {
        $matrix = $this->service->matrixPayload($survey->matrixVersion);
        $answers = $submission->answers()->get()->keyBy('matrix_version_item_id')
            ->map(fn ($a) => [
                'item_id' => $a->matrix_version_item_id,
                'skill_id' => $a->skill_id,
                'level_id' => $a->level_id,
                'years_experience' => $a->years_experience,
                'atuacao' => $a->atuacao,
                'notes' => $a->notes,
            ])->values();

        return [
            'survey' => [
                'id' => $survey->id, 'type' => $survey->type, 'title' => $survey->title,
                'description' => $survey->description, 'deadline' => optional($survey->deadline)->toDateString(),
            ],
            'cadastral_schema' => $this->service->schemaFor($survey->type),
            'cadastral' => array_merge($prefill, $submission->cadastral ?? []),
            'matrix' => $matrix,
            'submission' => [
                'id' => $submission->id,
                'status' => $submission->status,
                'progress' => $submission->progress,
                'answers' => $answers,
            ],
            'invite' => $invite ? ['status' => $invite->status] : null,
        ];
    }
}
