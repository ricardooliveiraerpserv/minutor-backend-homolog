<?php

namespace App\SourceCode\Gmud;

use App\Attachments\AttachmentService;
use App\Jobs\GmudExtractAnalyzeJob;
use App\Models\Attachment;
use App\Models\GmudPackage;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\HelpDeskTicketEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * GMUD G1 — recebe o ZIP como PACOTE governado e enfileira a extração/análise. NUNCA commita.
 *
 * "Upload = recebimento/evidência. Publicação (G7) = ação posterior, governada e explicitamente
 * confirmada." O ZIP fica preservado imutável como Attachment (sha256/dedup/auditoria). Dois
 * pontos de entrada: (a) a partir do ZIP já anexado à solução GMUD — substitui o antigo
 * auto-commit; (b) upload dedicado pelo endpoint do wizard.
 */
class GmudPackageService
{
    public function __construct(private AttachmentService $attachments)
    {
    }

    /**
     * Cria pacote(s) a partir do(s) ZIP já anexado(s) à interação de solução GMUD e enfileira a
     * análise. Reutiliza o Attachment existente (imutável) — NÃO re-armazena. Idempotente: não
     * duplica pacote de um attachment já recebido no mesmo chamado. Sem commit.
     *
     * @return GmudPackage[]
     */
    public function receiveFromComment(HelpDeskTicket $ticket, HelpDeskTicketComment $comment): array
    {
        $zips = Attachment::forEntity('HELPDESK_TICKET_COMMENT', $comment->id)->get()
            ->filter(fn (Attachment $a) => $this->isZip($a));

        $packages = [];
        foreach ($zips as $zip) {
            $existing = GmudPackage::where('ticket_id', $ticket->id)
                ->where('attachment_id', $zip->id)->first();
            if ($existing) {
                $packages[] = $existing;
                continue;
            }
            $packages[] = $this->makePackage($ticket, $zip, $comment->author_user_id, 'solucao_gmud');
        }
        return $packages;
    }

    /**
     * Cria um pacote a partir de um upload dedicado (endpoint do wizard). Armazena o ZIP imutável
     * via AttachmentService (categoria gmud_package no próprio chamado) e enfileira a análise.
     */
    public function receiveFromUpload(HelpDeskTicket $ticket, User $actor, UploadedFile $file): GmudPackage
    {
        $att = $this->attachments->store($actor, [
            'entity_type' => 'HELPDESK_TICKET',
            'entity_id'   => $ticket->id,
            'category'    => 'gmud_package',
            'visibility'  => 'internal',
            'file'        => $file,
            'metadata'    => ['gmud' => true, 'ticket_id' => $ticket->id],
        ]);
        return $this->makePackage($ticket, $att, $actor->id, 'upload');
    }

    /**
     * Garante o pacote do ÚLTIMO .zip anexado ao chamado (comentários GMUD/solução ou upload
     * dedicado). Se já existe pacote p/ esse anexo, devolve; senão cria+enfileira. Usado ao abrir o
     * pop-up após gravar/editar a GMUD, para SEMPRE refletir o zip mais recente (não um antigo).
     */
    public function ensureLatestForTicket(HelpDeskTicket $ticket): ?GmudPackage
    {
        $commentIds = $ticket->comments()->pluck('id')->all();

        $zips = Attachment::query()
            ->where(function ($q) use ($ticket, $commentIds) {
                $q->where(function ($qq) use ($commentIds) {
                    $qq->where('entity_type', 'HELPDESK_TICKET_COMMENT')->whereIn('entity_id', $commentIds ?: [-1]);
                })->orWhere(function ($qq) use ($ticket) {
                    $qq->where('entity_type', 'HELPDESK_TICKET')->where('entity_id', $ticket->id);
                });
            })
            ->orderByDesc('id')->get()
            ->filter(fn (Attachment $a) => $this->isZip($a));

        $latest = $zips->first();
        if (! $latest) {
            return null;
        }

        $existing = GmudPackage::where('ticket_id', $ticket->id)->where('attachment_id', $latest->id)->first();
        if ($existing) {
            return $existing;
        }
        return $this->makePackage($ticket, $latest, $latest->uploaded_by, 'ensure');
    }

    /** Cria a linha gmud_packages a partir de um Attachment imutável e enfileira o job. Sem commit. */
    private function makePackage(HelpDeskTicket $ticket, Attachment $att, ?int $userId, string $origin): GmudPackage
    {
        $package = GmudPackage::create([
            'ticket_id'      => $ticket->id,
            'customer_id'    => $ticket->customer_id,
            'attachment_id'  => $att->id,
            'original_name'  => $att->original_name,
            'size_bytes'     => (int) $att->size_bytes,
            'sha256'         => $att->checksum,           // sha256 do ZIP (já calculado pelo AttachmentService)
            'uploaded_by'    => $userId,
            'received_at'    => now(),
            'status'         => GmudPackage::STATUS_RECEIVED,
        ]);

        try {
            HelpDeskTicketEvent::log($ticket->id, 'gmud_package_received', [
                'meta' => [
                    'package_id' => $package->id,
                    'origin'     => $origin,
                    'sha256'     => $att->checksum,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('gmud_package.event_failed', ['package' => $package->id, 'error' => $e->getMessage()]);
        }

        // Extração/análise assíncrona e ISOLADA (fila source-doc, sem inline, sem commit).
        GmudExtractAnalyzeJob::dispatch($package->id)->onConnection('database')->onQueue('source-doc');

        return $package;
    }

    private function isZip(Attachment $a): bool
    {
        $ext = strtolower((string) ($a->extension ?: pathinfo((string) $a->original_name, PATHINFO_EXTENSION)));
        return $ext === 'zip' || str_contains(strtolower((string) $a->mime_type), 'zip');
    }
}
