<?php

namespace App\Http\Controllers;

use App\Models\GmudPackage;
use App\Models\HelpDeskTicket;
use App\SourceCode\Gmud\GmudPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GMUD — Publicação Governada de Fontes (wizard). Endpoints G0-G2: receber ZIP (RECEBIMENTO, não
 * publicação), listar pacotes do chamado e detalhar o resultado da extração/matching. NÃO existe
 * endpoint de publish aqui — a publicação no Git (G7) é uma fase posterior, ainda não implementada.
 * Gate: permission.or.admin:source_docs.gmud_publish (interno ERPSERV).
 */
class GmudPackageController extends Controller
{
    public function __construct(private GmudPackageService $service)
    {
    }

    /** POST /help-desk/tickets/{ticket}/gmud/packages — recebe o ZIP e enfileira a análise. Sem commit. */
    public function store(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:zip', 'max:51200'], // 50 MB
        ]);

        $package = $this->service->receiveFromUpload($ticket, $request->user(), $request->file('file'));

        return response()->json(['data' => $this->manifest($package)], 201);
    }

    /** GET /help-desk/tickets/{ticket}/gmud/packages — pacotes recebidos no chamado. */
    public function index(HelpDeskTicket $ticket): JsonResponse
    {
        $packages = GmudPackage::where('ticket_id', $ticket->id)
            ->withCount('files')
            ->orderByDesc('id')->get()
            ->map(fn (GmudPackage $p) => $this->manifest($p));

        return response()->json(['data' => $packages]);
    }

    /** GET /gmud/packages/{package} — manifesto + arquivos + evidências + links de Acervo. */
    public function show(GmudPackage $package): JsonResponse
    {
        $package->load(['files' => fn ($q) => $q->orderBy('path_in_zip')]);

        return response()->json([
            'data' => array_merge($this->manifest($package), [
                'files' => $package->files->map(fn ($f) => [
                    'id'                    => $f->id,
                    'path_in_zip'           => $f->path_in_zip,   // EVIDÊNCIA — não é destino Git
                    'filename'              => $f->filename,
                    'extension'             => $f->extension,
                    'size_bytes'            => $f->size_bytes,
                    'sha256'                => $f->sha256,
                    'git_blob_sha'          => $f->git_blob_sha,
                    'mtime'                 => optional($f->mtime)->toIso8601String(),
                    'is_source'             => $f->is_source,
                    'match_status'          => $f->match_status,
                    'matched_source_doc_id' => $f->matched_source_doc_id,
                    'matched_git_path'      => $f->matched_git_path,
                    'match_candidates'      => $f->match_candidates,
                    'match_evidence'        => $f->match_evidence,
                ])->values(),
            ]),
        ]);
    }

    /** Manifesto do pacote (sem publicar nada). committed=false é garantia explícita desta entrega. */
    private function manifest(GmudPackage $package): array
    {
        $package->loadMissing('uploader');
        return [
            'id'             => $package->id,
            'ticket_id'      => $package->ticket_id,
            'customer_id'    => $package->customer_id,
            'original_name'  => $package->original_name,
            'size_bytes'     => $package->size_bytes,
            'sha256'         => $package->sha256,
            'status'         => $package->status,
            'error'          => $package->error,
            'uploaded_by'    => $package->uploaded_by,
            'uploaded_by_name' => optional($package->uploader)->name,
            'received_at'    => optional($package->received_at)->toIso8601String(),
            'files_count'    => $package->files_count ?? $package->files()->count(),
            'committed'      => false, // G0-G2 nunca publica no Git.
        ];
    }
}
