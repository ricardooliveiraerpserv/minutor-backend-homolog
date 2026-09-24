<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\HelpDeskKbArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Help Desk — Base de Conhecimento: artigos (gestão interna). Leitura do cliente vai pelo Portal. */
class HelpDeskKbArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $arts = HelpDeskKbArticle::query()
            ->with(['category:id,name', 'author:id,name'])
            ->when($request->filled('kb_category_id'), fn ($q) => $q->where('kb_category_id', $request->kb_category_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) =>
                $w->where('title', 'ilike', '%' . $request->search . '%')->orWhere('body', 'ilike', '%' . $request->search . '%')))
            ->orderByDesc('pinned')->orderByDesc('updated_at')->get();
        return response()->json(['data' => $arts]);
    }

    /**
     * Sugestão de artigos na abertura do chamado: publicados, por relevância no texto digitado.
     * Cliente só vê visibility=customer; agente vê customer+internal (se o perfil permitir sugestões).
     */
    public function suggest(Request $request, \App\Services\HelpDeskAccessPolicy $access): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3) return response()->json(['data' => []]);

        $user = $request->user();
        $isClient = $user && ($user->isCliente() || $user->isParceiroAdmin());
        if (!$isClient && !$access->kbSuggestionsEnabled($user)) return response()->json(['data' => []]);

        // Tokeniza: casa por PALAVRA (recall) — "erro CTE" acha "erro ao processar CTE".
        $terms = collect(preg_split('/\s+/', $q))->filter(fn ($t) => mb_strlen($t) >= 3)->take(6)->values();
        if ($terms->isEmpty()) $terms = collect([$q]);

        $arts = HelpDeskKbArticle::published()
            ->when($isClient, fn ($x) => $x->where('visibility', 'customer'))
            ->where(function ($w) use ($terms) {
                foreach ($terms as $t) {
                    $w->orWhere('title', 'ilike', "%{$t}%")
                      ->orWhere('excerpt', 'ilike', "%{$t}%")->orWhere('body', 'ilike', "%{$t}%");
                }
            })
            ->orderByDesc('pinned')->orderByDesc('helpful_count')->orderByDesc('views_count')
            ->limit(6)->get(['id', 'title', 'excerpt', 'slug', 'visibility']);

        return response()->json(['data' => $arts]);
    }

    public function show(HelpDeskKbArticle $article): JsonResponse
    {
        return response()->json(['data' => $article->load(['category:id,name', 'author:id,name'])]);
    }

    private function rules(bool $creating): array
    {
        return [
            'kb_category_id' => 'nullable|exists:helpdesk_kb_categories,id',
            'title'          => ($creating ? 'required' : 'sometimes') . '|string|max:200',
            'excerpt'        => 'nullable|string',
            'body'           => 'nullable|string',
            'status'         => 'nullable|in:draft,published,archived',
            'visibility'     => 'nullable|in:customer,internal',
            'pinned'         => 'nullable|boolean',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $v['slug'] = Str::slug($v['title']) . '-' . Str::lower(Str::random(6));
        $v['author_user_id'] = $request->user()?->id;
        if (($v['status'] ?? 'draft') === 'published') $v['published_at'] = now();
        return response()->json(['data' => HelpDeskKbArticle::create($v)], 201);
    }

    public function update(Request $request, HelpDeskKbArticle $article): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        // Carimba published_at na primeira publicação.
        if (($v['status'] ?? null) === 'published' && !$article->published_at) $v['published_at'] = now();
        $article->update($v);
        return response()->json(['data' => $article->fresh()]);
    }

    public function publish(Request $request, HelpDeskKbArticle $article): JsonResponse
    {
        $article->update(['status' => 'published', 'published_at' => $article->published_at ?? now()]);
        return response()->json(['data' => $article->fresh()]);
    }

    public function destroy(HelpDeskKbArticle $article): JsonResponse
    {
        $article->delete(); // soft delete
        return response()->json(null, 204);
    }

    // ── Anexos/imagens do artigo (entity HELPDESK_KB_ARTICLE) ─────────────────
    public function attachments(Request $request, HelpDeskKbArticle $article, AttachmentService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->listFor('HELPDESK_KB_ARTICLE', $article->id, $request->user())->values()]);
    }

    public function uploadAttachment(Request $request, HelpDeskKbArticle $article, AttachmentService $svc): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:25600']);
        $category = $request->input('category', 'image');
        $att = $svc->store($request->user(), [
            'entity_type' => 'HELPDESK_KB_ARTICLE', 'entity_id' => $article->id,
            'category' => in_array($category, ['image', 'attachment'], true) ? $category : 'image',
            'file' => $request->file('file'),
        ], $request);
        return response()->json(['data' => $att], 201);
    }

    public function downloadAttachment(Request $request, HelpDeskKbArticle $article, Attachment $attachment, AttachmentService $svc)
    {
        abort_unless($attachment->entity_type === 'HELPDESK_KB_ARTICLE' && (int) $attachment->entity_id === $article->id, 404);
        return $svc->downloadStream($attachment, $request->user(), $request);
    }

    public function deleteAttachment(Request $request, HelpDeskKbArticle $article, Attachment $attachment, AttachmentService $svc): JsonResponse
    {
        abort_unless($attachment->entity_type === 'HELPDESK_KB_ARTICLE' && (int) $attachment->entity_id === $article->id, 404);
        $svc->softDelete($attachment, $request->user(), $request);
        return response()->json(null, 204);
    }
}
