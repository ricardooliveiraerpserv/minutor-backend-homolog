<?php

namespace App\Http\Controllers;

use App\Models\SkillSurvey;
use App\Models\SkillSurveyInvite;
use App\Models\User;
use App\Services\SkillCampaignNotifier;
use App\Services\SkillSurveyService;
use App\Workflows\WorkflowConfigService;
use App\Workflows\WorkflowMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Administração das Pesquisas de Competências (criar, enviar, acompanhar).
 * Gate de rota: competencias.manage (admin/administrativo).
 */
class SkillSurveyController extends Controller
{
    public function __construct(private readonly SkillSurveyService $service)
    {
    }

    /** Metadados p/ a tela de Nova Pesquisa (versão da matriz, destinatários, schema). */
    public function meta(): JsonResponse
    {
        $version = $this->service->activeVersion();

        $recipients = User::query()
            ->whereIn('type', ['admin', 'administrativo', 'coordenador', 'consultor'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'type'])
            ->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'cargo' => null, 'type' => $u->type,
            ]);

        return response()->json([
            'active_version' => $version ? [
                'id' => $version->id, 'number' => $version->number,
                'label' => $version->label, 'skills_count' => $version->skills_count,
            ] : null,
            'types' => [
                ['value' => SkillSurvey::TYPE_INTERNAL, 'label' => 'Colaboradores Internos'],
                ['value' => SkillSurvey::TYPE_PARTNER, 'label' => 'Parceiros'],
                ['value' => SkillSurvey::TYPE_CANDIDATE, 'label' => 'Banco de Talentos (Candidatos)'],
            ],
            'recipients' => $recipients,
            'cadastral_schema' => $this->service->allSchemas(),
        ]);
    }

    /** Lista de pesquisas com indicadores de acompanhamento. */
    public function index(): JsonResponse
    {
        $surveys = SkillSurvey::query()
            ->with('matrixVersion:id,number,label')
            ->withCount([
                'invites',
                'invites as submitted_count' => fn ($q) => $q->where('status', SkillSurveyInvite::STATUS_SUBMITTED),
                'submissions as submissions_count' => fn ($q) => $q->where('status', 'submitted'),
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($s) => $this->surveyCard($s));

        return response()->json(['surveys' => $surveys]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:internal,partner,candidate',
            'title' => 'required|string|max:160',
            'description' => 'nullable|string',
            'deadline' => 'nullable|date',
            'matrix_version_id' => 'nullable|exists:skill_matrix_versions,id',
        ]);

        $version = $data['matrix_version_id'] ?? optional($this->service->activeVersion())->id;
        abort_if(! $version, 422, 'Nenhuma versão da matriz publicada.');

        // Parceiros/Talentos: o link público É o meio de distribuição (sem etapa de
        // convite) → já nasce ABERTA para o link funcionar de imediato. Interna começa
        // como rascunho e abre ao enviar os convites.
        $isPublic = $data['type'] !== SkillSurvey::TYPE_INTERNAL;

        $survey = SkillSurvey::create([
            'type' => $data['type'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'deadline' => $data['deadline'] ?? null,
            'matrix_version_id' => $version,
            'allow_public' => $isPublic,
            'status' => $isPublic ? SkillSurvey::STATUS_OPEN : SkillSurvey::STATUS_DRAFT,
            'opened_at' => $isPublic ? now() : null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($this->surveyDetail($survey), 201);
    }

    public function show(int $id): JsonResponse
    {
        $survey = SkillSurvey::with('matrixVersion')->findOrFail($id);

        return response()->json($this->surveyDetail($survey));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $survey = SkillSurvey::findOrFail($id);
        $data = $request->validate([
            'title' => 'sometimes|string|max:160',
            'description' => 'nullable|string',
            'deadline' => 'nullable|date',
            'status' => 'sometimes|in:draft,open,closed',
        ]);

        if (isset($data['status'])) {
            if ($data['status'] === SkillSurvey::STATUS_OPEN && ! $survey->opened_at) {
                $survey->opened_at = now();
            }
            if ($data['status'] === SkillSurvey::STATUS_CLOSED) {
                $survey->closed_at = now();
            }
        }
        $survey->fill($data)->save();

        return response()->json($this->surveyDetail($survey->fresh('matrixVersion')));
    }

    /**
     * Grupos de colaboradores internos (categorias) elegíveis à campanha.
     * Parceiros são type=parceiro_admin; a flag `is_executive` separa o ADMIN do
     * parceiro (true) do parceiro comum (false/null).
     */
    private const CAMPAIGN_GROUPS = [
        ['key' => 'consultor',      'label' => 'Consultores',            'types' => ['consultor']],
        ['key' => 'coordenador',    'label' => 'Coordenadores',          'types' => ['coordenador']],
        ['key' => 'parceiro',       'label' => 'Parceiros',              'types' => ['parceiro_admin'], 'is_executive' => false],
        ['key' => 'parceiro_admin', 'label' => 'Parceiros admin',        'types' => ['parceiro_admin'], 'is_executive' => true],
        ['key' => 'admin',          'label' => 'Administrativo / Admin',  'types' => ['admin', 'administrativo']],
    ];

    /** Destinatários possíveis da campanha, agrupados por categoria (para seleção). */
    public function campaignTargets(): JsonResponse
    {
        $groups = collect(self::CAMPAIGN_GROUPS)->map(function ($g) {
            $users = User::whereIn('type', $g['types'])->where('enabled', true)
                ->when(array_key_exists('is_executive', $g), function ($q) use ($g) {
                    $g['is_executive']
                        ? $q->where('is_executive', true)
                        : $q->where(fn ($w) => $w->where('is_executive', false)->orWhereNull('is_executive'));
                })
                ->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values();

            return ['key' => $g['key'], 'label' => $g['label'], 'count' => $users->count(), 'users' => $users];
        });

        return response()->json(['groups' => $groups]);
    }

    /** Prévia do e-mail da campanha (não envia). */
    public function campaignPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:160',
            'description' => 'nullable|string',
            'deadline' => 'nullable|date',
        ]);
        $title = $data['title'] ?: ('Atualização de Competências — ' . now()->format('m/Y'));
        $prazo = ! empty($data['deadline']) ? \Illuminate\Support\Carbon::parse($data['deadline'])->format('d/m/Y') : 'sem prazo';
        $cta = rtrim((string) config('app.frontend_url', 'https://app.minutor.com.br'), '/') . '/competencias/responder';

        $r = app(WorkflowMailer::class)->renderHtml(
            SkillCampaignNotifier::WORKFLOW,
            ['titulo' => $title, 'prazo' => $prazo],
            $cta,
            $data['description'] ?? null,
            $title,
        );

        return response()->json(['subject' => $r['subject'], 'html' => $r['html']]);
    }

    /**
     * CAMPANHA de atualização de competências: cria a pesquisa interna com PRAZO,
     * convida os colaboradores SELECIONADOS e dispara pop-up + e-mail (workflow)
     * num passo. A recorrência de cobrança é definida aqui (Central de Workflows).
     */
    public function launchCampaign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:160',
            'description' => 'nullable|string',
            'deadline' => 'required|date|after_or_equal:today',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer',
            'recurrence_days' => 'nullable|integer|min:0|max:365',
        ]);

        $version = optional($this->service->activeVersion())->id;
        abort_if(! $version, 422, 'Nenhuma versão da matriz publicada.');

        // Só colaboradores internos ATIVOS dos tipos elegíveis (evita convidar cliente etc.).
        $eligible = ['consultor', 'coordenador', 'parceiro_admin', 'admin', 'administrativo'];
        $userIds = User::whereIn('id', $data['user_ids'])->whereIn('type', $eligible)
            ->where('enabled', true)->pluck('id')->all();
        abort_if(empty($userIds), 422, 'Nenhum destinatário válido selecionado.');

        $survey = SkillSurvey::create([
            'type' => SkillSurvey::TYPE_INTERNAL,
            'title' => $data['title'] ?: ('Atualização de Competências — ' . now()->format('m/Y')),
            'description' => $data['description'] ?: 'Revise e atualize suas competências. Se você evoluiu (novo curso, ferramenta ou projeto), reflita isso no seu perfil.',
            'deadline' => $data['deadline'],
            'matrix_version_id' => $version,
            'allow_public' => false,
            'is_campaign' => true, // separa das Pesquisas/Formulários na aba "Campanhas".
            'status' => SkillSurvey::STATUS_OPEN,
            'opened_at' => now(),
            'created_by' => $request->user()->id,
        ]);

        // Convites sem o e-mail padrão — a campanha notifica por pop-up + workflow.
        $this->service->inviteInternalUsers($survey, $userIds, notify: false);

        // Recorrência definida no modal → grava no template do workflow (Central).
        if (array_key_exists('recurrence_days', $data) && $data['recurrence_days'] !== null) {
            app(WorkflowConfigService::class)->setRecurrenceDays(SkillCampaignNotifier::WORKFLOW, (int) $data['recurrence_days']);
        }

        $mails = SkillCampaignNotifier::onLaunch($survey, $request->user());

        return response()->json(array_merge($this->surveyDetail($survey->fresh('matrixVersion')), [
            'invited' => count($userIds),
            'mails_sent' => $mails,
        ]), 201);
    }

    /** Convida colaboradores internos (user_ids específicos OU todos). */
    public function storeInvites(Request $request, int $id): JsonResponse
    {
        $survey = SkillSurvey::findOrFail($id);
        abort_if($survey->type !== SkillSurvey::TYPE_INTERNAL, 422, 'Convites diretos só para pesquisas internas. Parceiros/Candidatos usam o link público.');

        $data = $request->validate([
            'user_ids' => 'array',
            'user_ids.*' => 'integer|exists:users,id',
            'all' => 'boolean',
        ]);

        $userIds = $data['user_ids'] ?? [];
        if (! empty($data['all'])) {
            $userIds = User::whereIn('type', ['admin', 'administrativo', 'coordenador', 'consultor'])
                ->pluck('id')->all();
        }
        abort_if(empty($userIds), 422, 'Selecione ao menos um destinatário.');

        // Abrir a pesquisa automaticamente ao enviar os primeiros convites.
        if ($survey->status === SkillSurvey::STATUS_DRAFT) {
            $survey->forceFill(['status' => SkillSurvey::STATUS_OPEN, 'opened_at' => now()])->save();
        }

        $created = $this->service->inviteInternalUsers($survey, $userIds);

        return response()->json([
            'created' => $created,
            'detail' => $this->surveyDetail($survey->fresh('matrixVersion')),
        ], 201);
    }

    /** Tabela de acompanhamento (estilo MS Forms). */
    public function invites(int $id): JsonResponse
    {
        $survey = SkillSurvey::findOrFail($id);
        $invites = $survey->invites()
            ->with('respondent:id,name,email')
            ->orderBy('name')
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'respondent_id' => $i->respondent_id, // p/ abrir as competências do respondente
                'name' => $i->name ?? $i->respondent?->name,
                'email' => $i->email ?? $i->respondent?->email,
                'status' => $i->status,
                'last_access_at' => $i->last_access_at,
                'submitted_at' => $i->submitted_at,
                'reminder_count' => $i->reminder_count,
                'last_reminder_at' => $i->last_reminder_at,
            ]);

        return response()->json(['survey' => $this->surveyCard($survey), 'invites' => $invites]);
    }

    /**
     * Lembrete MANUAL de um convite: dispara pop-up + e-mail (workflow
     * competencias.campanha) ao consultor e registra o lembrete.
     */
    public function reminder(Request $request, int $inviteId): JsonResponse
    {
        $invite = SkillSurveyInvite::findOrFail($inviteId);
        abort_if($invite->status === SkillSurveyInvite::STATUS_SUBMITTED, 422, 'Convite já respondido.');

        $sent = SkillCampaignNotifier::remindOne($invite, $request->user());

        return response()->json([
            'id' => $invite->id,
            'reminder_count' => $invite->fresh()->reminder_count,
            'email_sent' => $sent,
            'link' => $this->inviteLink($invite),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function surveyCard(SkillSurvey $s): array
    {
        $invited = $s->invites_count ?? $s->invites()->count();
        $submitted = $s->submitted_count ?? $s->invites()->where('status', SkillSurveyInvite::STATUS_SUBMITTED)->count();
        $pending = max(0, $invited - $submitted);

        return [
            'id' => $s->id,
            'type' => $s->type,
            'title' => $s->title,
            'description' => $s->description,
            'status' => $s->status,
            'is_campaign' => (bool) $s->is_campaign,
            'deadline' => optional($s->deadline)->toDateString(),
            'public_token' => $s->public_token,
            'public_link' => $this->publicLink($s),
            'matrix_version' => $s->relationLoaded('matrixVersion') && $s->matrixVersion
                ? ['number' => $s->matrixVersion->number, 'label' => $s->matrixVersion->label]
                : null,
            'invited' => $invited,
            'submitted' => $submitted,
            'pending' => $pending,
            'response_rate' => $invited > 0 ? round($submitted / $invited * 100) : 0,
            'created_at' => $s->created_at,
        ];
    }

    private function surveyDetail(SkillSurvey $s): array
    {
        return array_merge($this->surveyCard($s->loadMissing('matrixVersion')), [
            'cadastral_schema' => $this->service->schemaFor($s->type),
        ]);
    }

    private function publicLink(SkillSurvey $s): string
    {
        return rtrim($this->frontendBase(), '/') . '/skills/' . $s->public_token;
    }

    private function inviteLink(SkillSurveyInvite $invite): string
    {
        return rtrim($this->frontendBase(), '/') . '/competencias/responder?invite=' . $invite->token;
    }

    private function frontendBase(): string
    {
        return env('FRONTEND_URL') ?: config('app.frontend_url') ?: config('app.url');
    }
}
