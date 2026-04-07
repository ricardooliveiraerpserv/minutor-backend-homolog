<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\SystemSetting;
use App\Models\ServiceType;
use App\Models\MovideskTicket;
use Carbon\Carbon;

class MovideskWebhookController extends Controller
{
    private function getMovideskToken(): ?string
    {
        return config('services.movidesk.token') ?: null;
    }

    private function getMovideskBaseUrl(): string
    {
        return 'https://api.movidesk.com/public/v1';
    }

    private function getTicketDetails(int $ticketId): ?array
    {
        try {
            $response = Http::get("{$this->getMovideskBaseUrl()}/tickets", [
                'token' => $this->getMovideskToken(),
                'id' => $ticketId,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('📦 [MOVIDESK API] Erro ao buscar ticket', [
                'ticket_id' => $ticketId,
                'status' => $response->status(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('📦 [MOVIDESK API] Exceção ao buscar ticket', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function handleTicket(Request $request): JsonResponse
    {
        Log::warning('🎫 [MOVIDESK WEBHOOK] ===== REQUISIÇÃO RECEBIDA =====', [
            'ip' => $request->ip(),
        ]);

        try {
            $payload = $request->all();

            if (empty($payload)) {
                Log::warning('🎫 [MOVIDESK WEBHOOK] Payload vazio');
                return response()->json(['status' => 'error', 'message' => 'Payload vazio'], 400);
            }

            $ticketId = $payload['Id'] ?? null;
            $subject = $payload['Subject'] ?? 'N/A';
            $status = $payload['Status'] ?? 'N/A';
            $urgency = $payload['Urgency'] ?? 'N/A';
            $actions = $payload['Actions'] ?? [];

            // Log básico do ticket
            Log::warning('🎫 [MOVIDESK WEBHOOK] Ticket recebido', [
                'ticket_id' => $ticketId,
                'subject' => $subject,
                'status' => $status,
                'urgency' => $urgency,
                'action_count' => count($actions),
            ]);

            // Buscar detalhes completos do ticket via API
            if ($ticketId && $this->getMovideskToken()) {
                $ticketDetails = $this->getTicketDetails($ticketId);

                if ($ticketDetails) {
                    // 🔍 LOG TEMPORÁRIO DE DEBUG - Todos os campos do ticket da API
                    Log::warning('🔍 [MOVIDESK WEBHOOK DEBUG] Ticket completo da API', [
                        'ticket_id' => $ticketId,
                        'full_ticket_details' => $ticketDetails,
                    ]);

                    // Salvar dados do ticket
                    $this->saveTicketData($ticketDetails);

                    // Processar e criar apontamentos de horas a partir das ações do ticket
                    $this->processTicketActions($ticketDetails);
                }
            }

            Log::warning('🎫 [MOVIDESK WEBHOOK] ===== PROCESSADO COM SUCESSO =====');

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processado',
                'timestamp' => now()->toIso8601String(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK WEBHOOK] Erro', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao processar',
            ], 200);
        }
    }

    /**
     * Processar ações do ticket e criar apontamentos de horas
     */
    private function processTicketActions(array $ticketDetails): void
    {
        $actions = $ticketDetails['actions'] ?? [];

        if (empty($actions)) {
            Log::info('⏭️ [MOVIDESK WEBHOOK] Nenhuma ação encontrada no ticket');
            return;
        }

        // Processar apenas a última ação (mais recente)
        $action = $actions[count($actions) - 1];
        $timeAppointments = $action['timeAppointments'] ?? [];

        if (empty($timeAppointments)) {
            Log::info('⏭️ [MOVIDESK WEBHOOK] Nenhum apontamento de tempo na ação', [
                'action_id' => $action['id'] ?? null,
            ]);
            return;
        }

        // Processar apenas o primeiro timeAppointment da última ação (timeAppointments[0])
        $timeAppointment = $timeAppointments[0];

        Log::info('⚙️ [MOVIDESK WEBHOOK] Processando apontamento de tempo da última ação', [
            'action_id' => $action['id'] ?? null,
            'action_index' => count($actions) - 1,
            'total_actions' => count($actions),
            'time_appointment_id' => $timeAppointment['id'] ?? null,
        ]);

        try {
            // 1. Buscar user_id pelo email
            $userId = $this->extractUserId($action);
            if (!$userId) {
                return; // Log já foi registrado na função
            }

            // 2. Buscar customer_id
            $customerId = $this->extractCustomerId($ticketDetails);
            if (!$customerId) {
                return; // Log já foi registrado na função
            }

            // 3. Buscar project_id
            $projectId = $this->extractProjectId($customerId);
            if (!$projectId) {
                return; // Log já foi registrado na função
            }

            // 4. Extrair date
            $date = $this->extractDate($timeAppointment);
            if (!$date) {
                return; // Log já foi registrado na função
            }

            // 5. Extrair start_time
            $startTime = $this->extractTime($timeAppointment, 'periodStart');
            if (!$startTime) {
                return; // Log já foi registrado na função
            }

            // 6. Extrair end_time
            $endTime = $this->extractTime($timeAppointment, 'periodEnd');
            if (!$endTime) {
                return; // Log já foi registrado na função
            }

            // 7. Extrair effort_hours
            $effortHours = $this->extractEffortHours($timeAppointment);
            if (!$effortHours) {
                return; // Log já foi registrado na função
            }

            // 8. Calcular effort_minutes
            $effortMinutes = $this->calculateEffortMinutes($effortHours);

            // Validar duração mínima de 1 minuto
            if ($effortMinutes <= 1) {
                Log::info('⏭️ [MOVIDESK WEBHOOK] Apontamento ignorado por duração menor ou igual a 1 minuto', [
                    'effort_hours' => $effortHours,
                    'effort_minutes' => $effortMinutes,
                    'action_id' => $action['id'] ?? null,
                ]);
                return;
            }

            // 9. Construir observation
            $observation = $this->buildObservation($ticketDetails, $action);

            // 10. Extrair ticket ID
            $ticketId = $ticketDetails['id'] ?? null;

            // Criar o apontamento
            $this->createTimesheet([
                'user_id' => $userId,
                'customer_id' => $customerId,
                'project_id' => $projectId,
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'effort_minutes' => $effortMinutes,
                'effort_hours' => $effortHours,
                'observation' => $observation,
                'ticket' => $ticketId,
            ]);

        } catch (\Exception $e) {
            Log::error('🚨 [MOVIDESK WEBHOOK] Erro ao processar apontamento', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'action_id' => $action['id'] ?? null,
            ]);
        }
    }

    /**
     * Extrair user_id pelo email da ação
     */
    private function extractUserId(array $action): ?int
    {
        $email = $action['createdBy']['email'] ?? null;

        if (!$email) {
            Log::warning('⚠️ [MOVIDESK WEBHOOK] Email do criador não encontrado na ação', [
                'action_id' => $action['id'] ?? null,
            ]);
            return null;
        }

        $user = User::where('email', $email)->where('enabled', true)->first();

        if (!$user) {
            Log::warning('⚠️ [MOVIDESK WEBHOOK] Usuário não encontrado ou inativo', [
                'email' => $email,
                'action_id' => $action['id'] ?? null,
            ]);
            return null;
        }

        Log::info('✅ [MOVIDESK WEBHOOK] Usuário encontrado', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'email' => $email,
        ]);

        return $user->id;
    }

    /**
     * Extrair customer_id dos clientes do ticket
     */
    private function extractCustomerId(array $ticketDetails): ?int
    {
        $clients = $ticketDetails['clients'] ?? [];

        if (empty($clients)) {
            Log::warning('⚠️ [MOVIDESK WEBHOOK] Nenhum cliente encontrado no ticket');
            return $this->getDefaultCustomerId();
        }

        // Se houver mais de um cliente e um deles contiver @erpserv.com.br, usar o outro
        $targetClient = null;

        if (count($clients) > 1) {
            foreach ($clients as $client) {
                $clientEmail = $client['email'] ?? '';
                if (strpos($clientEmail, '@erpserv.com.br') === false) {
                    $targetClient = $client;
                    break;
                }
            }
        }

        // Se não encontrou cliente específico, usar o primeiro
        if (!$targetClient) {
            $targetClient = $clients[0];
        }

        // Buscar cliente pelo businessName da organização; fallback para businessName direto no cliente
        $organizationName = $targetClient['organization']['businessName'] ?? null;

        if (!$organizationName) {
            $organizationName = $targetClient['businessName'] ?? null;

            if ($organizationName) {
                Log::info('ℹ️ [MOVIDESK WEBHOOK] Organization nula, usando businessName direto do cliente', [
                    'businessName' => $organizationName,
                ]);
            }
        }

        if (!$organizationName) {
            Log::warning('⚠️ [MOVIDESK WEBHOOK] businessName não encontrado (organization e cliente direto)', [
                'client' => $targetClient,
            ]);
            return $this->getDefaultCustomerId();
        }

        // Buscar cliente no sistema
        $customer = Customer::where('name', $organizationName)
            ->orWhere('company_name', $organizationName)
            ->where('active', true)
            ->first();

        if (!$customer) {
            Log::warning('⚠️ [MOVIDESK WEBHOOK] Cliente não encontrado no sistema', [
                'organization_name' => $organizationName,
            ]);
            return $this->getDefaultCustomerId();
        }

        Log::info('✅ [MOVIDESK WEBHOOK] Cliente encontrado', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'organization_name' => $organizationName,
        ]);

        return $customer->id;
    }

    /**
     * Obter customer_id padrão do SystemSetting
     */
    private function getDefaultCustomerId(): ?int
    {
        $defaultCustomerId = SystemSetting::get('movidesk_default_customer_id');

        if (!$defaultCustomerId) {
            Log::error('🚨 [MOVIDESK WEBHOOK] Cliente padrão não configurado em SystemSettings');
            return null;
        }

        Log::info('ℹ️ [MOVIDESK WEBHOOK] Usando cliente padrão', [
            'default_customer_id' => $defaultCustomerId,
        ]);

        return (int) $defaultCustomerId;
    }

    /**
     * Extrair project_id:
     * 1. Tenta buscar um projeto do cliente com tipo de serviço "Sustentação"
     * 2. Se não encontrar, usa o projeto padrão configurado em SystemSettings
     */
    private function extractProjectId(?int $customerId = null): ?int
    {
        // 1. Tentar projeto de Sustentação do próprio cliente
        if ($customerId) {
            // Buscar o tipo de serviço "Sustentação"
            $maintenanceServiceType = ServiceType::where('code', 'sustentacao')
                ->orWhere('name', 'Sustentação')
                ->first();

            if ($maintenanceServiceType) {
                $customerMaintenanceProject = Project::active()
                    ->where('customer_id', $customerId)
                    ->where('service_type_id', $maintenanceServiceType->id)
                    ->first();

                if ($customerMaintenanceProject) {
                    Log::info('✅ [MOVIDESK WEBHOOK] Projeto de Sustentação do cliente encontrado', [
                        'project_id' => $customerMaintenanceProject->id,
                        'project_name' => $customerMaintenanceProject->name,
                        'customer_id' => $customerId,
                        'service_type_id' => $maintenanceServiceType->id,
                    ]);

                    return $customerMaintenanceProject->id;
                }

                Log::info('ℹ️ [MOVIDESK WEBHOOK] Nenhum projeto de Sustentação encontrado para o cliente, usando projeto padrão', [
                    'customer_id' => $customerId,
                    'service_type_id' => $maintenanceServiceType->id,
                ]);
            } else {
                Log::warning('⚠️ [MOVIDESK WEBHOOK] Tipo de serviço "Sustentação" não encontrado no sistema, usando projeto padrão');
            }
        }

        // 2. Fallback: usar projeto padrão do sistema
        $defaultProjectId = SystemSetting::get('movidesk_default_project_id');

        if (!$defaultProjectId) {
            Log::error('🚨 [MOVIDESK WEBHOOK] Projeto padrão não configurado em SystemSettings');
            return null;
        }

        // Verificar se o projeto existe e está ativo
        $project = Project::find($defaultProjectId);

        if (!$project || !$project->isActive()) {
            Log::error('🚨 [MOVIDESK WEBHOOK] Projeto padrão não encontrado ou inativo', [
                'project_id' => $defaultProjectId,
            ]);
            return null;
        }

        Log::info('✅ [MOVIDESK WEBHOOK] Projeto padrão encontrado', [
            'project_id' => $project->id,
            'project_name' => $project->name,
        ]);

        return $project->id;
    }

    /**
     * Extrair data do timeAppointment
     */
    private function extractDate(array $timeAppointment): ?string
    {
        $dateString = $timeAppointment['date'] ?? null;

        if (!$dateString) {
            Log::warning('⚠️ [MOVIDESK WEBHOOK] Data não encontrada no timeAppointment');
            return null;
        }

        try {
            // Formato vindo do Movidesk: "2025-12-03T00:00:00"
            $date = Carbon::parse($dateString)->format('Y-m-d');

            Log::info('✅ [MOVIDESK WEBHOOK] Data extraída', [
                'original' => $dateString,
                'formatted' => $date,
            ]);

            return $date;
        } catch (\Exception $e) {
            Log::error('🚨 [MOVIDESK WEBHOOK] Erro ao parsear data', [
                'date_string' => $dateString,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrair horário (start_time ou end_time) do timeAppointment
     */
    private function extractTime(array $timeAppointment, string $field): ?string
    {
        $timeString = $timeAppointment[$field] ?? null;

        if (!$timeString) {
            Log::warning("⚠️ [MOVIDESK WEBHOOK] {$field} não encontrado no timeAppointment");
            return null;
        }

        try {
            // Formato vindo do Movidesk: "20:00:00"
            // Converter para "HH:MM" (sem segundos)
            $time = Carbon::parse($timeString)->format('H:i');

            Log::info("✅ [MOVIDESK WEBHOOK] {$field} extraído", [
                'original' => $timeString,
                'formatted' => $time,
            ]);

            return $time;
        } catch (\Exception $e) {
            Log::error("🚨 [MOVIDESK WEBHOOK] Erro ao parsear {$field}", [
                'time_string' => $timeString,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrair effort_hours do timeAppointment
     */
    private function extractEffortHours(array $timeAppointment): ?string
    {
        $workTime = $timeAppointment['workTime'] ?? null;

        if (!$workTime) {
            Log::warning('⚠️ [MOVIDESK WEBHOOK] workTime não encontrado no timeAppointment');
            return null;
        }

        try {
            // Formato vindo do Movidesk: "02:00:00"
            // Converter para "H:MM" (sem segundos)
            $parts = explode(':', $workTime);
            $hours = (int) $parts[0];
            $minutes = isset($parts[1]) ? $parts[1] : '00';

            $effortHours = "{$hours}:{$minutes}";

            Log::info('✅ [MOVIDESK WEBHOOK] effort_hours extraído', [
                'original' => $workTime,
                'formatted' => $effortHours,
            ]);

            return $effortHours;
        } catch (\Exception $e) {
            Log::error('🚨 [MOVIDESK WEBHOOK] Erro ao parsear workTime', [
                'work_time' => $workTime,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Calcular effort_minutes a partir de effort_hours
     */
    private function calculateEffortMinutes(string $effortHours): int
    {
        try {
            $parts = explode(':', $effortHours);
            $hours = (int) $parts[0];
            $minutes = isset($parts[1]) ? (int) $parts[1] : 0;

            $totalMinutes = ($hours * 60) + $minutes;

            Log::info('✅ [MOVIDESK WEBHOOK] effort_minutes calculado', [
                'effort_hours' => $effortHours,
                'effort_minutes' => $totalMinutes,
            ]);

            return $totalMinutes;
        } catch (\Exception $e) {
            Log::error('🚨 [MOVIDESK WEBHOOK] Erro ao calcular effort_minutes', [
                'effort_hours' => $effortHours,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Construir observation a partir do subject e htmlDescription
     */
    private function buildObservation(array $ticketDetails, array $action): string
    {
        $subject = $ticketDetails['subject'] ?? '';
        $htmlDescription = $action['htmlDescription'] ?? '';

        // Construir observation com subject em h2 e descrição
        $observation = "<h4>{$subject}</h4><br/>{$htmlDescription}";

        Log::info('✅ [MOVIDESK WEBHOOK] Observation construída', [
            'subject' => $subject,
            'observation_length' => strlen($observation),
        ]);

        return $observation;
    }

    /**
     * Criar apontamento de horas no sistema
     */
    private function createTimesheet(array $data): void
    {
        DB::beginTransaction();

        try {
            // Verificar se já existe um apontamento que se sobrepõe no mesmo dia/projeto/usuário
            // Regra de sobreposição: novo.start < existente.end && novo.end > existente.start
            $existingTimesheet = Timesheet::where('user_id', $data['user_id'])
                ->where('project_id', $data['project_id'])
                ->where('date', $data['date'])
                ->where('status', '!=', Timesheet::STATUS_REJECTED) // Ignorar apontamentos rejeitados
                ->where(function ($query) use ($data) {
                    $query->where('start_time', '<', $data['end_time'])
                          ->where('end_time', '>', $data['start_time']);
                })
                ->first();

            if ($existingTimesheet) {
                Log::warning('⚠️ [MOVIDESK WEBHOOK] Apontamento conflitante detectado por sobreposição de horário - criando com status conflicted', [
                    'existing_timesheet_id' => $existingTimesheet->id,
                    'ticket' => $data['ticket'],
                    'user_id' => $data['user_id'],
                    'project_id' => $data['project_id'],
                    'date' => $data['date'],
                    'new_start_time' => $data['start_time'],
                    'new_end_time' => $data['end_time'],
                    'existing_start_time' => $existingTimesheet->start_time,
                    'existing_end_time' => $existingTimesheet->end_time,
                ]);

                // Continuar criando o apontamento, mas com status de conflito
                // O usuário poderá ver e corrigir o erro no Movidesk / Minutor
            }

            // Criar novo apontamento
            $timesheet = new Timesheet();
            $timesheet->user_id = $data['user_id'];
            $timesheet->customer_id = $data['customer_id'];
            $timesheet->project_id = $data['project_id'];
            $timesheet->date = $data['date'];
            $timesheet->start_time = $data['start_time'];
            $timesheet->end_time = $data['end_time'];
            $timesheet->effort_minutes = $data['effort_minutes'];
            $timesheet->observation = $data['observation'];
            $timesheet->ticket = $data['ticket'];
            $timesheet->origin = 'webhook';
            // Se existe um apontamento conflitante, marcar como conflicted
            $timesheet->status = $existingTimesheet ? Timesheet::STATUS_CONFLICTED : Timesheet::STATUS_PENDING;
            $timesheet->save();

            DB::commit();

            $logLevel = $existingTimesheet ? 'warning' : 'info';
            $logMessage = $existingTimesheet
                ? '⚠️ [MOVIDESK WEBHOOK] Apontamento criado com status CONFLICTED'
                : '✅ [MOVIDESK WEBHOOK] Apontamento criado com sucesso';

            Log::$logLevel($logMessage, [
                'timesheet_id' => $timesheet->id,
                'user_id' => $data['user_id'],
                'customer_id' => $data['customer_id'],
                'project_id' => $data['project_id'],
                'ticket' => $data['ticket'],
                'origin' => 'webhook',
                'date' => $data['date'],
                'effort_hours' => $data['effort_hours'],
                'status' => $timesheet->status,
                'existing_timesheet_id' => $existingTimesheet->id ?? null,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('🚨 [MOVIDESK WEBHOOK] Erro ao criar apontamento', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'data' => $data,
            ]);
        }
    }

    /**
     * Salvar dados do ticket no banco de dados
     */
    private function saveTicketData(array $ticketDetails): void
    {
        try {
            $ticketId = $ticketDetails['id'] ?? null;

            if (!$ticketId) {
                Log::warning('⚠️ [MOVIDESK WEBHOOK] Ticket ID não encontrado, não será salvo');
                return;
            }

            // 1. Extrair solicitante
            $solicitante = $this->extractSolicitante($ticketDetails);

            // 2. Extrair categoria
            $categoria = $ticketDetails['category'] ?? null;

            // 3. Extrair urgência
            $urgencia = $ticketDetails['urgency'] ?? null;

            // 4. Extrair responsável
            $responsavel = $this->extractResponsavel($ticketDetails);

            // 5. Extrair nível
            $nivel = $this->extractNivel($ticketDetails);

            // 6. Extrair serviço
            $servico = $this->extractServico($ticketDetails);

            // 7. Extrair título
            $titulo = $ticketDetails['subject'] ?? null;

            // 8. Extrair status
            $status = $ticketDetails['status'] ?? null;

            // Criar ou atualizar registro
            MovideskTicket::updateOrCreate(
                ['ticket_id' => $ticketId],
                [
                    'solicitante' => $solicitante,
                    'categoria' => $categoria,
                    'urgencia' => $urgencia,
                    'responsavel' => $responsavel,
                    'nivel' => $nivel,
                    'servico' => $servico,
                    'titulo' => $titulo,
                    'status' => $status,
                ]
            );

            Log::info('✅ [MOVIDESK WEBHOOK] Dados do ticket salvos com sucesso', [
                'ticket_id' => $ticketId,
                'titulo' => $titulo,
                'categoria' => $categoria,
                'status' => $status,
            ]);

        } catch (\Exception $e) {
            Log::error('🚨 [MOVIDESK WEBHOOK] Erro ao salvar dados do ticket', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ticket_id' => $ticketDetails['id'] ?? null,
            ]);
        }
    }

    /**
     * Extrair dados do solicitante
     * Busca o cliente que não contém @erpserv no email
     */
    private function extractSolicitante(array $ticketDetails): ?array
    {
        $clients = $ticketDetails['clients'] ?? [];

        if (empty($clients)) {
            Log::warning('⚠️ [MOVIDESK WEBHOOK] Nenhum cliente encontrado para solicitante');
            return null;
        }

        $targetClient = null;

        // Se houver mais de um cliente, buscar o que não contém @erpserv
        if (count($clients) > 1) {
            foreach ($clients as $client) {
                $email = $client['email'] ?? '';
                if (strpos($email, '@erpserv') === false) {
                    $targetClient = $client;
                    break;
                }
            }
        }

        // Se não encontrou, usar o primeiro
        if (!$targetClient) {
            $targetClient = $clients[0];
        }

        return [
            'name' => $targetClient['businessName'] ?? null,
            'email' => $targetClient['email'] ?? null,
            'organization' => $targetClient['organization']['businessName'] ?? null,
        ];
    }

    /**
     * Extrair dados do responsável
     */
    private function extractResponsavel(array $ticketDetails): ?array
    {
        $owner = $ticketDetails['owner'] ?? null;

        if (!$owner) {
            Log::warning('⚠️ [MOVIDESK WEBHOOK] Responsável não encontrado no ticket');
            return null;
        }

        return [
            'name' => $owner['businessName'] ?? null,
            'email' => $owner['email'] ?? null,
        ];
    }

    /**
     * Extrair nível do ticket
     * Busca no customFieldValues o campo com customFieldId = 13485
     */
    private function extractNivel(array $ticketDetails): ?string
    {
        $customFieldValues = $ticketDetails['customFieldValues'] ?? [];

        foreach ($customFieldValues as $customField) {
            if (isset($customField['customFieldId']) && $customField['customFieldId'] == 13485) {
                $items = $customField['items'] ?? [];
                if (!empty($items) && isset($items[0]['customFieldItem'])) {
                    return $items[0]['customFieldItem'];
                }
            }
        }

        Log::warning('⚠️ [MOVIDESK WEBHOOK] Nível não encontrado no ticket (customFieldId 13485)');
        return null;
    }

    /**
     * Extrair serviço do ticket
     */
    private function extractServico(array $ticketDetails): ?string
    {
        $serviceSecondLevel = $ticketDetails['serviceSecondLevel'] ?? null;
        $serviceFirstLevel = $ticketDetails['serviceFirstLevel'] ?? null;

        if (!empty($serviceSecondLevel)) {
            return $serviceSecondLevel;
        }

        if (!empty($serviceFirstLevel)) {
            return $serviceFirstLevel;
        }

        Log::warning('⚠️ [MOVIDESK WEBHOOK] Serviço não encontrado no ticket');
        return null;
    }
}
