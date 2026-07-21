<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Models\SkillLevel;
use App\Models\SkillRespondent;
use App\Models\SkillSubmission;
use App\Models\SkillSurvey;
use App\Models\SkillSurveyInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores do Banco de Competências. Agregações sempre sobre a submissão
 * MAIS RECENTE por respondente (não conta histórico em dobro).
 */
class SkillDashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $type = $request->input('type') ?: null;                 // internal|partner|candidate
        $search = trim((string) $request->input('search')) ?: null;

        $csv = function ($v) {
            $v = is_array($v) ? implode(',', $v) : (string) $v;

            return array_values(array_filter(array_map('trim', explode(',', $v)), fn ($x) => $x !== ''));
        };
        $categories = $csv($request->input('categories', $request->input('category'))); // área(s)
        $modules = $csv($request->input('modules'));                                     // competência(s) específica(s)
        $respondentIds = array_map('intval', $csv($request->input('respondent_ids')));   // nomes selecionados
        $levelWeights = array_map('intval', $csv($request->input('level_weights', $request->input('level_weight')))); // níveis (múltiplos)
        $classifications = $csv($request->input('classifications', $request->input('classification'))); // classificação(ões)

        // Submissão mais recente (enviada) por respondente — respeita filtros de usuário.
        $latestIds = SkillSubmission::query()
            ->where('status', SkillSubmission::STATUS_SUBMITTED)
            ->when($type || $classifications || $search, fn ($q) => $q->whereHas('respondent', function ($r) use ($type, $classifications, $search) {
                $r->when($type, fn ($x) => $x->where('type', $type))
                    ->when($classifications, fn ($x) => $x->whereIn('classification', $classifications))
                    ->when($search, fn ($x) => $x->where('name', 'ilike', '%' . $search . '%'));
            }))
            ->when($respondentIds, fn ($q) => $q->whereIn('respondent_id', $respondentIds))
            ->selectRaw('max(id) as id')
            ->groupBy('respondent_id')
            ->pluck('id');

        $classificationOptions = collect(SkillRespondent::CLASSIFICATIONS)
            ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();

        return response()->json([
            'surveys' => $this->surveysBlock(),
            'response_rate' => $this->responseRateBlock(),
            'respondents_by_type' => $this->respondentsByType($latestIds),
            'strongest' => $this->skillRanking($latestIds, 'desc', $categories, $modules),
            'weakest' => $this->skillRanking($latestIds, 'asc', $categories, $modules),
            'respondents' => $this->respondentsList($latestIds, $categories, $modules, $levelWeights),
            'skill_rows' => ($categories || $modules) ? $this->skillRows($latestIds, $categories, $modules, $levelWeights) : [],
            'filters' => [
                'categories' => Skill::query()->select('category')->distinct()->orderBy('category')->pluck('category'),
                'levels' => SkillLevel::orderBy('weight')->get(['id', 'name', 'weight']), // inclui "Nenhum conhecimento" (0) p/ relatório
                'classifications' => $classificationOptions,
                'modules' => Skill::query()->orderBy('category')->orderBy('name')->get(['name', 'category'])
                    ->map(fn ($s) => ['value' => $s->name, 'label' => $s->name, 'category' => $s->category]),
                'names' => $this->namesList(),
                'partners' => \App\Models\Partner::orderBy('name')->get(['id', 'name'])->map(fn ($p) => ['value' => (string) $p->id, 'label' => $p->name]),
            ],
        ]);
    }

    /**
     * Lista de usuários (respondentes) que atendem aos filtros. Com área+nível,
     * traz quem tem ao menos uma competência naquela área com nível >= o pedido —
     * é a consulta "quem é Sênior+ em Protheus".
     */
    private function respondentsList($latestIds, array $categories, array $modules, array $levelWeights): array
    {
        if ($latestIds->isEmpty()) {
            return [];
        }
        $levelNames = SkillLevel::pluck('name', 'weight');

        $q = DB::table('skill_submissions as s')
            ->whereIn('s.id', $latestIds)
            ->join('skill_respondents as r', 'r.id', '=', 's.respondent_id')
            ->leftJoin('partners as pt', 'pt.id', '=', 'r.partner_id');

        if (! empty($categories) || ! empty($modules) || ! empty($levelWeights)) {
            $q->join('skill_submission_answers as a', 'a.submission_id', '=', 's.id')
                ->join('skill_matrix_version_items as i', 'i.id', '=', 'a.matrix_version_item_id')
                ->when($categories || $modules, fn ($qq) => $qq->where(function ($w) use ($categories, $modules) {
                    $w->when($categories, fn ($x) => $x->whereIn('i.category', $categories))
                        ->when($modules, fn ($x) => $x->orWhereIn('i.name', $modules));
                }))
                ->when(! empty($levelWeights), fn ($qq) => $qq->whereIn('a.level_weight', $levelWeights), fn ($qq) => $qq->where('a.level_weight', '>=', 1))
                ->groupBy('r.id', 'r.name', 'r.type', 'r.classification', 'r.partner_id', 'pt.name', DB::raw('r.data::text'), 's.submitted_at')
                ->select('r.id', 'r.name', 'r.type', 'r.classification', 'r.partner_id', DB::raw('pt.name as partner_name'), DB::raw('r.data::text as data'), 's.submitted_at',
                    DB::raw('max(a.level_weight) as top_weight'),
                    DB::raw('count(distinct a.skill_id) as matches'));
        } else {
            $q->select('r.id', 'r.name', 'r.type', 'r.classification', 'r.partner_id', DB::raw('pt.name as partner_name'), 'r.data', 's.submitted_at',
                DB::raw('cast(null as integer) as top_weight'),
                DB::raw('cast(null as integer) as matches'));
        }

        $classLabels = SkillRespondent::CLASSIFICATIONS;

        return $q->orderByDesc('s.submitted_at')->limit(500)->get()->map(function ($x) use ($levelNames, $classLabels) {
            $data = json_decode($x->data ?? '{}', true) ?: [];
            $tw = $x->top_weight !== null ? (int) $x->top_weight : null;

            return [
                'id' => $x->id,
                'name' => $x->name,
                'type' => $x->type,
                'classification' => $x->classification,
                'classification_label' => $x->classification ? ($classLabels[$x->classification] ?? $x->classification) : null,
                'blacklist' => $x->classification === 'blacklist',
                'partner_id' => $x->partner_id ? (string) $x->partner_id : '',
                'partner_name' => $x->partner_name,
                'empresa' => $data['empresa'] ?? null,
                'valor' => $data['valor'] ?? null,
                'last_at' => $x->submitted_at,
                'top_weight' => $tw,
                'top_level' => $tw !== null ? ($levelNames[$tw] ?? null) : null,
                'matches' => $x->matches !== null ? (int) $x->matches : null,
            ];
        })->all();
    }

    /**
     * Linhas detalhadas: uma por (pessoa × competência) nas áreas filtradas, com
     * o nível de cada competência — visão estilo planilha (Nome | Módulo | Nível).
     */
    private function skillRows($latestIds, array $categories, array $modules, array $levelWeights): array
    {
        if ($latestIds->isEmpty() || (empty($categories) && empty($modules))) {
            return [];
        }
        $classLabels = SkillRespondent::CLASSIFICATIONS;

        return DB::table('skill_submissions as s')
            ->whereIn('s.id', $latestIds)
            ->join('skill_respondents as r', 'r.id', '=', 's.respondent_id')
            ->join('skill_submission_answers as a', 'a.submission_id', '=', 's.id')
            ->join('skill_matrix_version_items as i', 'i.id', '=', 'a.matrix_version_item_id')
            ->join('skill_levels as l', 'l.id', '=', 'a.level_id')
            ->where(function ($w) use ($categories, $modules) {
                $w->when($categories, fn ($x) => $x->whereIn('i.category', $categories))
                    ->when($modules, fn ($x) => $x->orWhereIn('i.name', $modules));
            })
            ->when(! empty($levelWeights), fn ($q) => $q->whereIn('a.level_weight', $levelWeights), fn ($q) => $q->where('a.level_weight', '>=', 1))
            ->orderBy('r.name')->orderBy('i.category')->orderByDesc('a.level_weight')->orderBy('i.name')
            ->limit(4000)
            ->get(['r.id as respondent_id', 'r.name', 'r.classification', 'r.data', 'i.name as module', 'i.category', 'l.name as level', 'a.level_weight'])
            ->map(function ($x) use ($classLabels) {
                $data = json_decode($x->data ?? '{}', true) ?: [];

                return [
                    'respondent_id' => $x->respondent_id,
                    'name' => $x->name,
                    'classification' => $x->classification,
                    'classification_label' => $x->classification ? ($classLabels[$x->classification] ?? $x->classification) : null,
                    'blacklist' => $x->classification === 'blacklist',
                    'valor' => $data['valor'] ?? null,
                    'module' => $x->module,
                    'category' => $x->category,
                    'level' => $x->level,
                    'weight' => (int) $x->level_weight,
                ];
            })->all();
    }

    /** Nomes dos respondentes avaliados (para o multi-select de Nome). */
    private function namesList(): array
    {
        return SkillRespondent::query()
            ->whereIn('id', fn ($q) => $q->select('respondent_id')->from('skill_submissions')->where('status', 'submitted'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($r) => ['value' => (string) $r->id, 'label' => $r->name])
            ->all();
    }

    private function surveysBlock(): array
    {
        $rows = SkillSurvey::query()
            ->selectRaw("count(*) as total, count(*) filter (where status = 'open') as open")
            ->selectRaw("count(*) filter (where type = 'internal') as internal")
            ->selectRaw("count(*) filter (where type = 'partner') as partner")
            ->selectRaw("count(*) filter (where type = 'candidate') as candidate")
            ->first();

        return [
            'total' => (int) $rows->total,
            'open' => (int) $rows->open,
            'by_type' => [
                'internal' => (int) $rows->internal,
                'partner' => (int) $rows->partner,
                'candidate' => (int) $rows->candidate,
            ],
        ];
    }

    private function responseRateBlock(): array
    {
        $global = SkillSurveyInvite::query()
            ->selectRaw("count(*) as invited, count(*) filter (where status = 'submitted') as submitted")
            ->first();

        $byType = SkillSurveyInvite::query()
            ->join('skill_surveys', 'skill_surveys.id', '=', 'skill_survey_invites.survey_id')
            ->groupBy('skill_surveys.type')
            ->selectRaw('skill_surveys.type, count(*) as invited, count(*) filter (where skill_survey_invites.status = \'submitted\') as submitted')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->type => [
                'invited' => (int) $r->invited,
                'submitted' => (int) $r->submitted,
                'rate' => $r->invited > 0 ? round($r->submitted / $r->invited * 100) : 0,
            ]])->all();

        $invited = (int) $global->invited;
        $submitted = (int) $global->submitted;

        return [
            'invited' => $invited,
            'submitted' => $submitted,
            'pending' => max(0, $invited - $submitted),
            'rate' => $invited > 0 ? round($submitted / $invited * 100) : 0,
            'by_type' => $byType,
        ];
    }

    private function respondentsByType($latestIds): array
    {
        if ($latestIds->isEmpty()) {
            return ['internal' => 0, 'partner' => 0, 'candidate' => 0, 'total' => 0];
        }
        $rows = SkillSubmission::query()
            ->whereIn('skill_submissions.id', $latestIds)
            ->join('skill_respondents', 'skill_respondents.id', '=', 'skill_submissions.respondent_id')
            ->groupBy('skill_respondents.type')
            ->selectRaw('skill_respondents.type, count(*) as total')
            ->pluck('total', 'type');

        return [
            'internal' => (int) ($rows['internal'] ?? 0),
            'partner' => (int) ($rows['partner'] ?? 0),
            'candidate' => (int) ($rows['candidate'] ?? 0),
            'total' => (int) $rows->sum(),
        ];
    }

    private function recent(): array
    {
        return SkillSubmission::query()
            ->where('status', SkillSubmission::STATUS_SUBMITTED)
            ->with(['respondent:id,name,type', 'survey:id,title'])
            ->orderByDesc('submitted_at')
            ->limit(8)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'respondent_id' => $s->respondent_id,
                'name' => $s->respondent?->name,
                'type' => $s->respondent?->type,
                'survey' => $s->survey?->title,
                'submitted_at' => $s->submitted_at,
            ])->all();
    }

    /**
     * Ranking de competências por nível médio (0..4) sobre as submissões mais
     * recentes. desc = mais fortes; asc = menor domínio. Só competências com
     * pelo menos 3 respostas (evita ruído de amostra minúscula).
     */
    private function skillRanking($latestIds, string $dir, array $categories = [], array $modules = []): array
    {
        if ($latestIds->isEmpty()) {
            return [];
        }

        return DB::table('skill_submission_answers as a')
            ->whereIn('a.submission_id', $latestIds)
            ->whereNotNull('a.skill_id')
            ->join('skills', 'skills.id', '=', 'a.skill_id')
            ->when($categories || $modules, fn ($q) => $q->where(function ($w) use ($categories, $modules) {
                $w->when($categories, fn ($x) => $x->whereIn('skills.category', $categories))
                    ->when($modules, fn ($x) => $x->orWhereIn('skills.name', $modules));
            }))
            ->groupBy('a.skill_id', 'skills.name', 'skills.category')
            ->havingRaw('count(*) >= 3')
            ->selectRaw('a.skill_id, skills.name, skills.category, round(avg(a.level_weight)::numeric, 2) as avg_weight, count(*) as answers, count(*) filter (where a.level_weight > 0) as with_knowledge')
            ->orderByRaw('avg(a.level_weight) ' . ($dir === 'asc' ? 'asc' : 'desc'))
            ->orderByRaw('count(*) desc')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'skill_id' => $r->skill_id,
                'name' => $r->name,
                'category' => $r->category,
                'avg_weight' => (float) $r->avg_weight,
                'answers' => (int) $r->answers,
                'with_knowledge_pct' => $r->answers > 0 ? round($r->with_knowledge / $r->answers * 100) : 0,
            ])->all();
    }
}
