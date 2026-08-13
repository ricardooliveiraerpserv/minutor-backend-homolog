<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\ContractRequestMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Comentários NATIVOS do projeto — canal cliente + equipe para projetos que NÃO
 * vieram de uma Demanda (sem contract_request_id). Reusa a tabela
 * `contract_request_messages` (keyada por `project_id`) e a mesma camada de
 * anexos (entity_type=REQUEST_MESSAGE), então o FE reusa o ReqChatPanel.
 * Projetos vindos de Demanda continuam usando o canal da requisição (continuidade).
 */
class ProjectCommentController extends Controller
{
    private const TEAM = ['admin', 'coordenador', 'consultor', 'administrativo', 'parceiro_admin'];

    /** Cliente do mesmo customer OU membro da equipe interna. */
    private function authorize(User $user, Project $project): bool
    {
        if ($user->isCliente()) {
            return (int) $user->customer_id === (int) $project->customer_id;
        }
        return in_array($user->type, self::TEAM, true);
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        $user = auth()->user();
        if (!$this->authorize($user, $project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $msgs = ContractRequestMessage::where('project_id', $project->id)
            ->where('visibility', 'client')
            ->with(['author:id,name', 'attachments'])
            ->orderBy('created_at')
            ->get();

        return response()->json($msgs);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $user = auth()->user();
        if (!$this->authorize($user, $project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $request->validate([
            'message' => 'nullable|string|max:2000',
            'files'   => 'nullable|array|max:10',
            'files.*' => 'file|max:20480',
        ]);

        // Anexos: aceita qualquer tipo (foto de celular HEIC/HEIF, vídeos, etc.),
        // bloqueando apenas executáveis/scripts (anti-RCE/XSS) — igual aos comentários da requisição.
        $blockedExt = [
            'php','phtml','phar','php3','php4','php5','php7','pht','pgif',
            'exe','com','bat','cmd','sh','bash','csh','ksh','run',
            'js','mjs','cjs','jar','msi','dll','scr','vbs','vbe','ps1','psm1',
            'html','htm','xhtml','shtml','svg','svgz','hta','wsf','wsh',
            'reg','cpl','asp','aspx','jsp','cgi','app','deb','apk',
        ];
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $ext = strtolower($file->getClientOriginalExtension());
                if (in_array($ext, $blockedExt, true)) {
                    return response()->json([
                        'message' => 'Tipo de arquivo não permitido por segurança: .' . $ext,
                    ], 422);
                }
            }
        }

        $text = (string) $request->input('message', '');
        if (!$text && !$request->hasFile('files')) {
            return response()->json(['message' => 'Mensagem ou anexo obrigatório.'], 422);
        }

        $msg = ContractRequestMessage::create([
            'project_id'          => $project->id,
            'contract_request_id' => null,
            'user_id'             => $user->id,
            'message'             => $text,
            'visibility'          => 'client',
        ]);

        if ($request->hasFile('files')) {
            $service = app(\App\Attachments\AttachmentService::class);
            foreach ($request->file('files') as $file) {
                $path = $file->store('req-message-attachments', 'public');
                $service->registerExisting($user, [
                    'entity_type'   => 'REQUEST_MESSAGE',
                    'entity_id'     => $msg->id,
                    'category'      => 'attachment',
                    'storage_path'  => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $file->getMimeType() ?: 'application/octet-stream',
                ]);
            }
        }

        // Menções @[id:Nome] (canônicas do picker) → persiste + inclui nos destinatários.
        $mentionedIds = \App\Services\MentionParser::extract($text, collect());
        foreach ($mentionedIds as $mid) {
            \App\Models\ContractRequestMessageMention::firstOrCreate([
                'message_id'        => $msg->id,
                'mentioned_user_id' => $mid,
            ]);
        }

        // Notifica a "outra ponta" (cliente↔equipe) + @-mencionados. Best-effort.
        try {
            $this->notifyComment($project, $msg, $user, $mentionedIds);
        } catch (\Throwable $e) {
            \Log::warning('project comment notif falhou', ['project_id' => $project->id, 'err' => $e->getMessage()]);
        }

        $msg->load(['author:id,name', 'attachments']);
        return response()->json($msg, 201);
    }

    /** IDs a notificar: se autor é cliente → equipe do projeto; se é equipe → clientes do customer. */
    private function notifyComment(Project $project, ContractRequestMessage $msg, User $author, array $mentionedIds): void
    {
        if ($author->isCliente()) {
            $ids = User::where('type', 'admin')->where('enabled', true)->pluck('id')
                ->merge($project->coordinators()->pluck('users.id'))
                ->merge($project->consultants()->pluck('users.id'));
            if ($project->customer?->executive_id) {
                $ids->push($project->customer->executive_id);
            }
        } else {
            $ids = User::where('type', 'cliente')
                ->where('customer_id', $project->customer_id)
                ->where('enabled', true)->pluck('id');
        }

        $recipients = $ids->merge($mentionedIds)->filter()
            ->reject(fn ($id) => (int) $id === (int) $author->id)
            ->unique()->values()->all();
        if (empty($recipients)) return;

        $base    = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url     = $base . '/contratos/pipeline?project=' . $project->id . '&tab=comments';
        $excerpt = \Illuminate\Support\Str::limit((string) ($msg->message ?: '(anexo)'), 500);
        $nome    = $project->name ?: $project->code ?: ('Projeto #' . $project->id);

        $n = \App\Models\AppNotification::create([
            'title'        => 'Novo comentário no projeto',
            'message'      => e($author->name) . ' comentou no projeto <b>' . e($nome) . '</b>: ' . e($excerpt),
            'type'         => 'info',
            'priority'     => 'medium',
            'target_users' => $recipients,
            'send_email'   => true,
            'visible'      => true,
            'cta_label'    => 'Abrir comentários',
            'cta_url'      => $url,
            'created_by'   => $author->id,
            'expires_at'   => now()->addDays(14),
        ]);

        try {
            app(\App\Http\Controllers\NotificationController::class)->emailNotification($n);
        } catch (\Throwable $e) {
            \Log::warning('project comment email falhou', ['project_id' => $project->id, 'err' => $e->getMessage()]);
        }
    }

    public function mentionableUsers(Request $request, Project $project): JsonResponse
    {
        $user = auth()->user();
        if (!$this->authorize($user, $project)) {
            return response()->json([], 403);
        }

        $executiveId = $project->customer?->executive_id;

        $admins = User::query()->where('type', 'admin')->where('enabled', true)
            ->select('id', 'name')->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => 'admin']);

        $executivo = $executiveId
            ? User::query()->where('id', $executiveId)->where('enabled', true)
                ->select('id', 'name')->get()
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => 'executivo'])
            : collect();

        $clientes = User::query()->where('type', 'cliente')
            ->where('customer_id', $project->customer_id)->where('enabled', true)
            ->select('id', 'name')->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => 'cliente']);

        return response()->json(
            $admins->concat($executivo)->concat($clientes)->unique('id')->sortBy('name')->values()
        );
    }

    public function downloadAttachment(Request $request, ContractRequestMessage $message, Attachment $attachment): mixed
    {
        $user = auth()->user();

        if ($user->isCliente() && (int) $user->customer_id !== (int) $message->project?->customer_id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        if ($attachment->entity_type !== 'REQUEST_MESSAGE' || (int) $attachment->entity_id !== (int) $message->id) {
            return response()->json(['message' => 'Anexo não encontrado'], 404);
        }

        return Storage::disk('public')->download($attachment->storage_path, $attachment->original_name);
    }
}
