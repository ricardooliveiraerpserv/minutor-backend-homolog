<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentService;
use App\Models\Customer;
use App\Models\HelpDeskCategory;
use App\Models\HelpDeskService;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\SourceCodeRequest;
use App\Models\SourceCodeRequestItem;
use App\Services\PermissionService;
use App\SourceCode\GitHubSourceService;
use App\SourceCode\SourceRepoResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1C — Solicitação de código-fonte: cria a solicitação, anexa cada fonte (versão mais
 * recente da branch) numa interação "Códigos-fonte anexados" do chamado, e finaliza.
 * READ-ONLY no GitHub (só resolveLatest = GET). Gate: source_code.request.
 */
class SourceCodeRequestController extends Controller
{
    /** Aviso gravado no chamado quando o cliente NÃO tem contrato (fonte = só exemplo). */
    public const NO_CONTRACT_DISCLAIMER = "⚠️  ATENÇÃO — Cliente SEM contrato\n"
        . "Os códigos-fonte abaixo servem apenas como EXEMPLO/referência. O fonte original deve ser "
        . "solicitado diretamente ao cliente, e é possível que existam fontes que não estejam em nossa posse.";

    private function authorize(Request $request): void
    {
        $perms = PermissionService::for($request->user());
        abort_unless(in_array('*', $perms, true) || in_array('source_code.request', $perms, true), 403, 'Sem permissão para solicitar código-fonte.');
    }

    /** Cria a solicitação: (cria/vincula chamado) + interação + itens (pending). */
    public function store(Request $request, SourceRepoResolver $resolver): JsonResponse
    {
        $this->authorize($request);
        $v = $request->validate([
            'customer_id'        => 'required|exists:customers,id',
            'ticket_id'          => 'nullable|exists:helpdesk_tickets,id',
            'items'              => 'required|array|min:1|max:30',
            'items.*.owner'      => 'required|string|max:120',
            'items.*.repository' => 'required|string|max:140',
            'items.*.branch'     => 'required|string|max:140',
            'items.*.path'       => 'required|string|max:500',
        ]);
        $customer = Customer::findOrFail($v['customer_id']);
        // Segurança: cada item precisa pertencer a um repo ATIVO autorizado do cliente.
        foreach ($v['items'] as $it) {
            $resolver->assertAuthorized($customer, $it['owner'], $it['repository'], $it['path']);
        }
        $user = $request->user();

        $req = DB::transaction(function () use ($v, $customer, $user) {
            $ticket = !empty($v['ticket_id'])
                ? HelpDeskTicket::findOrFail($v['ticket_id'])
                : HelpDeskTicket::create([
                    'subject'           => 'Solicitação de código-fonte — ' . $customer->name,
                    'customer_id'       => $customer->id,
                    'created_by_id'     => $user->id,
                    'requester_user_id' => $user->id,
                    'assignee_id'       => $user->id,               // responsável = quem solicita
                    'status_id'         => $this->closedStatusId(), // nasce ENCERRADO (Fechado)
                    'category_id'       => $this->categoryId(),     // Solicitação de serviço
                    'service_id'        => $this->serviceId(),      // Solicitação de Fontes (cria se faltar)
                    'level'             => 'N1',                    // Nível 1 (formato N1/N2/N3)
                    'priority'          => 'normal',
                    'channel'           => 'interno',
                    'source_system'     => 'source_code',
                ]);

            $comment = $ticket->comments()->create([
                'author_user_id' => $user->id,
                'body'           => 'Códigos-fonte anexados (processando…)',
                'visibility'     => 'internal',
                'is_system'      => false, // comentario normal p/ a UI renderizar os anexos (fonte) — sys-card nao mostra anexos
                'channel'        => 'interno',
            ]);

            $req = SourceCodeRequest::create([
                'ticket_id'    => $ticket->id,
                'client_id'    => $customer->id,
                'requested_by' => $user->id,
                'comment_id'   => $comment->id,
                'status'       => 'processing',
            ]);
            foreach ($v['items'] as $it) {
                $req->items()->create([
                    'owner'      => $it['owner'],
                    'repository' => $it['repository'],
                    'branch'     => $it['branch'],
                    'path'       => $it['path'],
                    'filename'   => basename($it['path']),
                    'status'     => 'pending',
                ]);
            }
            $req->setRelation('ticket', $ticket);
            return $req;
        });

        return response()->json([
            'data'   => $this->serialize($req->fresh('items')),
            'ticket' => ['id' => $req->ticket->id, 'ticket_number' => $req->ticket->ticket_number],
        ], 201);
    }

    /** Anexa UM fonte: pega a versão mais recente (SHA) e grava como anexo da interação. */
    public function attachItem(Request $request, SourceCodeRequestItem $sourceCodeRequestItem, GitHubSourceService $git, SourceRepoResolver $resolver, AttachmentService $attachments): JsonResponse
    {
        $this->authorize($request);
        $item = $sourceCodeRequestItem;
        $req  = $item->request()->with('client')->firstOrFail();
        $customer = $req->client;

        $srcTmp = null;
        $zipPath = null;
        try {
            $repo = $resolver->assertAuthorized($customer, $item->owner, $item->repository, $item->path);
            $resolved = $git->resolveLatest($repo, $item->path);   // {commit, content}
            $commit = $resolved['commit'];
            $filename = basename($item->path);

            // Grava o fonte com a DATA DO COMMIT e compacta em .zip — assim, ao baixar/extrair,
            // o arquivo mantém a data original (download cru pegaria a data de hoje).
            $srcTmp = tempnam(sys_get_temp_dir(), 'scf_');
            file_put_contents($srcTmp, $resolved['content']);
            if (!empty($commit['date'])) {
                @touch($srcTmp, strtotime((string) $commit['date']) ?: time());
            }
            $zipPath = tempnam(sys_get_temp_dir(), 'scfz_');
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Falha ao criar o .zip do fonte.');
            }
            $zip->addFile($srcTmp, $filename);   // a entrada do zip herda a mtime = data do commit
            $zip->close();

            $upload = new UploadedFile($zipPath, $filename . '.zip', null, null, true); // .zip com a data preservada

            $att = $attachments->store($request->user(), [
                'entity_type' => 'HELPDESK_TICKET_COMMENT',
                'entity_id'   => $req->comment_id,
                'category'    => 'source_code',
                'file'        => $upload,
                'visibility'  => 'internal',
                'metadata'    => [
                    'client'         => $customer->name,
                    'owner'          => $item->owner,
                    'repository'     => $item->repository,
                    'branch'         => $item->branch,
                    'path'           => $item->path,
                    'commit_sha'     => $commit['sha'] ?? null,
                    'commit_at'      => $commit['date'] ?? null,
                    'commit_author'  => $commit['author'] ?? null,
                    'commit_message' => $commit['message'] ?? null,
                    'requested_by'   => $request->user()->name,
                    'requested_at'   => now()->toIso8601String(),
                    'timezone'       => 'UTC',
                ],
            ], $request);

            $item->update([
                'attachment_id'       => $att->id,
                'filename'            => $filename,
                'original_commit_sha' => $commit['sha'] ?? null,
                'original_commit_at'  => $commit['date'] ?? null,
                'commit_author'       => $commit['author'] ?? null,
                'commit_message'      => $commit['message'] ?? null,
                'status'              => 'attached',
                'error'               => null,
            ]);
        } catch (\Throwable $e) {
            $item->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        } finally {
            if ($srcTmp && is_file($srcTmp)) { @unlink($srcTmp); }
            if ($zipPath && is_file($zipPath)) { @unlink($zipPath); }
        }

        return response()->json(['data' => $this->serializeItem($item->fresh())]);
    }

    /** Fecha a solicitação: status + escreve o resumo na interação. */
    public function finalize(Request $request, SourceCodeRequest $sourceCodeRequest): JsonResponse
    {
        $this->authorize($request);
        $req = $sourceCodeRequest->load('items', 'requester', 'client');
        $items = $req->items;
        $attached = $items->where('status', 'attached')->count();
        $status = ($items->count() > 0 && $attached === $items->count()) ? 'done' : ($attached > 0 ? 'partial' : 'processing');
        $req->update(['status' => $status]);

        if ($req->comment_id) {
            $lines = $items->where('status', 'attached')->map(function ($i) {
                $when = optional($i->original_commit_at)->format('d/m/Y \à\s H:i') ?? '—';
                $sha = substr((string) $i->original_commit_sha, 0, 7);
                return "📄  {$i->filename}\n       🕒 {$when}    ·    🔖 {$sha}    ·    ⬇️ .zip (data preservada)";
            })->implode("\n\n");
            $failLines = $items->where('status', 'failed')->map(fn ($i) => "⚠️  {$i->filename} — falha ao obter o fonte")->implode("\n");
            $head = "📦  {$attached} código-fonte(s) anexado(s)";
            // Cliente sem contrato → grava o aviso junto ao chamado quando há fonte anexado.
            $disclaimer = ($attached > 0 && $req->client && !$req->client->has_contract) ? self::NO_CONTRACT_DISCLAIMER . "\n\n" : '';
            $body = trim($disclaimer . $head . "\n\n" . $lines . ($failLines !== '' ? "\n\n" . $failLines : '') . "\n\n👤  Solicitado por: " . (optional($req->requester)->name ?? '—'));
            HelpDeskTicketComment::where('id', $req->comment_id)->update(['body' => $body]);
        }

        return response()->json(['data' => $this->serialize($req->fresh('items'))]);
    }

    /** Status "Fechado" (encerrado) da empresa ativa; fallback p/ o default. */
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

    /** Serviço "Solicitação de Fontes" (cria uma vez se não existir). */
    private function serviceId(): ?int
    {
        return HelpDeskService::firstOrCreate(['name' => 'Solicitação de Fontes'], ['active' => true])->id;
    }

    // ── serialização ─────────────────────────────────────────────────────

    private function serialize(SourceCodeRequest $r): array
    {
        return [
            'id'         => $r->id,
            'ticket_id'  => $r->ticket_id,
            'comment_id' => $r->comment_id,
            'status'     => $r->status,
            'items'      => $r->items->map(fn ($i) => $this->serializeItem($i))->values(),
        ];
    }

    private function serializeItem(SourceCodeRequestItem $i): array
    {
        return [
            'id'                  => $i->id,
            'owner'               => $i->owner,
            'repository'          => $i->repository,
            'branch'              => $i->branch,
            'path'                => $i->path,
            'filename'            => $i->filename,
            'status'              => $i->status,
            'error'               => $i->error,
            'original_commit_sha' => $i->original_commit_sha,
            'original_commit_at'  => optional($i->original_commit_at)->toIso8601String(),
            'attachment_id'       => $i->attachment_id,
        ];
    }
}
