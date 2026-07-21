<?php

namespace App\Http\Controllers;

use App\Models\SkillRespondent;
use App\Models\SkillSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

/**
 * Perfil consolidado do profissional (interno/parceiro/candidato) a partir da
 * avaliação mais recente + histórico imutável de todas as avaliações.
 */
class SkillProfileController extends Controller
{
    /** Lista de profissionais avaliados (com ao menos uma submissão enviada). */
    public function index(Request $request): JsonResponse
    {
        $latest = SkillSubmission::query()
            ->where('status', SkillSubmission::STATUS_SUBMITTED)
            ->selectRaw('respondent_id, max(id) as submission_id, max(submitted_at) as last_at, count(*) as evaluations')
            ->groupBy('respondent_id');

        $rows = SkillRespondent::query()
            ->joinSub($latest, 'x', 'x.respondent_id', '=', 'skill_respondents.id')
            ->when($request->filled('type'), fn ($q) => $q->where('skill_respondents.type', $request->string('type')))
            ->when($request->filled('search'), fn ($q) => $q->where('skill_respondents.name', 'ilike', '%' . $request->string('search') . '%'))
            ->orderByDesc('x.last_at')
            ->get([
                'skill_respondents.id', 'skill_respondents.name', 'skill_respondents.type',
                'skill_respondents.email', 'skill_respondents.data',
                'x.last_at', 'x.evaluations',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'type' => $r->type,
                'email' => $r->email,
                'empresa' => is_array($r->data) ? ($r->data['empresa'] ?? null) : (json_decode($r->data ?? '[]', true)['empresa'] ?? null),
                'last_at' => $r->last_at,
                'evaluations' => (int) $r->evaluations,
            ]);

        return response()->json(['respondents' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $respondent = SkillRespondent::findOrFail($id);
        $category = $request->input('category') ?: null;
        $lw = $request->input('level_weights', $request->input('level_weight'));
        $lw = is_array($lw) ? implode(',', $lw) : (string) $lw;
        $levelWeights = array_map('intval', array_values(array_filter(array_map('trim', explode(',', $lw)), fn ($x) => $x !== '')));

        $latest = SkillSubmission::query()
            ->where('respondent_id', $id)
            ->where('status', SkillSubmission::STATUS_SUBMITTED)
            ->with(['survey:id,title', 'matrixVersion:id,number,label'])
            ->orderByDesc('submitted_at')
            ->first();

        $history = SkillSubmission::query()
            ->where('respondent_id', $id)
            ->where('status', SkillSubmission::STATUS_SUBMITTED)
            ->with(['survey:id,title', 'matrixVersion:id,number'])
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'survey' => $s->survey?->title,
                'matrix_version' => $s->matrixVersion ? "v{$s->matrixVersion->number}" : null,
                'submitted_at' => $s->submitted_at,
                'answers_count' => $s->answers()->count(),
            ]);

        $radar = [];
        $byCategory = [];
        $skills = [];
        if ($latest) {
            // Radar + resumo por categoria (nível médio 0..4 na avaliação mais recente).
            $cat = DB::table('skill_submission_answers as a')
                ->where('a.submission_id', $latest->id)
                ->join('skill_matrix_version_items as i', 'i.id', '=', 'a.matrix_version_item_id')
                ->groupBy('i.category')
                ->selectRaw('i.category, round(avg(a.level_weight)::numeric, 2) as avg_weight, count(*) as total, count(*) filter (where a.level_weight > 0) as with_knowledge')
                ->orderBy('i.category')
                ->get();

            foreach ($cat as $c) {
                $radar[] = ['category' => $c->category, 'avg_weight' => (float) $c->avg_weight];
                $byCategory[] = [
                    'category' => $c->category,
                    'avg_weight' => (float) $c->avg_weight,
                    'total' => (int) $c->total,
                    'with_knowledge' => (int) $c->with_knowledge,
                ];
            }

            // Competências com conhecimento declarado, filtradas por área e nível(is).
            $skills = DB::table('skill_submission_answers as a')
                ->where('a.submission_id', $latest->id)
                ->when(! empty($levelWeights), fn ($q) => $q->whereIn('a.level_weight', $levelWeights), fn ($q) => $q->where('a.level_weight', '>', 0))
                ->join('skill_matrix_version_items as i', 'i.id', '=', 'a.matrix_version_item_id')
                ->join('skill_levels as l', 'l.id', '=', 'a.level_id')
                ->when($category, fn ($q) => $q->where('i.category', $category))
                ->orderBy('i.category')->orderByDesc('a.level_weight')->orderBy('i.name')
                ->get(['i.category', 'i.name', 'l.name as level', 'a.level_weight'])
                ->map(fn ($s) => [
                    'category' => $s->category, 'name' => $s->name,
                    'level' => $s->level, 'weight' => (int) $s->level_weight,
                ])->all();
        }

        $data = is_array($respondent->data) ? $respondent->data : (json_decode($respondent->data ?? '[]', true) ?: []);

        return response()->json([
            'respondent' => [
                'id' => $respondent->id,
                'name' => $respondent->name,
                'type' => $respondent->type,
                'classification' => $respondent->classification,
                'classification_label' => $respondent->classification ? (SkillRespondent::CLASSIFICATIONS[$respondent->classification] ?? $respondent->classification) : null,
                'blacklist' => $respondent->classification === 'blacklist',
                'partner_id' => $respondent->partner_id ? (string) $respondent->partner_id : '',
                'partner_name' => $respondent->partner?->name,
                'email' => $respondent->email,
                'phone' => $respondent->phone,
                'valor' => $data['valor'] ?? null,
                'empresa' => $data['empresa'] ?? null,
                'cargo' => $data['cargo'] ?? null,
                'cidade' => $data['cidade'] ?? null,
                'estado' => $data['estado'] ?? null,
                'linkedin' => $data['linkedin'] ?? $data['linkedin_url'] ?? null,
                'idiomas' => $data['idiomas'] ?? null,
                'cadastral' => $data,
            ],
            'latest' => $latest ? [
                'id' => $latest->id,
                'survey' => $latest->survey?->title,
                'matrix_version' => $latest->matrixVersion ? "v{$latest->matrixVersion->number}" : null,
                'submitted_at' => $latest->submitted_at,
            ] : null,
            'history' => $history,
            'radar' => $radar,
            'by_category' => $byCategory,
            'skills' => $skills,
            'filters' => [
                'categories' => \App\Models\Skill::query()->select('category')->distinct()->orderBy('category')->pluck('category'),
                'levels' => \App\Models\SkillLevel::orderBy('weight')->get(['id', 'name', 'weight']),
                'classifications' => collect(SkillRespondent::CLASSIFICATIONS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
                'partners' => \App\Models\Partner::orderBy('name')->get(['id', 'name'])->map(fn ($p) => ['value' => (string) $p->id, 'label' => $p->name]),
            ],
        ]);
    }

    /** Alteração de classificação EM MASSA (vários respondentes de uma vez). */
    public function bulkClassification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'respondent_ids' => 'required|array|min:1',
            'respondent_ids.*' => 'integer',
            'classification' => ['nullable', Rule::in(array_keys(SkillRespondent::CLASSIFICATIONS))],
        ]);
        $updated = SkillRespondent::whereIn('id', $data['respondent_ids'])
            ->update(['classification' => $data['classification'] ?? null]);

        return response()->json(['updated' => $updated, 'classification' => $data['classification'] ?? null]);
    }

    /** Atualiza a classificação de um respondente (editável). */
    public function updateClassification(Request $request, int $id): JsonResponse
    {
        $respondent = SkillRespondent::findOrFail($id);
        $data = $request->validate([
            'classification' => ['nullable', Rule::in(array_keys(SkillRespondent::CLASSIFICATIONS))],
            'partner_id' => 'nullable|exists:partners,id',
        ]);
        $respondent->classification = $data['classification'] ?? null;
        if ($request->has('partner_id')) {
            $respondent->partner_id = $data['partner_id'] ?? null;
        }
        // Ao deixar de ser Parceiro, desvincula a empresa parceira.
        if (($data['classification'] ?? null) !== 'parceiro') {
            $respondent->partner_id = null;
        }
        $respondent->save();

        return response()->json([
            'id' => $respondent->id,
            'classification' => $respondent->classification,
            'classification_label' => $respondent->classification ? (SkillRespondent::CLASSIFICATIONS[$respondent->classification] ?? null) : null,
            'blacklist' => $respondent->classification === 'blacklist',
            'partner_id' => $respondent->partner_id ? (string) $respondent->partner_id : '',
        ]);
    }

    /** Exclui um respondente e todo o seu histórico (submissões, respostas, convites). */
    public function destroy(int $id): JsonResponse
    {
        SkillRespondent::findOrFail($id);
        $this->purge([$id]);

        return response()->json(['deleted' => 1]);
    }

    /** Exclusão em massa de respondentes (com histórico). */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'respondent_ids' => 'required|array|min:1',
            'respondent_ids.*' => 'integer',
        ]);
        $deleted = $this->purge($data['respondent_ids']);

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Remove respondentes + dependências. answers caem por cascade da submissão;
     * hire_cards por cascade do respondente. Submissões e convites deletados
     * explicitamente (FK nullOnDelete deixaria órfãos).
     */
    private function purge(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            DB::table('skill_submissions')->whereIn('respondent_id', $ids)->delete();
            DB::table('skill_survey_invites')->whereIn('respondent_id', $ids)->delete();

            return DB::table('skill_respondents')->whereIn('id', $ids)->delete();
        });
    }
}
