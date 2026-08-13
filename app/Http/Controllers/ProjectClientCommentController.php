<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectClientComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conversa GLOBAL do projeto (cliente ↔ equipe). Um único fio por projeto.
 * - Cliente do MESMO customer: /client/projects/{project}/comments (GET/POST).
 * - Equipe interna: /projects/{project}/client-comments (GET/POST).
 * Sem horas/valores. Mantém-se separado do Diário interno e dos comentários por atividade.
 */
class ProjectClientCommentController extends Controller
{
    // ---------------- Cliente ----------------
    public function clientIndex(Project $project, Request $request): JsonResponse
    {
        if (($e = $this->assertClient($project, $request)) !== null) return $e;
        return response()->json($this->list($project));
    }

    public function clientStore(Project $project, Request $request): JsonResponse
    {
        if (($e = $this->assertClient($project, $request)) !== null) return $e;
        return $this->create($project, $request, fromClient: true);
    }

    // ---------------- Equipe ----------------
    public function teamIndex(Project $project, Request $request): JsonResponse
    {
        if (($e = $this->assertTeam($request)) !== null) return $e;
        return response()->json($this->list($project));
    }

    public function teamStore(Project $project, Request $request): JsonResponse
    {
        if (($e = $this->assertTeam($request)) !== null) return $e;
        return $this->create($project, $request, fromClient: false);
    }

    // ---------------- Helpers ----------------
    private function list(Project $project): array
    {
        $items = ProjectClientComment::query()
            ->where('project_id', $project->id)
            ->with('author:id,name')
            ->orderBy('created_at')
            ->limit(500)
            ->get()
            ->map(fn ($c) => $this->present($c));

        return ['items' => $items];
    }

    private function create(Project $project, Request $request, bool $fromClient): JsonResponse
    {
        $data = $request->validate([
            'text'       => 'nullable|string|max:5000',
            'attachment' => 'nullable|file|max:20480|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
        ]);

        $text = trim((string) ($data['text'] ?? ''));
        $hasAttach = $request->hasFile('attachment');
        if ($text === '' && !$hasAttach) {
            return response()->json(['message' => 'Comentário precisa de texto ou anexo.'], 422);
        }

        $attach = [];
        if ($hasAttach) {
            $file = $request->file('attachment');
            $path = $file->store("project-comments/{$project->id}", 'public');
            $attach = [
                'attachment_path'          => $path,
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime'          => $file->getMimeType(),
                'attachment_size'          => $file->getSize(),
            ];
        }

        $c = ProjectClientComment::create(array_merge([
            'project_id'  => $project->id,
            'user_id'     => $request->user()->id,
            'body'        => $text !== '' ? $text : null,
            'from_client' => $fromClient,
        ], $attach));

        return response()->json($this->present($c->load('author:id,name')), 201);
    }

    private function present(ProjectClientComment $c): array
    {
        return [
            'id'                       => $c->id,
            'body'                     => $c->body,
            'from_client'              => (bool) $c->from_client,
            'author_name'              => $c->author?->name,
            'attachment_path'          => $c->attachment_path,
            'attachment_original_name' => $c->attachment_original_name,
            'created_at'               => $c->created_at?->toIso8601String(),
        ];
    }

    private function assertClient(Project $project, Request $request): ?JsonResponse
    {
        $u = $request->user();
        if (!$u || !$u->isCliente()) {
            return response()->json(['message' => 'Endpoint exclusivo do perfil cliente.'], 403);
        }
        if ((int) $u->customer_id !== (int) $project->customer_id) {
            return response()->json(['message' => 'Você não participa deste projeto.'], 403);
        }
        return null;
    }

    private function assertTeam(Request $request): ?JsonResponse
    {
        $u = $request->user();
        if (!$u || $u->isCliente()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }
        return null;
    }
}
