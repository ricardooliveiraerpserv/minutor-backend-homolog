<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Models\SkillMatrixVersion;
use App\Models\SkillSubmission;
use App\Models\SkillSurvey;
use App\Services\SkillSurveyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestão da matriz única e suas versões (competências + versionamento).
 * Gate de rota: competencias.manage.
 */
class SkillMatrixVersionController extends Controller
{
    public function __construct(private readonly SkillSurveyService $service)
    {
    }

    /** Lista de versões da matriz. */
    public function versions(): JsonResponse
    {
        $versions = SkillMatrixVersion::query()
            ->withCount('items')
            ->orderByDesc('number')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'number' => $v->number,
                'label' => $v->label,
                'status' => $v->status,
                'skills_count' => $v->skills_count,
                'items_count' => $v->items_count,
                'published_at' => $v->published_at,
            ]);

        return response()->json(['versions' => $versions]);
    }

    /** Competências atuais (matriz viva), agrupadas por categoria. */
    public function skills(): JsonResponse
    {
        $order = array_flip(SkillSurveyService::CATEGORY_ORDER);
        $sections = Skill::query()
            ->orderBy('category')->orderBy('name')
            ->get()
            ->groupBy('category')
            ->map(fn ($items, $cat) => [
                'category' => $cat,
                'count' => $items->count(),
                'items' => $items->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'type' => $s->type])->values(),
            ])
            ->sortBy(fn ($sec) => $order[$sec['category']] ?? 99)
            ->values();

        return response()->json([
            'sections' => $sections,
            'total' => Skill::count(),
            'categories' => SkillSurveyService::CATEGORY_ORDER,
        ]);
    }

    public function storeSkill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'category' => 'required|string|max:80',
            'type' => 'nullable|in:module,technology,process',
        ]);
        $exists = Skill::where('name', $data['name'])->where('category', $data['category'])->exists();
        abort_if($exists, 422, 'Já existe uma competência com esse nome nesta categoria.');

        $skill = Skill::create(array_merge($data, ['type' => $data['type'] ?? 'technology']));

        return response()->json($skill, 201);
    }

    public function updateSkill(Request $request, int $id): JsonResponse
    {
        $skill = Skill::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'category' => 'sometimes|string|max:80',
            'type' => 'nullable|in:module,technology,process',
        ]);
        $skill->fill($data)->save();

        return response()->json($skill);
    }

    public function destroySkill(int $id): JsonResponse
    {
        $skill = Skill::findOrFail($id);
        // Snapshots (skill_matrix_version_items) preservam name/category via nullOnDelete —
        // o histórico das versões publicadas não se perde.
        $skill->delete();

        return response()->json(['deleted' => true]);
    }

    /** Exclui uma versão da matriz. Bloqueia se for a única ou se estiver em uso. */
    public function destroyVersion(int $id): JsonResponse
    {
        $version = SkillMatrixVersion::findOrFail($id);
        abort_if(SkillMatrixVersion::count() <= 1, 422, 'Não é possível excluir a única versão da matriz.');

        $surveys = SkillSurvey::where('matrix_version_id', $id)->count();
        $submissions = SkillSubmission::where('matrix_version_id', $id)->count();
        abort_if(
            $surveys > 0 || $submissions > 0,
            422,
            "Versão em uso ({$surveys} pesquisa(s), {$submissions} resposta(s)) — não pode ser excluída."
        );

        $wasActive = $version->status === SkillMatrixVersion::STATUS_ACTIVE;
        $version->delete(); // items em cascata

        // Se excluiu a ativa, promove a versão mais recente restante.
        if ($wasActive) {
            $latest = SkillMatrixVersion::latest('number')->first();
            if ($latest && $latest->status !== SkillMatrixVersion::STATUS_ACTIVE) {
                $latest->update(['status' => SkillMatrixVersion::STATUS_ACTIVE]);
            }
        }

        return response()->json(['deleted' => true]);
    }

    /** Renomeia uma categoria (atualiza todas as competências dela). */
    public function renameCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|string',
            'to' => 'required|string|max:80',
        ]);
        $count = Skill::where('category', $data['from'])->update(['category' => $data['to']]);

        return response()->json(['renamed' => $count, 'from' => $data['from'], 'to' => $data['to']]);
    }

    /** Exclui uma categoria inteira (todas as competências dela). Snapshots preservados. */
    public function destroyCategory(string $name): JsonResponse
    {
        $count = Skill::where('category', $name)->delete();

        return response()->json(['deleted' => $count, 'category' => $name]);
    }

    /** Publica uma nova versão (snapshot das competências atuais). */
    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate(['label' => 'nullable|string|max:120']);
        $version = $this->service->publishVersion($data['label'] ?? null);

        return response()->json([
            'id' => $version->id,
            'number' => $version->number,
            'label' => $version->label,
            'status' => $version->status,
            'skills_count' => $version->skills_count,
            'published_at' => $version->published_at,
        ], 201);
    }
}
