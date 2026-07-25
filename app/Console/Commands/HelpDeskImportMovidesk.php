<?php

namespace App\Console\Commands;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\CustomerContact;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\HelpDeskTicketEvent;
use App\Models\MovideskOrganization;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * POC de migração: importa os tickets do Movidesk criados nos últimos N meses para as tabelas
 * NATIVAS do Help Desk (helpdesk_tickets + helpdesk_ticket_comments + eventos), pra ver como o
 * sistema lê o legado. Idempotente por (source_system='movidesk', external_ref=id). Token passado
 * em runtime (--token), não é gravado no .env. Anexos NÃO são baixados nesta fase (imagens ficam
 * como referência inline no HTML).
 */
class HelpDeskImportMovidesk extends Command
{
    protected $signature = 'help-desk:import-movidesk {--months=3} {--limit=0} {--token=} {--dry-run} {--fix-imagens} {--backfill-files} {--only-new} {--sync} {--since=} {--company=1}';
    protected $description = 'Importa/sincroniza tickets do Movidesk para o Help Desk nativo. --sync: delta por lastUpdate (novos + abertos que mudaram), com watermark. --backfill-files: re-busca janela recente e rehospeda imagens/anexos inline.';

    public function __construct(private AttachmentService $attachments)
    {
        parent::__construct();
    }

    /** Ator (admin) usado como uploader dos anexos rehospedados — CLI não tem usuário logado. */
    private ?User $uploaderCache = null;
    private bool $uploaderResolved = false;

    /** Watermark do sync incremental (última data de lastUpdate processada). */
    private const WATERMARK_KEY = 'movidesk_hd_sync_watermark';

    /** Marcadores p/ imagem inline e arquivo do Movidesk cuja URL S3 já expirou (recuperáveis via backup). */
    private const IMG_PLACEHOLDER  = '<div style="display:inline-block;padding:6px 10px;margin:4px 0;border:1px dashed #c4b5fd;border-radius:6px;background:#f5f3ff;color:#6d28d9;font-size:12px">🖼️ Imagem anexada no Movidesk — recuperável via backup</div>';
    private const FILE_PLACEHOLDER = '<span style="display:inline-block;padding:3px 9px;margin:2px 0;border:1px dashed #c4b5fd;border-radius:6px;background:#f5f3ff;color:#6d28d9;font-size:12px">📎 Arquivo anexado no Movidesk — recuperável via backup</span>';

    /** Troca <img>/<a> apontando para o S3 do Movidesk (URLs assinadas já expiradas) por marcadores. */
    private function cleanBody(?string $html): string
    {
        $html = (string) $html;
        if ($html === '' || stripos($html, 'movidesk-files') === false) return $html;
        $html = preg_replace('/<img\b[^>]*movidesk-files[^>]*>/is', self::IMG_PLACEHOLDER, $html) ?? $html;
        $html = preg_replace('/<a\b[^>]*movidesk-files[^>]*>.*?<\/a>/is', self::FILE_PLACEHOLDER, $html) ?? $html;
        return $html;
    }

    /**
     * Rehospeda as imagens/anexos inline que ainda apontam pro S3 do Movidesk: baixa o binário
     * (enquanto a URL assinada está viva), grava como Attachment (FASE 11) da entidade dona e
     * reescreve o HTML:
     *   <img ...movidesk-files...>  → <img data-att-id="{id}" alt="..."> (o FE resolve p/ URL assinada)
     *   <a ...movidesk-files...>x</a> → chip com data-att-id (arquivo vira anexo listado)
     * Se a URL já expirou (download falha) ou não há uploader/permissão, cai no marcador (cleanBody).
     * entity_id PRECISA já existir no banco (ticket/comentário salvo) — o AttachmentService resolve a entidade.
     */
    private function rehostBody(?string $html, string $entityType, int $entityId): string
    {
        $html = (string) $html;
        if ($html === '' || stripos($html, 'movidesk-files') === false) return $html;
        $uploader = $this->uploader();
        if (!$uploader) return $this->cleanBody($html); // sem ator não dá pra gravar anexo

        // <img> apontando pro movidesk-files → baixa e vira anexo 'image' inline (data-att-id).
        $html = preg_replace_callback('/<img\b[^>]*>/is', function ($m) use ($entityType, $entityId, $uploader) {
            $tag = $m[0];
            if (stripos($tag, 'movidesk-files') === false) return $tag;
            if (!preg_match('/(?<=[\s"\'])src=["\']([^"\']+)["\']/i', $tag, $s)) return self::IMG_PLACEHOLDER;
            $att = $this->downloadAndStore(html_entity_decode($s[1]), $entityType, $entityId, $uploader, 'image');
            if (!$att) return self::IMG_PLACEHOLDER;
            $alt = preg_match('/\salt=["\']([^"\']*)["\']/i', $tag, $a) ? $a[1] : 'imagem';
            return sprintf('<img data-att-id="%d" alt="%s">', $att->id, htmlspecialchars($alt, ENT_QUOTES));
        }, $html) ?? $html;

        // <a> apontando pro movidesk-files → baixa e vira anexo 'attachment' (chip clicável).
        $html = preg_replace_callback('/<a\b[^>]*>.*?<\/a>/is', function ($m) use ($entityType, $entityId, $uploader) {
            $tag = $m[0];
            if (stripos($tag, 'movidesk-files') === false) return $tag;
            if (!preg_match('/(?<=[\s"\'])href=["\']([^"\']+)["\']/i', $tag, $s)) return self::FILE_PLACEHOLDER;
            $att = $this->downloadAndStore(html_entity_decode($s[1]), $entityType, $entityId, $uploader, 'attachment');
            if (!$att) return self::FILE_PLACEHOLDER;
            return sprintf(
                '<span class="hd-att-chip" data-att-id="%d">📎 %s</span>',
                $att->id, htmlspecialchars($att->original_name, ENT_QUOTES),
            );
        }, $html) ?? $html;

        return $html;
    }

    /** Baixa a URL (S3 Movidesk) e grava como Attachment via AttachmentService. null se falhar/expirado. */
    private function downloadAndStore(string $url, string $entityType, int $entityId, User $uploader, string $category): ?Attachment
    {
        $tmp = null;
        try {
            $resp = Http::timeout(45)->get($url);
            if (!$resp->successful()) return null;               // URL assinada expirada (403) etc.
            $bytes = $resp->body();
            if ($bytes === '' || strlen($bytes) < 8) return null;

            $mime = trim(explode(';', (string) ($resp->header('Content-Type') ?: 'application/octet-stream'))[0]);
            $name = $this->fileNameFromUrl($url, $resp->header('Content-Disposition'), $mime);

            $tmp = tempnam(sys_get_temp_dir(), 'mvatt');
            file_put_contents($tmp, $bytes);
            // test-mode=true: pula is_uploaded_file() (não é upload HTTP real, é download server-side).
            $file = new UploadedFile($tmp, $name, $mime ?: null, null, true);

            $att = $this->attachments->store($uploader, [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'category'    => $category,
                'file'        => $file,
                'visibility'  => 'internal',
                'metadata'    => ['imported_from' => 'movidesk'],
            ]);
            return $att;
        } catch (\Throwable $e) {
            // Extensão/MIME não permitido, tamanho, rede: não interrompe a importação — vira marcador.
            return null;
        } finally {
            if ($tmp && is_file($tmp)) @unlink($tmp);
        }
    }

    /** Deriva um nome de arquivo com extensão coerente (Content-Disposition > URL > MIME). */
    private function fileNameFromUrl(string $url, ?string $disposition, string $mime): string
    {
        if ($disposition && preg_match('/filename\*?=(?:UTF-8\'\')?["\']?([^"\';]+)/i', $disposition, $d)) {
            $n = urldecode(trim($d[1]));
            if ($n !== '' && str_contains($n, '.')) return $this->sanitizeBasename($n);
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = $path !== '' ? basename($path) : '';
        $extByMime = [
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/webp' => 'webp',
            'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ];
        $ext = $extByMime[strtolower($mime)] ?? (pathinfo($base, PATHINFO_EXTENSION) ?: 'bin');
        if ($base === '' || !str_contains($base, '.')) $base = 'movidesk_' . substr(md5($url), 0, 8) . '.' . $ext;
        elseif (strtolower(pathinfo($base, PATHINFO_EXTENSION)) !== strtolower($ext) && isset($extByMime[strtolower($mime)])) {
            $base = pathinfo($base, PATHINFO_FILENAME) . '.' . $ext; // corrige ext pra bater com o MIME real
        }
        return $this->sanitizeBasename($base);
    }

    private function sanitizeBasename(string $n): string
    {
        $n = preg_replace('/[^\w.\-]+/u', '_', $n) ?? $n;
        return mb_substr(ltrim($n, '.'), 0, 180) ?: 'arquivo.bin';
    }

    /** Uploader dos anexos: primeiro admin (internal staff → passa o permission_check do registry). */
    private function uploader(): ?User
    {
        if ($this->uploaderResolved) return $this->uploaderCache;
        $this->uploaderResolved = true;
        return $this->uploaderCache = User::where('type', 'admin')->orderBy('id')->first();
    }

    private const BASE = 'https://api.movidesk.com/public/v1';

    /** Movidesk baseStatus → chave do helpdesk_statuses. */
    private const STATUS_MAP = [
        'New' => 'novo', 'InAttendance' => 'em_andamento', 'Stopped' => 'aguardando_cliente',
        'Resolved' => 'resolvido', 'Closed' => 'fechado', 'Canceled' => 'cancelado',
    ];
    private const URGENCY_MAP = ['baixa' => 'baixa', 'normal' => 'normal', 'alta' => 'alta', 'urgente' => 'urgente'];

    public function handle(): int
    {
        // Modo enriquecimento (sem API): troca as imagens inline mortas por marcador nos já importados.
        if ($this->option('fix-imagens')) {
            return $this->fixImagens();
        }

        $token = $this->option('token') ?: config('services.movidesk.token');
        if (!$token) { $this->error('Sem token Movidesk. Passe --token=... ou configure services.movidesk.token.'); return self::FAILURE; }

        $months = max(1, (int) $this->option('months'));
        $limit  = (int) $this->option('limit');
        $dry    = (bool) $this->option('dry-run');
        $sync   = (bool) $this->option('sync');
        $runStart = now();

        // Empresa dos tickets Movidesk = ERPSERV (company 1, onde vivem os 41k). Sem isto, um processo
        // CLI não tem contexto de empresa e o pluck de status pega "último vence" (empresa 2) → status errado.
        $companyId = max(1, (int) $this->option('company'));

        // Backfill de anexos: re-busca a janela recente da API (URLs S3 frescas) e rehospeda os
        // já importados. Não cria/atualiza ticket — só reescreve descrição/interações com anexos reais.
        if ($this->option('backfill-files')) {
            return $this->backfillFiles($token, $months, $limit);
        }

        // Mapas de resolução (carregados uma vez). Status ESCOPADO pela empresa (chaves repetem entre empresas).
        $statusId = HelpDeskStatus::where('company_id', $companyId)->pluck('id', 'key');   // key → id
        $orgToCustomer = MovideskOrganization::whereNotNull('customer_id')->pluck('customer_id', 'movidesk_id'); // movidesk_id → customer_id
        $userByEmail = User::whereNotNull('email')->get(['id', 'email'])->keyBy(fn ($u) => mb_strtolower($u->email));
        $contactByEmail = CustomerContact::whereNotNull('email')->get(['id', 'customer_id', 'email'])->keyBy(fn ($c) => mb_strtolower($c->email));

        if ($sync) {
            // DELTA: lista por lastUpdate (pega NOVOS e os já existentes que MUDARAM — status/comentário).
            // Watermark do último sync (SystemSetting); --since sobrepõe; default = 2 meses atrás no 1º run.
            $wm = $this->option('since') ?: SystemSetting::get(self::WATERMARK_KEY);
            $sinceUpd = $wm ? Carbon::parse($wm) : now()->subMonths(2);
            $this->info("SYNC delta — tickets com lastUpdate >= {$sinceUpd->toDateTimeString()}" . ($dry ? ' — DRY RUN' : ''));
            $ids = $this->listTicketIdsByUpdate($token, $sinceUpd, $limit);
        } else {
            $since = now()->subMonths($months)->startOfDay();
            $this->info("Listando tickets Movidesk criados desde {$since->toDateString()} (últimos {$months} meses)…");
            $ids = $this->listTicketIds($token, $since, $limit);
        }
        $this->info('Encontrados: ' . count($ids) . ' tickets' . ($limit ? " (limitado a {$limit})" : '') . ($dry ? ' — DRY RUN' : ''));
        if (empty($ids)) {
            if ($sync && !$dry) $this->advanceWatermark($runStart);
            return self::SUCCESS;
        }

        $imp = 0; $upd = 0; $skip = 0; $comments = 0; $errs = 0; $reh = 0;
        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        // Em --sync NUNCA pula existentes: precisamos reprocessar os abertos que mudaram (status/comentário).
        $onlyNew = !$sync && (bool) $this->option('only-new');
        // Pré-carrega os external_ref já importados em UMA query (evita 1 query por id pela rede).
        $jaFeitos = $onlyNew
            ? HelpDeskTicket::where('source_system', 'movidesk')->pluck('external_ref')->flip()
            : collect();
        foreach ($ids as $id) {
            try {
                // --only-new: já importado → pula SEM chamar a API (re-runs rápidos p/ convergir).
                if ($onlyNew && $jaFeitos->has((string) $id)) { $skip++; $bar->advance(); continue; }
                $t = $this->fetchFull($token, (int) $id);
                if (!$t || !empty($t['isDeleted'])) { $skip++; $bar->advance(); continue; }
                if ($dry) { $bar->advance(); continue; }

                // Fila de rehospedagem (imagens/anexos inline) — processada FORA da transação:
                // download do S3 + upload pro storage são chamadas de rede lentas, não seguram lock de DB.
                $rehostQueue = [];
                DB::transaction(function () use ($t, $statusId, $orgToCustomer, $userByEmail, $contactByEmail, $companyId, &$imp, &$upd, &$comments, &$rehostQueue) {
                    $mid = (string) $t['id'];
                    $actions = collect($t['actions'] ?? [])->sortBy('createdDate')->values();

                    // Cliente/contato pelo 1º client (pessoa) do ticket.
                    $client = collect($t['clients'] ?? [])->first(fn ($c) => ($c['personType'] ?? 1) !== 2) ?? ($t['clients'][0] ?? null);
                    $clientEmail = $client['email'] ?? null;
                    $orgId = $client['organization']['id'] ?? (($client['personType'] ?? null) === 2 ? ($client['id'] ?? null) : null);
                    $customerId = $orgId ? ($orgToCustomer[(string) $orgId] ?? null) : null;
                    $contact = $clientEmail ? $contactByEmail->get(mb_strtolower($clientEmail)) : null;
                    if (!$customerId && $contact) $customerId = $contact->customer_id;

                    // Responsável = owner (por e-mail → user).
                    $ownerEmail = $t['owner']['email'] ?? null;
                    $assigneeId = $ownerEmail ? optional($userByEmail->get(mb_strtolower($ownerEmail)))->id : null;

                    $created  = $this->dt($t['createdDate'] ?? null);
                    $baseStat = $t['baseStatus'] ?? 'InAttendance';
                    $sId = $statusId[self::STATUS_MAP[$baseStat] ?? 'em_andamento'] ?? null;
                    $urg = mb_strtolower(trim((string) ($t['urgency'] ?? 'normal')));

                    // Descrição = ação MAIS ANTIGA (abertura); demais viram interações.
                    $descAction = $actions->first();
                    $descRaw = $descAction['htmlDescription'] ?? ($descAction['description'] ?? null);
                    $desc = $this->cleanBody($descRaw); // fallback: placeholder até rehospedar (fora da transação)

                    $num = $t['movideskTicketNumber'] ?? $t['id'];
                    $ticket = HelpDeskTicket::withTrashed()->firstOrNew(['source_system' => 'movidesk', 'external_ref' => $mid]);
                    $isNew = !$ticket->exists;

                    $ticket->fill([
                        'ticket_number' => (string) $num,
                        'subject'       => mb_substr((string) ($t['subject'] ?: 'Sem assunto'), 0, 200),
                        'description'   => $desc,
                        'customer_id'   => $customerId,
                        'customer_contact_id' => $contact?->id,
                        'requester_name'  => $client['businessName'] ?? null,
                        'requester_email' => $clientEmail,
                        'assignee_id'   => $assigneeId,
                        'status_id'     => $sId,
                        'priority'      => self::URGENCY_MAP[$urg] ?? 'normal',
                        'channel'       => 'movidesk',
                        'first_responded_at' => $this->dt($t['slaRealResponseDate'] ?? null),
                        'resolved_at'   => $this->dt($t['resolvedIn'] ?? null),
                        'closed_at'     => $this->dt($t['closedIn'] ?? null),
                        'reopened_at'   => $this->dt($t['reopenedIn'] ?? null),
                        'last_activity_at' => $this->dt($t['lastActionDate'] ?? $t['lastUpdate'] ?? null) ?? $created,
                    ]);
                    $ticket->company_id = $companyId;   // não é fillable → seta direto (senão nasce NULL)
                    $ticket->timestamps = false;
                    if ($isNew) $ticket->created_at = $created;
                    $ticket->updated_at = now();
                    $ticket->save();

                    if ($isNew) {
                        HelpDeskTicketEvent::insert([
                            'ticket_id' => $ticket->id, 'event_type' => 'created',
                            'to_value' => mb_substr((string) $t['subject'], 0, 255),
                            'meta' => json_encode(['imported_from' => 'movidesk', 'movidesk_id' => $mid]),
                            'created_at' => $created,
                        ]);
                        // Rehospeda a descrição só na criação (a abertura não muda; evita re-download no sync).
                        if (stripos((string) $descRaw, 'movidesk-files') !== false) {
                            $rehostQueue[] = ['type' => 'HELPDESK_TICKET', 'id' => $ticket->id, 'html' => $descRaw, 'col' => 'description', 'table' => 'helpdesk_tickets'];
                        }
                        $imp++;
                    } else {
                        $upd++;
                    }

                    // Interações = todas as ações EXCETO a de abertura.
                    foreach ($actions->slice(1) as $a) {
                        // Ação do Movidesk tem id POR-TICKET (1,2,3…) → a chave precisa do id do ticket
                        // senão colide entre chamados (só a 1ª sobreviveria globalmente).
                        $key = 'mv-' . $mid . '-act-' . ($a['id'] ?? uniqid());
                        if (HelpDeskTicketComment::where('idempotency_key', $key)->exists()) continue;
                        $email = $a['createdBy']['email'] ?? null;
                        $authorUser = $email ? optional($userByEmail->get(mb_strtolower($email)))->id : null;
                        $authorContact = (!$authorUser && $email) ? optional($contactByEmail->get(mb_strtolower($email)))->id : null;
                        $isPublic = !isset($a['isPublic']) || $a['isPublic'] !== false;

                        $rawBody = $a['htmlDescription'] ?? ($a['description'] ?? '');
                        $c = new HelpDeskTicketComment([
                            'ticket_id'        => $ticket->id,
                            'author_user_id'   => $authorUser,
                            'author_contact_id' => $authorContact,
                            'body'             => $this->cleanBody($rawBody),
                            'visibility'       => $isPublic ? 'customer' : 'internal',
                            'channel'          => 'movidesk',
                            'is_system'        => false,
                            'idempotency_key'  => $key,
                        ]);
                        $c->timestamps = false;
                        $c->created_at = $this->dt($a['createdDate'] ?? null) ?? $created;
                        $c->updated_at = $c->created_at;
                        $c->save();
                        if (stripos((string) $rawBody, 'movidesk-files') !== false) {
                            $rehostQueue[] = ['type' => 'HELPDESK_TICKET_COMMENT', 'id' => $c->id, 'html' => $rawBody, 'col' => 'body', 'table' => 'helpdesk_ticket_comments'];
                        }
                        $comments++;
                    }
                });

                // Rehospedagem FORA da transação: baixa cada imagem/anexo inline (S3 Movidesk ainda vivo),
                // grava como Attachment e reescreve o corpo (data-att-id). Se a URL expirou → marcador.
                foreach ($rehostQueue as $rq) {
                    $new = $this->rehostBody($rq['html'], $rq['type'], $rq['id']);
                    DB::table($rq['table'])->where('id', $rq['id'])->update([$rq['col'] => $new]);
                    $reh++;
                }
            } catch (\Throwable $e) {
                $errs++;
                // A conexão pode cair no meio de um run longo (pooler Supabase) e derrubar TODAS as
                // queries seguintes em cascata. Reconecta pra que o próximo ticket use conexão nova.
                try { \Illuminate\Support\Facades\DB::reconnect(); } catch (\Throwable) {}
                $this->newLine();
                $this->warn("Ticket {$id}: " . $e->getMessage());
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("Importados: {$imp} · Atualizados: {$upd} · Interações: {$comments} · Rehospedados: {$reh} · Pulados: {$skip} · Erros: {$errs}");
        // Só avança o watermark se o run não quebrou no meio (senão perderíamos tickets da janela).
        if ($sync && !$dry) $this->advanceWatermark($runStart);
        return self::SUCCESS;
    }

    /**
     * BACKFILL de anexos: re-busca os tickets da janela recente (createdDate >= now-months) direto
     * da API — que devolve URLs S3 FRESCAS (as gravadas no banco já podem ter assinatura expirada) —
     * e rehospeda a descrição + interações já importadas, casando as interações por idempotency_key.
     * Idempotente: rehostBody deduplica por checksom e vira no-op quando o corpo já não tem movidesk-files.
     */
    private function backfillFiles(string $token, int $months, int $limit): int
    {
        if (!$this->uploader()) { $this->error('Sem usuário admin p/ uploader dos anexos.'); return self::FAILURE; }
        $since = now()->subMonths($months)->startOfDay();
        $this->info("BACKFILL de anexos — tickets criados desde {$since->toDateString()} (janela {$months} meses). Re-busca da API p/ URLs frescas.");
        $ids = $this->listTicketIds($token, $since, $limit);
        $this->info('Encontrados: ' . count($ids) . ' tickets' . ($limit ? " (limitado a {$limit})" : ''));
        if (empty($ids)) return self::SUCCESS;

        $tk = 0; $ck = 0; $skip = 0; $errs = 0;
        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();
        foreach ($ids as $id) {
            try {
                $mid = (string) $id;
                $ticket = HelpDeskTicket::withTrashed()
                    ->where('source_system', 'movidesk')->where('external_ref', $mid)->first();
                if (!$ticket) { $skip++; $bar->advance(); continue; } // ainda não importado

                $t = $this->fetchFull($token, (int) $id);
                if (!$t) { $skip++; $bar->advance(); continue; }
                $actions = collect($t['actions'] ?? [])->sortBy('createdDate')->values();

                // Descrição = 1ª ação; rehospeda com o HTML fresco (troca placeholder/URL morta por anexo real).
                $descAction = $actions->first();
                $rawDesc = $descAction['htmlDescription'] ?? ($descAction['description'] ?? '');
                if (stripos((string) $rawDesc, 'movidesk-files') !== false) {
                    $new = $this->rehostBody($rawDesc, 'HELPDESK_TICKET', $ticket->id);
                    if ($new !== $ticket->description) {
                        DB::table('helpdesk_tickets')->where('id', $ticket->id)->update(['description' => $new]);
                        $tk++;
                    }
                }

                // Interações: casa por idempotency_key (mv-{mid}-act-{actId}) e rehospeda o corpo.
                foreach ($actions->slice(1) as $a) {
                    $rawBody = $a['htmlDescription'] ?? ($a['description'] ?? '');
                    if (stripos((string) $rawBody, 'movidesk-files') === false) continue;
                    $key = 'mv-' . $mid . '-act-' . ($a['id'] ?? '');
                    $c = HelpDeskTicketComment::where('idempotency_key', $key)->first();
                    if (!$c) continue;
                    $new = $this->rehostBody($rawBody, 'HELPDESK_TICKET_COMMENT', $c->id);
                    if ($new !== $c->body) {
                        DB::table('helpdesk_ticket_comments')->where('id', $c->id)->update(['body' => $new]);
                        $ck++;
                    }
                }
            } catch (\Throwable $e) {
                $errs++;
                try { DB::reconnect(); } catch (\Throwable) {}
                $this->newLine();
                $this->warn("Ticket {$id}: " . $e->getMessage());
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("Backfill — descrições rehospedadas: {$tk} · interações rehospedadas: {$ck} · pulados: {$skip} · erros: {$errs}");
        return self::SUCCESS;
    }

    /** Watermark = início do run − 30min de folga (absorve fuso/precisão; reprocessar é idempotente). */
    private function advanceWatermark(Carbon $runStart): void
    {
        $wm = $runStart->copy()->subMinutes(30)->toDateTimeString();
        SystemSetting::set(self::WATERMARK_KEY, $wm, 'string', 'movidesk', 'Último sync incremental do HD Movidesk (lastUpdate)');
        $this->info("Watermark atualizado → {$wm}");
    }

    /** Lista ids dos tickets com lastUpdate >= $since (delta do sync). Paginado; respeita $limit. */
    private function listTicketIdsByUpdate(string $token, Carbon $since, int $limit): array
    {
        $ids = []; $skip = 0;
        // Mesmo formato do listTicketIds (…T…z) que já funciona com a API do Movidesk.
        $iso = $since->format('Y-m-d\TH:i:s') . '.00z';
        do {
            $resp = Http::timeout(60)->get(self::BASE . '/tickets', [
                'token' => $token, '$select' => 'id,lastUpdate',
                '$filter' => "lastUpdate ge {$iso}",
                '$orderby' => 'lastUpdate', '$top' => 1000, '$skip' => $skip,
            ]);
            if (!$resp->successful()) break;
            $page = $resp->json();
            if (!is_array($page)) break;
            if (isset($page['id'])) $page = [$page]; // 1 resultado vem como objeto
            foreach ($page as $row) { $ids[] = $row['id']; if ($limit && count($ids) >= $limit) return array_slice($ids, 0, $limit); }
            $n = count($page); $skip += 1000;
        } while ($n === 1000);
        return $ids;
    }

    /** Reescreve os corpos já importados trocando imagens inline mortas do Movidesk por marcador. */
    private function fixImagens(): int
    {
        $ids = HelpDeskTicket::where('source_system', 'movidesk')->pluck('id');
        $this->info('Limpando imagens inline mortas em ' . $ids->count() . ' tickets importados…');
        $td = 0; $cd = 0;

        HelpDeskTicket::whereIn('id', $ids)->where('description', 'like', '%movidesk-files%')
            ->select('id', 'description')->chunkById(200, function ($rows) use (&$td) {
                foreach ($rows as $r) {
                    $new = $this->cleanBody($r->description);
                    if ($new !== $r->description) { DB::table('helpdesk_tickets')->where('id', $r->id)->update(['description' => $new]); $td++; }
                }
            });

        HelpDeskTicketComment::whereIn('ticket_id', $ids)->where('body', 'like', '%movidesk-files%')
            ->select('id', 'body')->chunkById(200, function ($rows) use (&$cd) {
                foreach ($rows as $r) {
                    $new = $this->cleanBody($r->body);
                    if ($new !== $r->body) { DB::table('helpdesk_ticket_comments')->where('id', $r->id)->update(['body' => $new]); $cd++; }
                }
            });

        $this->info("Descrições limpas: {$td} · Interações limpas: {$cd}");
        return self::SUCCESS;
    }

    /** Lista ids dos tickets criados desde $since (paginado; respeita $limit). */
    private function listTicketIds(string $token, Carbon $since, int $limit): array
    {
        $ids = []; $skip = 0;
        do {
            $resp = Http::timeout(60)->get(self::BASE . '/tickets', [
                'token' => $token, '$select' => 'id,createdDate',
                '$filter' => "createdDate ge {$since->format('Y-m-d')}T00:00:00.00z",
                '$orderby' => 'createdDate', '$top' => 1000, '$skip' => $skip,
            ]);
            if (!$resp->successful()) break;
            $page = $resp->json();
            if (!is_array($page)) break;
            if (isset($page['id'])) $page = [$page]; // 1 resultado vem como objeto
            foreach ($page as $row) { $ids[] = $row['id']; if ($limit && count($ids) >= $limit) return array_slice($ids, 0, $limit); }
            $n = count($page); $skip += 1000;
        } while ($n === 1000);
        return $ids;
    }

    /** Busca o ticket completo (com actions/clients/owner). */
    private function fetchFull(string $token, int $id): ?array
    {
        $resp = Http::timeout(45)->get(self::BASE . '/tickets', [
            'token' => $token, 'id' => $id,
            '$expand' => 'clients($expand=organization),owner,actions($expand=createdBy($select=id,businessName,email,profileType);$select=id,type,isPublic,htmlDescription,description,createdDate,createdBy,isDeleted)',
        ]);
        return $resp->successful() ? $resp->json() : null;
    }

    private function dt(?string $s): ?Carbon
    {
        if (!$s) return null;
        try { return Carbon::parse($s); } catch (\Throwable) { return null; }
    }
}
