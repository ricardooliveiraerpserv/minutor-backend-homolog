<?php

namespace App\Console\Commands;

use App\Models\CustomerContact;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\HelpDeskTicketEvent;
use App\Models\MovideskOrganization;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Console\Command;
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
    protected $signature = 'help-desk:import-movidesk {--months=3} {--limit=0} {--token=} {--dry-run} {--fix-imagens} {--only-new} {--sync} {--since=} {--company=1}';
    protected $description = 'Importa/sincroniza tickets do Movidesk para o Help Desk nativo. --sync: delta por lastUpdate (novos + abertos que mudaram), com watermark.';

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

        $imp = 0; $upd = 0; $skip = 0; $comments = 0; $errs = 0;
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

                DB::transaction(function () use ($t, $statusId, $orgToCustomer, $userByEmail, $contactByEmail, $companyId, &$imp, &$upd, &$comments) {
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
                    $desc = $this->cleanBody($descAction['htmlDescription'] ?? ($descAction['description'] ?? null));

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

                        $c = new HelpDeskTicketComment([
                            'ticket_id'        => $ticket->id,
                            'author_user_id'   => $authorUser,
                            'author_contact_id' => $authorContact,
                            'body'             => $this->cleanBody($a['htmlDescription'] ?? ($a['description'] ?? '')),
                            'visibility'       => $isPublic ? 'customer' : 'internal',
                            'channel'          => 'movidesk',
                            'is_system'        => false,
                            'idempotency_key'  => $key,
                        ]);
                        $c->timestamps = false;
                        $c->created_at = $this->dt($a['createdDate'] ?? null) ?? $created;
                        $c->updated_at = $c->created_at;
                        $c->save();
                        $comments++;
                    }
                });
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
        $this->info("Importados: {$imp} · Atualizados: {$upd} · Interações: {$comments} · Pulados: {$skip} · Erros: {$errs}");
        // Só avança o watermark se o run não quebrou no meio (senão perderíamos tickets da janela).
        if ($sync && !$dry) $this->advanceWatermark($runStart);
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
