<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\GmudPackage;
use App\Models\HelpDeskCategory;
use App\Models\HelpDeskService;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\HelpDeskTicketEvent;
use App\Models\Project;
use App\SourceCode\Gmud\GmudPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GMUD — Envio de Fontes de PROJETO (sem ticket). Rotina standalone (fora de um chamado) para
 * publicar fontes de um projeto no Git do cliente via o MESMO motor governado da GMUD:
 *
 *   1) store    → cria um chamado automático (serviço/categoria GMUD), RECEBE o ZIP como pacote
 *                 governado (GmudPackageService::receiveFromUpload) e vincula o pacote ao projeto.
 *                 NÃO publica. O FE abre o pop-up de publicação (GmudPublishModal) pelo package id.
 *   2) publish  → é o endpoint EXISTENTE /gmud/packages/{package}/publish (commit atômico no Git).
 *   3) finalize → depois que o usuário publicou: documenta a publicação numa interação visível ao
 *                 cliente (dispara o e-mail de documentação) e ENCERRA o chamado automático.
 *
 * Gate: permission.or.admin:source_docs.gmud_publish (interno ERPSERV).
 */
class GmudProjectSubmissionController extends Controller
{
    public function __construct(private GmudPackageService $service)
    {
    }

    /** POST /gmud/project-submission — recebe o ZIP de um PROJETO e devolve o pacote (sem publicar). */
    public function store(Request $request): JsonResponse
    {
        // Gate por EMPRESA: só quem trabalha com fonte (git) envia GMUD de projeto.
        abort_unless(\App\Models\Company::userCanUseSourceModule(), 403, 'Esta empresa não tem o módulo de código-fonte habilitado.');

        $v = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'project_id'  => ['required', 'exists:projects,id'],
            'description' => ['nullable', 'string', 'max:5000'], // nota do envio → documentação ao cliente
            'file'        => ['required', 'file', 'mimes:zip', 'max:51200'], // 50 MB (= wizard)
        ]);

        $customer = Customer::findOrFail($v['customer_id']);
        $project  = Project::findOrFail($v['project_id']);
        // O projeto precisa pertencer ao cliente selecionado (segurança: evita cruzar fontes de clientes).
        abort_unless((int) $project->customer_id === (int) $customer->id, 422, 'O projeto não pertence ao cliente selecionado.');

        $user = $request->user();
        // Escopo da Central de Fontes: só envia para empresas que o usuário enxerga
        // (coordenador: todas exceto ERPSERV; demais: alocados; ERPSERV só admin).
        abort_unless(app(\App\SourceCode\SourceDocCustomerScope::class)->canAccessCustomerId($user, (int) $customer->id), 403, 'Você não tem acesso a esta empresa.');

        // Destinatário do e-mail de documentação = contato do cliente (1º com e-mail). Se não houver,
        // o e-mail é pulado (sem destinatário) — o envio continua documentado no projeto e no chamado.
        $contactId = \App\Models\CustomerContact::where('customer_id', $customer->id)
            ->whereNotNull('email')->where('email', '!=', '')->orderBy('id')->value('id');

        // Chamado automático (nasce ABERTO para permitir documentar/encerrar no finalize). Reusa a
        // família "Solução com GMUD": serviço dedicado + categoria de solicitação de serviço.
        $ticket = HelpDeskTicket::create([
            'subject'             => 'Envio de fontes — ' . $project->name,
            'description'         => $v['description'] ?? null,   // nota do envio (vai pra documentação ao cliente)
            'customer_id'         => $customer->id,
            'customer_contact_id' => $contactId,            // recipiente do e-mail ao cliente (finalize)
            'project_id'          => $project->id,
            'created_by_id'       => $user->id,
            'requester_user_id'   => $user->id,
            'assignee_id'         => $user->id,
            'status_id'           => $this->openStatusId(),  // nasce aberto; finalize encerra
            'category_id'         => $this->categoryId(),
            'service_id'          => $this->serviceId(),      // "Envio de Fontes (GMUD)" (cria se faltar)
            'level'               => 'N1',
            'priority'            => 'normal',
            'channel'             => 'interno',
            'source_system'       => 'gmud_project',
        ]);

        // Número do chamado (mesma numeração do portal, por empresa) — sem isto o ticket_number
        // fica vazio e a BUSCA GLOBAL não encontra o chamado (só o FE mostra "#id" como fallback).
        if (! $ticket->ticket_number) {
            $ticket->update(['ticket_number' => \App\Services\HelpDeskTicketNumber::next((int) $ticket->company_id)]);
        }

        // RECEBIMENTO governado (reusa exatamente o motor do wizard) — sem commit.
        $package = $this->service->receiveFromUpload($ticket, $user, $request->file('file'));

        // Classificação = projeto; a PASTA de destino é o nome do projeto (classification 'projeto').
        $package->update([
            'project_id'     => $project->id,
            'classification' => 'projeto',
            'project_name'   => $project->name,
            'project_folder' => $project->name,
        ]);

        return response()->json([
            'data' => [
                'package_id'    => $package->id,
                'ticket_id'     => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'project_id'    => $project->id,
                'project_name'  => $project->name,
                'classification'=> 'projeto',
            ],
        ], 201);
    }

    /**
     * POST /gmud/project-submission/{package}/finalize — após o publish (commit no Git), documenta a
     * publicação numa interação visível ao cliente (dispara o e-mail de documentação) e ENCERRA o
     * chamado automático. Idempotente: se já encerrado/documentado, não duplica.
     */
    public function finalize(Request $request, GmudPackage $package): JsonResponse
    {
        abort_unless($package->project_id, 422, 'Este pacote não é um envio de fontes de projeto.');
        // Normalmente exige publicação (commit) antes. EXCEÇÃO: todos os fontes já são IDÊNTICOS ao Git
        // (nada a commitar) → ainda assim DOCUMENTAMOS o envio ("sem alteração") e encerramos o chamado.
        $allIdentical = $package->status !== GmudPackage::STATUS_PUBLISHED
            && $package->files()->where('is_source', true)->exists()
            && ! $package->files()->where('is_source', true)->where('match_status', '!=', 'identical')->exists();
        abort_unless($package->status === GmudPackage::STATUS_PUBLISHED || $allIdentical, 422, 'Publique os fontes no Git antes de finalizar.');

        $ticket = $package->ticket;
        if (! $ticket) {
            return response()->json(['message' => 'Chamado do envio não encontrado.'], 422);
        }
        $ticket->loadMissing('status');
        $project = $package->project;

        // Idempotência: já documentado (interação de doc gravada) → não repete (não reenvia e-mail).
        $already = HelpDeskTicketComment::where('ticket_id', $ticket->id)
            ->where('channel', 'gmud_project_doc')->exists();

        if (! $already) {
            $pub = $this->publicationInfo($package);
            // Descrição do envio: a que veio agora (FE) tem precedência; senão a persistida no chamado.
            $description = $request->filled('description') ? (string) $request->input('description') : (string) ($ticket->description ?? '');
            $body = $this->documentationBody($package, $project, $pub, $description);

            // Interação VISÍVEL AO CLIENTE criada diretamente (o chamado pode ter sido fechado): o
            // addComment barraria ticket terminal. Documenta o envio no chamado E no projeto (project_id).
            $comment = $ticket->comments()->create([
                'author_user_id' => $request->user()?->id,
                'body'           => $body,
                'visibility'     => 'customer',
                'channel'        => 'gmud_project_doc',
                'is_system'      => false,
            ]);

            $ticket->forceFill(['gmud_source_status' => 'atualizado', 'last_activity_at' => now()])->save();
            HelpDeskTicketEvent::log($ticket->id, 'comment', ['meta' => ['comment_id' => $comment->id, 'visibility' => 'customer', 'gmud_project' => true]]);

            // E-mail de documentação ao cliente (mesma fila/engine das soluções visíveis ao cliente).
            try {
                \App\Jobs\SendHelpDeskEmailJob::dispatch($ticket->id, $comment->id)
                    ->onConnection(config('queue.helpdesk_email_connection'))->onQueue('emails');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('gmud_project.finalize_email_failed', ['ticket' => $ticket->id, 'error' => $e->getMessage()]);
            }
        }

        // ENCERRA o chamado (idempotente: se já terminal, mantém).
        if (! optional($ticket->status)->is_terminal) {
            if ($closedId = $this->closedStatusId()) {
                $ticket->forceFill(['status_id' => $closedId])->save();
                HelpDeskTicketEvent::log($ticket->id, 'status_changed', ['meta' => ['to_status_id' => $closedId, 'reason' => 'gmud_project_finalize']]);
            }
        }

        return response()->json([
            'data' => [
                'package_id'    => $package->id,
                'ticket_id'     => $ticket->id,
                'ticket_number' => $ticket->fresh()->ticket_number,
                'project_id'    => $package->project_id,
                'closed'        => true,
                'documented'    => ! $already,
            ],
        ]);
    }

    /** GET /gmud/project-submissions — TODAS as GMUDs enviadas por esta rotina (todos os projetos). */
    public function index(Request $request): JsonResponse
    {
        // whereHas('project') aplica o CompanyScope do Project → a lista fica escopada à EMPRESA ATIVA
        // (o projeto/cliente resolvem e o "Ver no projeto" funciona; sem isto, GMUD de outra empresa
        // aparecia com projeto "—" e o link dava 404 por escopo). Troque de empresa p/ ver a de cada uma.
        $q = GmudPackage::whereHas('project')
            ->where('classification', 'projeto');
        // Escopo de CLIENTE da Central de Fontes (coordenador: todos exceto ERPSERV; demais: alocados).
        app(\App\SourceCode\SourceDocCustomerScope::class)->applyScope($q, $request->user(), 'gmud_packages.customer_id');
        $packages = $q
            ->where('status', GmudPackage::STATUS_PUBLISHED)
            ->with(['ticket:id,ticket_number', 'project:id,name,code', 'customer:id,name'])
            ->withCount(['files as published_files_count' => fn ($q) => $q->where('is_source', true)->whereNotNull('dest_git_path')->where('action', '!=', 'skip')])
            ->orderByDesc('id')->limit(1000)->get()
            ->map(function (GmudPackage $p) {
                $pub = $this->publicationInfo($p);
                return [
                    'id'            => $p->id,
                    'project_id'    => $p->project_id,
                    'project_name'  => optional($p->project)->name,
                    'project_code'  => optional($p->project)->code,
                    'customer_name' => optional($p->customer)->name,
                    'ticket_id'     => $p->ticket_id,
                    'ticket_number' => optional($p->ticket)->ticket_number,
                    'project_folder'=> $p->project_folder,
                    'files_count'   => $p->published_files_count,
                    'commit_sha'    => $pub['commit_sha'] ?? null,
                    'repo'          => $pub['repo'] ?? null,
                    'branch'        => $pub['branch'] ?? null,
                    'published_at'  => $pub['published_at'] ?? null,
                ];
            })->values();

        return response()->json(['data' => $packages]);
    }

    /**
     * DELETE /gmud/project-submission/{package} — CANCELA um envio ainda NÃO publicado: apaga o
     * pacote (e evidências) e o chamado automático que nasceu com ele (sem atividade real). Usado
     * quando o .zip não tinha fonte ou o usuário desiste — não deixa chamado/pacote órfão.
     */
    public function cancel(GmudPackage $package): JsonResponse
    {
        abort_unless($package->project_id, 422, 'Este pacote não é um envio de fontes de projeto.');
        abort_if($package->status === GmudPackage::STATUS_PUBLISHED, 422, 'Este envio já foi publicado — não pode ser cancelado.');

        $ticketId = $package->ticket_id;
        \Illuminate\Support\Facades\DB::transaction(function () use ($package, $ticketId) {
            if ($ticketId) {
                HelpDeskTicketComment::where('ticket_id', $ticketId)->delete();
                HelpDeskTicketEvent::where('ticket_id', $ticketId)->delete();
            }
            $package->files()->delete();
            $package->delete();
            if ($ticketId && ($t = HelpDeskTicket::find($ticketId)) && $t->source_system === 'gmud_project') {
                $t->delete();
            }
        });

        return response()->json(['data' => ['canceled' => true]]);
    }

    /** GET /projects/{project}/gmud-submissions — pacotes GMUD publicados do projeto (documentação). */
    public function projectSubmissions(Request $request, Project $project): JsonResponse
    {
        abort_unless(app(\App\SourceCode\SourceDocCustomerScope::class)->canAccessCustomerId($request->user(), (int) $project->customer_id), 403, 'Você não tem acesso a esta empresa.');
        $packages = GmudPackage::where('project_id', $project->id)
            ->where('status', GmudPackage::STATUS_PUBLISHED)
            ->with('ticket:id,ticket_number')
            ->withCount(['files as published_files_count' => fn ($q) => $q->where('is_source', true)->whereNotNull('dest_git_path')->where('action', '!=', 'skip')])
            ->orderByDesc('id')->get()
            ->map(function (GmudPackage $p) {
                $pub = $this->publicationInfo($p);
                return [
                    'id'            => $p->id,
                    'ticket_id'     => $p->ticket_id,
                    'ticket_number' => optional($p->ticket)->ticket_number,
                    'original_name' => $p->original_name,
                    'project_folder'=> $p->project_folder,
                    'files_count'   => $p->published_files_count,
                    'commit_sha'    => $pub['commit_sha'] ?? null,
                    'repo'          => $pub['repo'] ?? null,
                    'branch'        => $pub['branch'] ?? null,
                    'published_at'  => $pub['published_at'] ?? null,
                    // Por fonte publicado: o que foi ALTERADO (change_diff) + o DOC gerado (source_doc_id).
                    'files'         => $this->publishedFilesWithDoc($p),
                ];
            })->values();

        return response()->json(['data' => $packages]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Fontes publicados do pacote + trecho alterado (change_diff) e id do doc gerado por arquivo. */
    private function publishedFilesWithDoc(GmudPackage $package): array
    {
        $files = $package->files()->where('is_source', true)->whereNotNull('dest_git_path')
            ->where('action', '!=', 'skip')->orderBy('dest_git_path')->get();
        if ($files->isEmpty()) {
            return [];
        }
        $docsByPath = collect();
        $repo = $package->source_repo_id ? \App\Models\ClientSourceRepo::find($package->source_repo_id) : null;
        if ($repo) {
            $docsByPath = \App\Models\SourceDoc::with('currentVersion:id,source_doc_id,change_diff,change_diff_meta')
                ->where('owner', $repo->owner)->where('repository', $repo->repository)
                ->where('branch', $repo->branch ?: 'main')
                ->whereIn('path', $files->pluck('dest_git_path')->all())
                ->get(['id', 'path', 'current_version_id'])->keyBy('path');
        }
        return $files->map(function ($f) use ($docsByPath) {
            $doc = $docsByPath->get($f->dest_git_path);
            $cv  = $doc?->currentVersion;
            return [
                'filename'         => $f->filename ?: basename((string) $f->dest_git_path),
                'dest_git_path'    => $f->dest_git_path,
                'source_doc_id'    => $doc?->id,
                'change_diff'      => $cv?->change_diff,
                'change_diff_meta' => $cv?->change_diff_meta,
            ];
        })->values()->all();
    }

    /** Corpo da interação de documentação (visível ao cliente). */
    private function documentationBody(GmudPackage $package, ?Project $project, ?array $pub, string $description = ''): string
    {
        // Descrição do envio (nota do consultor) — logo após o cabeçalho, quando informada.
        $desc = trim($description) !== '' ? "📝  " . trim($description) . "\n\n" : '';
        $committed = $pub && ! empty($pub['commit_sha']);

        if ($committed) {
            $sha  = substr((string) $pub['commit_sha'], 0, 10);
            $repo = $pub['repo'] ?? '—';
            $files = $package->files()->where('is_source', true)->whereNotNull('dest_git_path')->where('action', '!=', 'skip')
                ->orderBy('dest_git_path')->get();
            $lines = $files->map(fn ($f) => "📄  {$f->dest_git_path}")->implode("\n");
            $head = "📦  Fontes do projeto \"" . ($project?->name ?? '—') . "\" publicados no repositório do cliente.";
            $meta = "🗂️  Repositório: {$repo}" . (! empty($pub['branch']) ? " @ {$pub['branch']}" : '') . "\n🔖  Commit: {$sha}\n📁  Pasta: " . ($package->project_folder ?: '/');
        } else {
            // Sem commit: todos os fontes já estão IDÊNTICOS ao Git (nada mudou) — lista pelos paths já versionados.
            $files = $package->files()->where('is_source', true)->whereNotNull('matched_git_path')
                ->orderBy('matched_git_path')->get();
            $lines = $files->map(fn ($f) => "📄  {$f->matched_git_path}")->implode("\n");
            $head = "📦  Fontes do projeto \"" . ($project?->name ?? '—') . "\" já presentes no repositório do cliente (sem alteração nesta verificação).";
            $meta = "🔖  Sem nova publicação — os fontes já estavam versionados no Git.";
        }

        return trim($head . "\n\n" . $desc . $meta . "\n\n" . ($lines !== '' ? "Arquivos:\n" . $lines : '') . "\n\n✅  Envio documentado no projeto.");
    }

    /** Metadados da publicação (commit/repo/branch/data) — lidos do evento gmud_package_published. */
    private function publicationInfo(GmudPackage $package): ?array
    {
        $ev = HelpDeskTicketEvent::where('ticket_id', $package->ticket_id)
            ->where('event_type', 'gmud_package_published')
            ->whereRaw("meta->>'package_id' = ?", [(string) $package->id])
            ->orderByDesc('id')->first(['created_at', 'meta']);
        if (! $ev) {
            return null;
        }
        $meta = is_array($ev->meta) ? $ev->meta : (json_decode((string) $ev->meta, true) ?: []);
        return [
            'commit_sha'   => $meta['commit_sha'] ?? null,
            'repo'         => $meta['repo'] ?? null,
            'branch'       => $meta['branch'] ?? null,
            'published_at' => optional($ev->created_at)->toIso8601String(),
        ];
    }

    /** Status inicial ABERTO (padrão) p/ o chamado automático. */
    private function openStatusId(): ?int
    {
        return optional(HelpDeskStatus::default())->id;
    }

    /** Status "Fechado" (encerrado) da empresa ativa; fallback p/ o default (mesmo do SourceCodeRequest). */
    private function closedStatusId(): ?int
    {
        return HelpDeskStatus::where('is_terminal', true)->where('is_resolved', true)->value('id')
            ?? HelpDeskStatus::where('key', 'fechado')->value('id')
            ?? optional(HelpDeskStatus::default())->id;
    }

    /** Categoria "Solicitação de serviço". */
    private function categoryId(): ?int
    {
        return HelpDeskCategory::whereRaw('lower(name) = ?', ['solicitação de serviço'])->value('id');
    }

    /** Serviço "Envio de Fontes (GMUD)" (cria uma vez se não existir). */
    private function serviceId(): ?int
    {
        return HelpDeskService::firstOrCreate(['name' => 'Envio de Fontes (GMUD)'], ['active' => true])->id;
    }
}
