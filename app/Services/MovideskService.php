<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MovideskTicket;
use App\Models\Project;
use App\Models\ServiceType;
use App\Models\SystemSetting;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MovideskService
{
    private function token(): ?string
    {
        return config('services.movidesk.token') ?: null;
    }

    private function baseUrl(): string
    {
        return 'https://api.movidesk.com/public/v1';
    }

    // ─────────────────────────────────────────────────────────────
    // API
    // ─────────────────────────────────────────────────────────────

    public function fetchTicket(int $ticketId): ?array
    {
        try {
            $response = Http::get("{$this->baseUrl()}/tickets", [
                'token'   => $this->token(),
                'id'      => $ticketId,
                '$expand' => 'clients,owner,actions($expand=timeAppointments)',
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('📦 [MOVIDESK] Erro ao buscar ticket', [
                'ticket_id' => $ticketId,
                'status'    => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::error('📦 [MOVIDESK] Exceção ao buscar ticket', [
                'ticket_id' => $ticketId,
                'error'     => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Busca tickets com lastUpdate > $since, com paginação automática.
     */
    public function fetchTicketsSince(Carbon $since): array
    {
        $tickets = [];
        $top     = 50;
        $skip    = 0;
        $filter  = "lastUpdate gt " . $since->utc()->format('Y-m-d\TH:i:s\Z');

        do {
            try {
                // Monta URL manualmente: Guzzle codifica '$' → '%24', Movidesk não aceita %24filter
                // $select é obrigatório na listagem do Movidesk (sem ele retorna 400)
                $url = "{$this->baseUrl()}/tickets"
                    . '?token=' . urlencode($this->token())
                    . '&$filter=' . urlencode($filter)
                    . '&$select=id,lastUpdate'
                    . '&$top=' . $top
                    . '&$skip=' . $skip;

                $response = Http::timeout(30)->get($url);

                Log::info('📦 [MOVIDESK SYNC] Resposta da API', [
                    'filter' => $filter,
                    'status' => $response->status(),
                    'skip'   => $skip,
                    'body_preview' => substr($response->body(), 0, 300),
                ]);

                if (!$response->successful()) {
                    Log::warning('📦 [MOVIDESK SYNC] Erro na listagem', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                    break;
                }

                $page = $response->json();

                if (empty($page)) {
                    Log::info('📦 [MOVIDESK SYNC] Nenhum ticket retornado', ['filter' => $filter]);
                    break;
                }

                // API pode retornar objeto único em vez de array
                if (isset($page['id'])) {
                    $page = [$page];
                }

                $tickets = array_merge($tickets, $page);
                $skip   += $top;

                if (count($page) < $top) {
                    break; // última página
                }

            } catch (\Throwable $e) {
                Log::error('📦 [MOVIDESK SYNC] Exceção na listagem', ['error' => $e->getMessage()]);
                break;
            }
        } while (true);

        return $tickets;
    }

    // ─────────────────────────────────────────────────────────────
    // Processamento
    // ─────────────────────────────────────────────────────────────

    /**
     * Processa TODAS as ações do ticket (modo sync/cron).
     * Retorna número de timesheets criados.
     */
    public function processTicket(array $ticketDetails): int
    {
        $this->saveTicketData($ticketDetails);

        $actions = $ticketDetails['actions'] ?? [];
        $created = 0;

        foreach ($actions as $action) {
            foreach ($action['timeAppointments'] ?? [] as $appointment) {
                if ($this->processAppointment($ticketDetails, $action, $appointment)) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * Processa apenas o último timeAppointment da última ação (modo webhook).
     * Retorna 1 se criou, 0 se não.
     */
    public function processLastActionOnly(array $ticketDetails): int
    {
        $this->saveTicketData($ticketDetails);

        $actions = $ticketDetails['actions'] ?? [];

        if (empty($actions)) {
            Log::info('⏭️ [MOVIDESK] Nenhuma ação no ticket');
            return 0;
        }

        $action           = $actions[count($actions) - 1];
        $timeAppointments = $action['timeAppointments'] ?? [];

        if (empty($timeAppointments)) {
            Log::info('⏭️ [MOVIDESK] Última ação sem timeAppointments', [
                'action_id' => $action['id'] ?? null,
            ]);
            return 0;
        }

        return $this->processAppointment($ticketDetails, $action, $timeAppointments[0]) ? 1 : 0;
    }

    private function processAppointment(array $ticket, array $action, array $appointment): bool
    {
        $appointmentId = $appointment['id'] ?? null;

        // Deduplicação por movidesk_appointment_id
        if ($appointmentId && Timesheet::where('movidesk_appointment_id', $appointmentId)->exists()) {
            Log::info('⏭️ [MOVIDESK] Apontamento já importado', [
                'movidesk_appointment_id' => $appointmentId,
            ]);
            return false;
        }

        try {
            $userId = $this->extractUserId($action);
            if (!$userId) return false;

            $customerId = $this->extractCustomerId($ticket);
            if (!$customerId) return false;

            $projectId = $this->extractProjectId($customerId);
            if (!$projectId) return false;

            $date = $this->extractDate($appointment);
            if (!$date) return false;

            $startTime = $this->extractTime($appointment, 'periodStart');
            if (!$startTime) return false;

            $endTime = $this->extractTime($appointment, 'periodEnd');
            if (!$endTime) return false;

            $effortHours = $this->extractEffortHours($appointment);
            if (!$effortHours) return false;

            $effortMinutes = $this->calculateEffortMinutes($effortHours);

            if ($effortMinutes <= 1) {
                Log::info('⏭️ [MOVIDESK] Apontamento ignorado (duração ≤ 1 min)', [
                    'effort_minutes'          => $effortMinutes,
                    'movidesk_appointment_id' => $appointmentId,
                ]);
                return false;
            }

            $this->createTimesheet([
                'user_id'                 => $userId,
                'customer_id'             => $customerId,
                'project_id'              => $projectId,
                'date'                    => $date,
                'start_time'              => $startTime,
                'end_time'                => $endTime,
                'effort_minutes'          => $effortMinutes,
                'effort_hours'            => $effortHours,
                'observation'             => $this->buildObservation($ticket, $action),
                'ticket'                  => $ticket['id'] ?? null,
                'movidesk_appointment_id' => $appointmentId,
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK] Erro ao processar apontamento', [
                'error'                   => $e->getMessage(),
                'movidesk_appointment_id' => $appointmentId,
            ]);
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Extratores
    // ─────────────────────────────────────────────────────────────

    private function extractUserId(array $action): ?int
    {
        $email = $action['createdBy']['email'] ?? null;

        if (!$email) {
            Log::warning('⚠️ [MOVIDESK] Email não encontrado na ação', ['action_id' => $action['id'] ?? null]);
            return null;
        }

        $user = User::where('email', $email)->where('enabled', true)->first();

        if (!$user) {
            Log::warning('⚠️ [MOVIDESK] Usuário não encontrado ou inativo', ['email' => $email]);
            return null;
        }

        return $user->id;
    }

    private function extractCustomerId(array $ticket): ?int
    {
        $clients = $ticket['clients'] ?? [];

        if (empty($clients)) {
            return $this->getDefaultCustomerId();
        }

        $target = null;

        if (count($clients) > 1) {
            foreach ($clients as $client) {
                if (strpos($client['email'] ?? '', '@erpserv.com.br') === false) {
                    $target = $client;
                    break;
                }
            }
        }

        $target = $target ?? $clients[0];

        $orgName = $target['organization']['businessName']
            ?? $target['businessName']
            ?? null;

        if (!$orgName) {
            Log::warning('⚠️ [MOVIDESK] businessName não encontrado', ['client' => $target]);
            return $this->getDefaultCustomerId();
        }

        $customer = Customer::where(function ($q) use ($orgName) {
            $q->where('name', $orgName)->orWhere('company_name', $orgName);
        })->where('active', true)->first();

        if (!$customer) {
            Log::warning('⚠️ [MOVIDESK] Cliente não encontrado no sistema', ['organization' => $orgName]);
            return $this->getDefaultCustomerId();
        }

        return $customer->id;
    }

    private function getDefaultCustomerId(): ?int
    {
        $id = SystemSetting::get('movidesk_default_customer_id');

        if (!$id) {
            Log::error('🚨 [MOVIDESK] movidesk_default_customer_id não configurado');
            return null;
        }

        return (int) $id;
    }

    private function extractProjectId(?int $customerId): ?int
    {
        if ($customerId) {
            $serviceType = ServiceType::where('code', 'sustentacao')
                ->orWhere('name', 'Sustentação')
                ->first();

            if ($serviceType) {
                $project = Project::active()
                    ->where('customer_id', $customerId)
                    ->where('service_type_id', $serviceType->id)
                    ->first();

                if ($project) {
                    return $project->id;
                }
            }
        }

        $defaultId = SystemSetting::get('movidesk_default_project_id');

        if (!$defaultId) {
            Log::error('🚨 [MOVIDESK] movidesk_default_project_id não configurado');
            return null;
        }

        $project = Project::find($defaultId);

        if (!$project || !$project->isActive()) {
            Log::error('🚨 [MOVIDESK] Projeto padrão inativo ou inexistente', ['id' => $defaultId]);
            return null;
        }

        return $project->id;
    }

    private function extractDate(array $appointment): ?string
    {
        $val = $appointment['date'] ?? null;
        if (!$val) {
            Log::warning('⚠️ [MOVIDESK] date ausente no timeAppointment');
            return null;
        }
        try {
            return Carbon::parse($val)->format('Y-m-d');
        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK] Erro ao parsear date', ['value' => $val]);
            return null;
        }
    }

    private function extractTime(array $appointment, string $field): ?string
    {
        $val = $appointment[$field] ?? null;
        if (!$val) {
            Log::warning("⚠️ [MOVIDESK] {$field} ausente no timeAppointment");
            return null;
        }
        try {
            return Carbon::parse($val)->format('H:i');
        } catch (\Throwable $e) {
            Log::error("🚨 [MOVIDESK] Erro ao parsear {$field}", ['value' => $val]);
            return null;
        }
    }

    private function extractEffortHours(array $appointment): ?string
    {
        $workTime = $appointment['workTime'] ?? null;
        if (!$workTime) {
            Log::warning('⚠️ [MOVIDESK] workTime ausente no timeAppointment');
            return null;
        }

        [$hours, $minutes] = array_pad(explode(':', $workTime), 2, '00');
        return ((int) $hours) . ':' . $minutes;
    }

    private function calculateEffortMinutes(string $effortHours): int
    {
        [$hours, $minutes] = array_pad(explode(':', $effortHours), 2, '0');
        return ((int) $hours * 60) + (int) $minutes;
    }

    private function buildObservation(array $ticket, array $action): string
    {
        $subject = $ticket['subject'] ?? '';
        $html    = $action['htmlDescription'] ?? '';
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = preg_replace('/\s+/', ' ', $plain);

        if ($subject) {
            $escaped = preg_quote(trim($subject), '/');
            $plain   = preg_replace('/^' . $escaped . '\s*/iu', '', $plain);
            $plain   = trim($plain);
        }

        return $plain;
    }

    // ─────────────────────────────────────────────────────────────
    // Persistência
    // ─────────────────────────────────────────────────────────────

    private function createTimesheet(array $data): void
    {
        DB::beginTransaction();

        try {
            $conflict = Timesheet::where('user_id', $data['user_id'])
                ->where('project_id', $data['project_id'])
                ->where('date', $data['date'])
                ->where('status', '!=', Timesheet::STATUS_REJECTED)
                ->where(function ($q) use ($data) {
                    $q->where('start_time', '<', $data['end_time'])
                      ->where('end_time', '>', $data['start_time']);
                })
                ->first();

            $timesheet                          = new Timesheet();
            $timesheet->user_id                 = $data['user_id'];
            $timesheet->customer_id             = $data['customer_id'];
            $timesheet->project_id              = $data['project_id'];
            $timesheet->date                    = $data['date'];
            $timesheet->start_time              = $data['start_time'];
            $timesheet->end_time                = $data['end_time'];
            $timesheet->effort_minutes          = $data['effort_minutes'];
            $timesheet->observation             = $data['observation'];
            $timesheet->ticket                  = $data['ticket'];
            $timesheet->movidesk_appointment_id = $data['movidesk_appointment_id'] ?? null;
            $timesheet->origin                  = 'webhook';
            $timesheet->status                  = $conflict
                ? Timesheet::STATUS_CONFLICTED
                : Timesheet::STATUS_PENDING;
            $timesheet->save();

            DB::commit();

            Log::info($conflict
                ? '⚠️ [MOVIDESK] Timesheet criado com status CONFLICTED'
                : '✅ [MOVIDESK] Timesheet criado', [
                'timesheet_id'            => $timesheet->id,
                'movidesk_appointment_id' => $data['movidesk_appointment_id'],
                'ticket'                  => $data['ticket'],
                'date'                    => $data['date'],
                'effort_hours'            => $data['effort_hours'],
                'status'                  => $timesheet->status,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('🚨 [MOVIDESK] Erro ao criar timesheet', [
                'error' => $e->getMessage(),
                'data'  => $data,
            ]);
        }
    }

    private function saveTicketData(array $ticket): void
    {
        try {
            $id = $ticket['id'] ?? null;
            if (!$id) return;

            MovideskTicket::updateOrCreate(['ticket_id' => $id], [
                'solicitante' => $this->extractSolicitante($ticket),
                'categoria'   => $ticket['category'] ?? null,
                'urgencia'    => $ticket['urgency'] ?? null,
                'responsavel' => $this->extractResponsavel($ticket),
                'nivel'       => $this->extractNivel($ticket),
                'servico'     => $ticket['serviceSecondLevel'] ?? $ticket['serviceFirstLevel'] ?? null,
                'titulo'      => $ticket['subject'] ?? null,
                'status'      => $ticket['status'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK] Erro ao salvar dados do ticket', [
                'error' => $e->getMessage(),
                'id'    => $ticket['id'] ?? null,
            ]);
        }
    }

    private function extractSolicitante(array $ticket): ?array
    {
        $clients = $ticket['clients'] ?? [];
        if (empty($clients)) return null;

        // Preferir pessoa (tem organization preenchida) sobre empresa
        $target = null;
        foreach ($clients as $c) {
            if (!empty($c['organization'])) {
                $target = $c;
                break;
            }
        }
        $target = $target ?? $clients[0];

        return [
            'name'         => $target['businessName'] ?? null,
            'email'        => $target['email'] ?? null,
            'organization' => $target['organization']['businessName'] ?? null,
        ];
    }

    private function extractResponsavel(array $ticket): ?array
    {
        $owner = $ticket['owner'] ?? null;
        if (!$owner) return null;
        return ['name' => $owner['businessName'] ?? null, 'email' => $owner['email'] ?? null];
    }

    private function extractNivel(array $ticket): ?string
    {
        foreach ($ticket['customFieldValues'] ?? [] as $field) {
            if (($field['customFieldId'] ?? null) == 13485) {
                return $field['items'][0]['customFieldItem'] ?? null;
            }
        }
        return null;
    }
}
