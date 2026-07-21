<?php

namespace App\Http\Controllers;

use App\Models\SkillRespondent;
use App\Models\SkillSubmission;
use App\Models\SkillSurvey;
use App\Models\SkillSurveyInvite;
use App\Services\SkillSurveyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Portal PÚBLICO da pesquisa (fora do auth:sanctum, com throttle) — Parceiros e
 * Banco de Talentos. O link /skills/{public_token} resolve o tipo e apresenta a
 * MESMA matriz. Autosave/retomada via `continue_token` (guardado no navegador).
 */
class SkillFormController extends Controller
{
    public function __construct(private readonly SkillSurveyService $service)
    {
    }

    /** Resolve a pesquisa pública e devolve matriz + schema cadastral. */
    public function show(string $token): JsonResponse
    {
        $survey = $this->publicSurvey($token);

        return response()->json([
            'survey' => [
                'id' => $survey->id, 'type' => $survey->type, 'title' => $survey->title,
                'description' => $survey->description, 'deadline' => optional($survey->deadline)->toDateString(),
            ],
            'cadastral_schema' => $this->service->schemaFor($survey->type),
            'matrix' => $this->service->matrixPayload($survey->matrixVersion),
        ]);
    }

    /** Upload do currículo (candidato) — retorna a referência p/ o cadastral. */
    public function upload(Request $request, string $token): JsonResponse
    {
        $survey = $this->publicSurvey($token);
        $request->validate(['file' => 'required|file|mimes:pdf,doc,docx|max:5120'], [
            'file.mimes' => 'Anexe um arquivo PDF, DOC ou DOCX.',
            'file.max' => 'O arquivo deve ter no máximo 5 MB.',
        ]);
        $file = $request->file('file');
        $path = $file->store('skills-cv/' . $survey->public_token, 'local');

        return response()->json([
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'size' => $file->getSize(),
        ], 201);
    }

    /** Inicia uma resposta pública (cria respondente + submissão). */
    public function start(Request $request, string $token): JsonResponse
    {
        $survey = $this->publicSurvey($token);
        $cadastral = $this->service->sanitizeCadastral($survey->type, (array) $request->input('cadastral', []));
        $request->merge(['cadastral' => $cadastral]);

        $request->validate(
            array_merge(['cadastral' => 'required|array'], $this->service->cadastralRules($survey->type)),
            [
                'cadastral.phone.regex' => 'Informe um celular válido com DDD (ex.: 11 98888-7777).',
                'cadastral.cep.regex' => 'CEP inválido.',
                'cadastral.email.email' => 'E-mail inválido.',
            ]
        );

        // Complemento é obrigatório, a menos que "Sem complemento" esteja marcado.
        $temCampoComplemento = collect($this->service->schemaFor($survey->type))
            ->contains(fn ($f) => $f['key'] === 'complemento');
        if ($temCampoComplemento) {
            $semComplemento = filter_var($cadastral['sem_complemento'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $semComplemento && trim((string) ($cadastral['complemento'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'cadastral.complemento' => "Informe o complemento ou marque 'Sem complemento'.",
                ]);
            }
        }

        $respondent = SkillRespondent::create([
            'type' => $survey->type,
            'name' => $this->service->clean($cadastral['name'] ?? $cadastral['empresa'] ?? 'Respondente', 160) ?? 'Respondente',
            'email' => $this->service->clean($cadastral['email'] ?? null, 190),
            'phone' => $this->service->clean($cadastral['phone'] ?? null, 40),
            'data' => $cadastral,
        ]);

        $invite = SkillSurveyInvite::create([
            'survey_id' => $survey->id,
            'respondent_id' => $respondent->id,
            'email' => $respondent->email,
            'name' => $respondent->name,
            'status' => SkillSurveyInvite::STATUS_STARTED,
            'started_at' => now(),
        ]);

        $submission = $this->service->startOrResume($survey, $respondent, $invite, $cadastral, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'continue_token' => $invite->token,
            'submission' => $this->publicSubmissionPayload($survey, $submission),
        ], 201);
    }

    /** Retoma uma resposta pública pelo continue_token. */
    public function resume(string $continueToken): JsonResponse
    {
        [$survey, $submission] = $this->resolveContinue($continueToken);

        return response()->json([
            'survey' => [
                'id' => $survey->id, 'type' => $survey->type, 'title' => $survey->title,
                'description' => $survey->description, 'deadline' => optional($survey->deadline)->toDateString(),
            ],
            'cadastral_schema' => $this->service->schemaFor($survey->type),
            'cadastral' => $submission->cadastral ?? [],
            'matrix' => $this->service->matrixPayload($survey->matrixVersion),
            'submission' => $this->publicSubmissionPayload($survey, $submission),
        ]);
    }

    public function autosave(Request $request, string $continueToken): JsonResponse
    {
        [, $submission] = $this->resolveContinue($continueToken);
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

        return response()->json(['saved' => true, 'progress' => $submission->progress]);
    }

    public function submit(string $continueToken): JsonResponse
    {
        [, $submission] = $this->resolveContinue($continueToken);
        $submission = $this->service->submit($submission);

        return response()->json([
            'submitted' => true,
            'submitted_at' => $submission->submitted_at,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function publicSurvey(string $token): SkillSurvey
    {
        $survey = SkillSurvey::with('matrixVersion')->where('public_token', $token)->first();
        abort_if(! $survey, 404, 'Pesquisa não encontrada.');
        abort_if(! $survey->allow_public || $survey->type === SkillSurvey::TYPE_INTERNAL, 403, 'Esta pesquisa não está disponível publicamente.');
        abort_if($survey->status !== SkillSurvey::STATUS_OPEN, 422, 'Esta pesquisa não está aberta.');

        return $survey;
    }

    /** @return array{0: SkillSurvey, 1: SkillSubmission} */
    private function resolveContinue(string $continueToken): array
    {
        $invite = SkillSurveyInvite::where('token', $continueToken)->first();
        abort_if(! $invite || ! $invite->submission_id, 404, 'Sessão não encontrada.');
        $submission = SkillSubmission::with(['survey.matrixVersion', 'matrixVersion'])->findOrFail($invite->submission_id);

        return [$submission->survey, $submission];
    }

    private function publicSubmissionPayload(SkillSurvey $survey, SkillSubmission $submission): array
    {
        $answers = $submission->answers()->get()->map(fn ($a) => [
            'item_id' => $a->matrix_version_item_id,
            'skill_id' => $a->skill_id,
            'level_id' => $a->level_id,
            'years_experience' => $a->years_experience,
            'atuacao' => $a->atuacao,
        ])->values();

        return [
            'id' => $submission->id,
            'status' => $submission->status,
            'progress' => $submission->progress,
            'answers' => $answers,
        ];
    }
}
