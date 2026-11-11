<?php

namespace App\SourceCode\Gmud;

use App\Attachments\AttachmentService;
use App\Attachments\Storage\StorageProvider;
use App\Models\Attachment;
use App\Models\ClientSourceRepo;
use App\Models\GmudPackage;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * FONTE ENVIADO PELO CLIENTE → Git (decisão Ricardo 2026-11-11).
 *
 * O cliente desenvolve internamente e manda o fonte no chamado — quase sempre por E-MAIL (anexo na
 * abertura ou numa resposta), às vezes pelo portal. Não é GMUD (mudança da ERPSERV): é versionar o
 * que o cliente fez. Reusa o MOTOR DA GMUD inteiro (pacote imutável → extração/matching/qualidade
 * → pop-up de pasta → 1 commit atômico na branch principal → doc-IA), só muda a entrada:
 *  - candidatos = TODO anexo de fonte (.prw/.tlpp/...) ou .zip do chamado, de qualquer canal;
 *  - o consultor escolhe quais → vira 1 pacote (zip montado aqui se vierem soltos), origin='cliente';
 *  - publicar: só COORDENADOR ou ADMIN; qualidade baixa NÃO trava (aviso pós-publicação, como a GMUD).
 */
class ClientSourceService
{
    public const ORIGIN = 'cliente';

    public function __construct(
        private AttachmentService $attachments,
        private StorageProvider $storage,
        private GmudPackageService $packages,
    ) {
    }

    public static function canPublish(?User $user): bool
    {
        return $user && ($user->isAdmin() || $user->isCoordenador());
    }

    /** Extensões aceitas como fonte solto (mesma lista do motor GMUD). */
    public static function isSourceName(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, GmudZipExtractor::SOURCE_EXT, true);
    }

    public static function isZipName(string $name, ?string $mime = null): bool
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip' || str_contains(strtolower((string) $mime), 'zip');
    }

    /**
     * Anexos de fonte/zip do chamado (abertura + interações), mais novo primeiro. Ignora os pacotes
     * que o próprio motor monta (categoria gmud_package) e os fontes trazidos do GitHub (source_code).
     *
     * @return array<int, array<string,mixed>>
     */
    public function candidates(HelpDeskTicket $ticket): array
    {
        $comments = HelpDeskTicketComment::where('ticket_id', $ticket->id)
            ->get(['id', 'author_user_id', 'author_contact_id', 'visibility', 'channel', 'created_at', 'form_kind'])
            ->keyBy('id');

        $atts = Attachment::query()
            ->where(function ($q) use ($ticket, $comments) {
                $q->where(fn ($qq) => $qq->where('entity_type', 'HELPDESK_TICKET')->where('entity_id', $ticket->id))
                  ->orWhere(fn ($qq) => $qq->where('entity_type', 'HELPDESK_TICKET_COMMENT')->whereIn('entity_id', $comments->keys()->all() ?: [-1]));
            })
            ->whereNull('deleted_at')
            ->whereNotIn('category', ['gmud_package', 'source_code'])
            ->orderByDesc('id')->get();

        // Anexos já usados num pacote do cliente PUBLICADO (p/ marcar "já versionado").
        $published = GmudPackage::where('ticket_id', $ticket->id)->where('origin', self::ORIGIN)
            ->where('status', GmudPackage::STATUS_PUBLISHED)->get(['id', 'source_attachment_ids'])
            ->flatMap(fn ($p) => (array) ($p->source_attachment_ids ?? []))->map(fn ($v) => (int) $v)->flip();

        // Zips que já viraram pacote de GMUD da ERPSERV (origin NULL) não são fonte do cliente.
        $gmudAtt = GmudPackage::where('ticket_id', $ticket->id)->whereNull('origin')->pluck('attachment_id')->map(fn ($v) => (int) $v)->flip();

        $out = [];
        foreach ($atts as $a) {
            if ($gmudAtt->has((int) $a->id)) continue;
            $name = (string) $a->original_name;
            $isZip = self::isZipName($name, $a->mime_type);
            if (!$isZip && !self::isSourceName($name)) continue;
            // Formulários da ERPSERV (GMUD, solução, formulários dinâmicos como "Solução com GMUD")
            // não são "fonte do cliente" — seguem o fluxo GMUD.
            $c = $a->entity_type === 'HELPDESK_TICKET_COMMENT' ? $comments->get($a->entity_id) : null;
            if ($c && in_array($c->form_kind, ['gmud', 'solution', 'dynamic'], true)) continue;

            $out[] = [
                'id'          => $a->id,
                'name'        => $name,
                'kind'        => $isZip ? 'zip' : 'fonte',
                'size_bytes'  => (int) $a->size_bytes,
                'received_at' => optional($a->created_at)->toIso8601String(),
                'from'        => $this->senderLabel($ticket, $a, $c),
                'via_email'   => (($a->metadata['source'] ?? null) === 'email:graph'),
                'published'   => $published->has($a->id),
            ];
        }
        return $out;
    }

    /**
     * Monta o pacote do cliente com os anexos escolhidos e enfileira a análise (motor GMUD).
     * 1 zip sozinho → reusa o anexo; senão monta um zip novo com tudo (fontes soltos + conteúdo dos zips).
     */
    public function buildPackage(HelpDeskTicket $ticket, array $attachmentIds, User $actor): GmudPackage
    {
        $valid = collect($this->candidates($ticket))->keyBy('id');
        $ids = array_values(array_unique(array_map('intval', $attachmentIds)));
        $ids = array_values(array_filter($ids, fn ($id) => $valid->has($id)));
        if (empty($ids)) {
            throw new GmudPublishException('Selecione ao menos um fonte anexado ao chamado.');
        }
        if (!$ticket->customer_id) {
            throw new GmudPublishException('O chamado não tem cliente vinculado — vincule o cliente antes de versionar o fonte.');
        }
        $hasRepo = ClientSourceRepo::where('customer_id', $ticket->customer_id)->where('active', true)->exists();
        if (!$hasRepo) {
            throw new GmudPublishException('O cliente deste chamado não tem repositório Git conectado (Cadastro do cliente › Fontes Git).');
        }

        $atts = Attachment::whereIn('id', $ids)->get()->keyBy('id');
        $sender = $valid->get($ids[0])['from'] ?? null;

        if (count($ids) === 1 && self::isZipName((string) $atts[$ids[0]]->original_name, $atts[$ids[0]]->mime_type)) {
            $zipAtt = $atts[$ids[0]];
        } else {
            $zipAtt = $this->bundle($ticket, $atts->only($ids)->values()->all(), $actor);
        }

        $pkg = $this->packages->receiveClientSource($ticket, $zipAtt, $actor->id, [
            'origin'                => self::ORIGIN,
            'source_attachment_ids' => $ids,
            'sender'                => $sender,
        ]);
        return $pkg;
    }

    /** Junta fontes soltos + conteúdo de zips num único .zip (imutável) anexado ao chamado. */
    private function bundle(HelpDeskTicket $ticket, array $atts, User $actor): Attachment
    {
        $tmp = tempnam(sys_get_temp_dir(), 'clisrc');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new GmudPublishException('Não foi possível montar o pacote de fontes.');
        }
        $used = [];
        $add = function (string $name, string $bytes) use ($zip, &$used) {
            $base = basename(str_replace('\\', '/', $name));
            if ($base === '') return;
            $n = $base; $i = 2;
            while (isset($used[mb_strtolower($n)])) { $n = pathinfo($base, PATHINFO_FILENAME) . "_{$i}." . pathinfo($base, PATHINFO_EXTENSION); $i++; }
            $used[mb_strtolower($n)] = true;
            $zip->addFromString($n, $bytes);
        };
        foreach ($atts as $a) {
            $bytes = $this->storage->get((string) $a->storage_path);
            if (self::isZipName((string) $a->original_name, $a->mime_type)) {
                // Conteúdo do zip do cliente entra preservando a estrutura de pastas interna.
                $in = tempnam(sys_get_temp_dir(), 'clizip');
                file_put_contents($in, $bytes);
                $src = new \ZipArchive();
                if ($src->open($in) === true) {
                    for ($i = 0; $i < $src->numFiles; $i++) {
                        $st = $src->statIndex($i);
                        $entry = (string) ($st['name'] ?? '');
                        if ($entry === '' || str_ends_with($entry, '/')) continue;
                        $data = $src->getFromIndex($i);
                        if ($data === false) continue;
                        $safe = ltrim(str_replace(['\\', '../'], ['/', ''], $entry), '/');
                        if (isset($used[mb_strtolower($safe)])) { $add($safe, $data); continue; }
                        $used[mb_strtolower($safe)] = true;
                        $zip->addFromString($safe, $data);
                    }
                    $src->close();
                }
                @unlink($in);
            } else {
                $add((string) $a->original_name, $bytes);
            }
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        $num = $ticket->ticket_number ?: $ticket->id;
        $name = "fonte-cliente-chamado-{$num}.zip";
        $key = sprintf('attachments/helpdesk_ticket/%d/%s_%s', $ticket->id, substr((string) Str::uuid(), 0, 8), $name);
        $this->storage->put($key, $bytes, 'application/zip');
        return $this->attachments->registerExisting($actor, [
            'entity_type'   => 'HELPDESK_TICKET',
            'entity_id'     => $ticket->id,
            'category'      => 'gmud_package',
            'storage_path'  => $key,
            'original_name' => $name,
            'mime_type'     => 'application/zip',
            'visibility'    => 'internal',
            'metadata'      => ['client_source' => true, 'from_attachments' => array_map(fn ($a) => $a->id, $atts)],
        ]);
    }

    /** Quem mandou o anexo: autor da interação (contato/usuário) ou solicitante do chamado. */
    private function senderLabel(HelpDeskTicket $ticket, Attachment $a, ?HelpDeskTicketComment $c): ?string
    {
        if ($c) {
            if ($c->author_contact_id) {
                $ct = \App\Models\CustomerContact::find($c->author_contact_id, ['name', 'email']);
                if ($ct) return trim(($ct->name ?: '') . ($ct->email ? " <{$ct->email}>" : '')) ?: null;
            }
            if ($c->author_user_id) {
                $u = User::find($c->author_user_id, ['name', 'email']);
                if ($u) return trim(($u->name ?: '') . ($u->email ? " <{$u->email}>" : '')) ?: null;
            }
        }
        $n = trim((string) $ticket->requester_name);
        $e = trim((string) $ticket->requester_email);
        return trim($n . ($e ? " <{$e}>" : '')) ?: null;
    }
}
