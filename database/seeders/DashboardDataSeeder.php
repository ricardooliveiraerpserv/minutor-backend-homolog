<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\ServiceType;
use App\Models\ContractType;
use App\Models\Project;
use App\Models\ProjectChangeLog;
use App\Models\Timesheet;
use App\Models\MovideskTicket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🎯 Criando dados fictícios para Dashboard...');
        
        // 1. Criar Clientes
        $this->command->info('📋 Criando clientes...');
        $customers = $this->createCustomers();
        
        // 2. Criar Tipos de Serviço
        $this->command->info('🔧 Criando tipos de serviço...');
        $serviceTypes = $this->createServiceTypes();
        
        // 3. Criar Tipos de Contrato
        $this->command->info('📝 Criando tipos de contrato...');
        $contractTypes = $this->createContractTypes();
        
        // 4. Criar Projetos (pais e filhos)
        $this->command->info('📦 Criando projetos...');
        $projects = $this->createProjects($customers, $serviceTypes, $contractTypes);
        
        // Garantir que é uma Collection
        if (!($projects instanceof \Illuminate\Database\Eloquent\Collection)) {
            $projects = Project::all();
        }
        
        // 5. Criar Tickets do Movidesk
        $this->command->info('🎫 Criando tickets do Movidesk...');
        $tickets = $this->createMovideskTickets();
        
        // 6. Criar Timesheets com tickets
        $this->command->info('⏰ Criando timesheets...');
        $this->createTimesheets($projects, $tickets);
        
        // 7. Criar histórico de mudanças de aporte
        $this->command->info('📊 Criando histórico de mudanças...');
        $this->createProjectChangeLogs($projects);
        
        $this->command->info('✅ Dados fictícios criados com sucesso!');
    }
    
    private function createCustomers(): array
    {
        $customers = [];
        
        $customerData = [
            ['name' => 'TechCorp Solutions', 'company_name' => 'TechCorp Solutions Ltda', 'cgc' => '12345678000190'],
            ['name' => 'InnovaSoft', 'company_name' => 'InnovaSoft Sistemas', 'cgc' => '98765432000123'],
            ['name' => 'Digital Services', 'company_name' => 'Digital Services S.A.', 'cgc' => '11223344000156'],
        ];
        
        foreach ($customerData as $data) {
            $customers[] = Customer::firstOrCreate(
                ['cgc' => $data['cgc']],
                array_merge($data, ['active' => true])
            );
        }
        
        return $customers;
    }
    
    private function createServiceTypes(): array
    {
        $serviceTypes = [];
        
        $types = [
            ['name' => 'Projeto', 'code' => 'projeto', 'description' => 'Projetos de desenvolvimento'],
            ['name' => 'Sustentação', 'code' => 'sustentacao', 'description' => 'Serviços de sustentação'],
        ];
        
        foreach ($types as $type) {
            $serviceTypes[] = ServiceType::firstOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['active' => true])
            );
        }
        
        return $serviceTypes;
    }
    
    private function createContractTypes(): array
    {
        $contractTypes = [];
        
        $types = [
            ['name' => 'Fechado', 'code' => 'closed', 'description' => 'Projeto fechado'],
            ['name' => 'Banco de Horas Fixo', 'code' => 'fixed_hours', 'description' => 'Banco de horas fixo'],
            ['name' => 'Banco de Horas Mensal', 'code' => 'monthly_hours', 'description' => 'Banco de horas mensal'],
            ['name' => 'On Demand', 'code' => 'on_demand', 'description' => 'Sob demanda'],
        ];
        
        foreach ($types as $type) {
            $contractTypes[] = ContractType::firstOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['active' => true])
            );
        }
        
        return $contractTypes;
    }
    
    private function createProjects(array $customers, array $serviceTypes, array $contractTypes)
    {
        $projects = [];
        $users = User::all();
        
        if ($users->isEmpty()) {
            $this->command->warn('⚠️  Nenhum usuário encontrado. Execute UserSeeder primeiro.');
            return [];
        }
        
        // Buscar tipos específicos
        $projetoServiceType = collect($serviceTypes)->firstWhere('code', 'projeto');
        $sustentacaoServiceType = collect($serviceTypes)->firstWhere('code', 'sustentacao');
        $fechadoContractType = collect($contractTypes)->firstWhere('code', 'closed');
        $fixedHoursContractType = collect($contractTypes)->firstWhere('code', 'fixed_hours');
        
        // Criar projetos pais (tipo "Projeto")
        $parentProjects = [];
        foreach ($customers as $index => $customer) {
            // Projeto pai 1 - Banco de Horas Fixo
            $parent1 = Project::firstOrCreate(
                ['code' => "PRJ-" . strtoupper(substr($customer->name, 0, 3)) . "-001"],
                [
                    'name' => "Projeto Principal {$customer->name}",
                    'description' => "Projeto principal do cliente {$customer->name}",
                    'customer_id' => $customer->id,
                    'parent_project_id' => null,
                    'service_type_id' => $projetoServiceType->id,
                    'contract_type_id' => $fixedHoursContractType->id,
                    'status' => 'started',
                    'sold_hours' => 1200 + ($index * 200),
                    'hour_contribution' => 100 + ($index * 50),
                    'additional_hourly_rate' => 180.50 + ($index * 10),
                    'hourly_rate' => 150.00 + ($index * 5),
                    'start_date' => Carbon::now()->subMonths(6)->format('Y-m-d'),
                    'max_expense_per_consultant' => 5000.00,
                    'unlimited_expense' => false,
                    'expense_responsible_party' => 'consultancy',
                ]
            );
            $parentProjects[] = $parent1;
            
            // Projeto pai 2 - Fechado
            $parent2 = Project::firstOrCreate(
                ['code' => "PRJ-" . strtoupper(substr($customer->name, 0, 3)) . "-002"],
                [
                    'name' => "Projeto Fechado {$customer->name}",
                    'description' => "Projeto fechado do cliente {$customer->name}",
                    'customer_id' => $customer->id,
                    'parent_project_id' => null,
                    'service_type_id' => $projetoServiceType->id,
                    'contract_type_id' => $fechadoContractType->id,
                    'status' => 'started',
                    'sold_hours' => 800,
                    'hour_contribution' => 0,
                    'additional_hourly_rate' => null,
                    'hourly_rate' => 200.00,
                    'start_date' => Carbon::now()->subMonths(2)->format('Y-m-d'),
                    'max_expense_per_consultant' => null,
                    'unlimited_expense' => false,
                ]
            );
            $parentProjects[] = $parent2;
            
            // Criar projetos filhos (Sustentação) para o primeiro projeto pai
            $child1 = Project::firstOrCreate(
                ['code' => "SUS-" . strtoupper(substr($customer->name, 0, 3)) . "-001"],
                [
                    'name' => "Sustentação - {$parent1->name}",
                    'description' => "Projeto de sustentação vinculado ao projeto principal",
                    'customer_id' => $customer->id,
                    'parent_project_id' => $parent1->id,
                    'service_type_id' => $sustentacaoServiceType->id,
                    'contract_type_id' => $fixedHoursContractType->id,
                    'status' => 'started',
                    'sold_hours' => null,
                    'hour_contribution' => 50,
                    'additional_hourly_rate' => 180.50,
                    'hourly_rate' => 150.00,
                    'start_date' => Carbon::now()->subMonths(3)->format('Y-m-d'),
                    'max_expense_per_consultant' => 3000.00,
                    'unlimited_expense' => false,
                ]
            );
            
            $child2 = Project::firstOrCreate(
                ['code' => "SUS-" . strtoupper(substr($customer->name, 0, 3)) . "-002"],
                [
                    'name' => "Sustentação 2 - {$parent1->name}",
                    'description' => "Segundo projeto de sustentação",
                    'customer_id' => $customer->id,
                    'parent_project_id' => $parent1->id,
                    'service_type_id' => $sustentacaoServiceType->id,
                    'contract_type_id' => $fixedHoursContractType->id,
                    'status' => 'started',
                    'sold_hours' => null,
                    'hour_contribution' => 30,
                    'additional_hourly_rate' => 180.50,
                    'hourly_rate' => 150.00,
                    'start_date' => Carbon::now()->subMonths(1)->format('Y-m-d'),
                    'max_expense_per_consultant' => 2000.00,
                    'unlimited_expense' => false,
                ]
            );
        }
        
        return Project::all(); // Retorna Collection de projetos
    }
    
    private function createMovideskTickets(): array
    {
        $tickets = [];
        
        // Dados variados para criar tickets realistas
        $solicitantes = [
            ['name' => 'João Silva', 'email' => 'joao.silva@example.com'],
            ['name' => 'Maria Santos', 'email' => 'maria.santos@example.com'],
            ['name' => 'Pedro Oliveira', 'email' => 'pedro.oliveira@example.com'],
            ['name' => 'Ana Costa', 'email' => 'ana.costa@example.com'],
            ['name' => 'Carlos Ferreira', 'email' => 'carlos.ferreira@example.com'],
        ];
        
        $responsaveis = [
            ['name' => 'Equipe Técnica', 'email' => 'tecnica@example.com'],
            ['name' => 'Suporte N1', 'email' => 'suporte.n1@example.com'],
            ['name' => 'Desenvolvimento', 'email' => 'dev@example.com'],
        ];
        
        $categorias = ['Desenvolvimento', 'Bug', 'Melhoria', 'Dúvida', 'Configuração', 'Treinamento'];
        $statuses = ['Em Andamento', 'Aguardando', 'Resolvido', 'Fechado', 'Cancelado', 'Pendente'];
        $niveis = ['N1', 'N2', 'N3', 'N4'];
        $servicos = ['Módulo Financeiro', 'Módulo RH', 'Módulo Vendas', 'Módulo Estoque', 'Sistema Principal', 'API'];
        $urgencias = ['Baixa', 'Média', 'Alta', 'Crítica'];
        
        // Criar tickets distribuídos nos últimos 12 meses
        $ticketCounter = 1;
        for ($month = 11; $month >= 0; $month--) {
            $ticketsInMonth = rand(5, 15); // 5 a 15 tickets por mês
            
            for ($i = 0; $i < $ticketsInMonth; $i++) {
                $ticketId = (string) (10000 + $ticketCounter);
                $createdAt = Carbon::now()->subMonths($month)->subDays(rand(0, 27));
                
                $ticket = MovideskTicket::firstOrCreate(
                    ['ticket_id' => $ticketId],
                    [
                        'titulo' => "Ticket #{$ticketId} - " . $categorias[array_rand($categorias)],
                        'categoria' => $categorias[array_rand($categorias)],
                        'urgencia' => $urgencias[array_rand($urgencias)],
                        'nivel' => $niveis[array_rand($niveis)],
                        'servico' => $servicos[array_rand($servicos)],
                        'status' => $statuses[array_rand($statuses)],
                        'solicitante' => $solicitantes[array_rand($solicitantes)],
                        'responsavel' => $responsaveis[array_rand($responsaveis)],
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt->copy()->addDays(rand(1, 5)),
                    ]
                );
                
                $tickets[] = $ticket;
                $ticketCounter++;
            }
        }
        
        // Criar alguns tickets com 8h+ de apontamentos (para testar endpoint específico)
        for ($i = 0; $i < 5; $i++) {
            $ticketId = (string) (20000 + $i);
            $ticket = MovideskTicket::firstOrCreate(
                ['ticket_id' => $ticketId],
                [
                    'titulo' => "Ticket Grande #{$ticketId} - Mais de 8 horas",
                    'categoria' => 'Desenvolvimento',
                    'urgencia' => 'Alta',
                    'nivel' => 'N2',
                    'servico' => 'Sistema Principal',
                    'status' => 'Em Andamento',
                    'solicitante' => $solicitantes[array_rand($solicitantes)],
                    'responsavel' => $responsaveis[array_rand($responsaveis)],
                    'created_at' => Carbon::now()->subMonths(rand(1, 6)),
                    'updated_at' => Carbon::now()->subMonths(rand(1, 6)),
                ]
            );
            $tickets[] = $ticket;
        }
        
        return $tickets;
    }
    
    private function createTimesheets($projects, array $tickets): void
    {
        $users = User::all();
        $projectsCollection = $projects instanceof \Illuminate\Database\Eloquent\Collection 
            ? $projects 
            : collect($projects);
        
        if ($users->isEmpty()) {
            $this->command->warn('⚠️  Nenhum usuário encontrado. Não foi possível criar timesheets.');
            return;
        }
        
        // Buscar o tipo de serviço "Sustentação"
        $maintenanceServiceType = ServiceType::where('code', 'sustentacao')
            ->orWhere('name', 'Sustentação')
            ->first();
        
        // Criar timesheets distribuídos nos últimos 12 meses
        $ticketIndex = 0;
        $ticketCount = count($tickets);
        
        // Para cada projeto, criar timesheets
        foreach ($projectsCollection as $project) {
            $projectModel = $project instanceof Project ? $project : Project::find($project['id'] ?? $project->id);
            if (!$projectModel) continue;
            
            // Verificar se é projeto de Sustentação
            $isMaintenanceProject = $maintenanceServiceType && $projectModel->service_type_id === $maintenanceServiceType->id;
            
            // Criar timesheets para os últimos 12 meses
            for ($month = 11; $month >= 0; $month--) {
                $daysInMonth = rand(5, 15); // 5 a 15 dias com apontamentos por mês
                
                for ($day = 0; $day < $daysInMonth; $day++) {
                    $date = Carbon::now()->subMonths($month)->subDays(rand(0, 27));
                    
                    // Para projetos de Sustentação, SEMPRE usar ticket (100%)
                    // Para outros projetos, 80% com ticket
                    if ($isMaintenanceProject) {
                        // Projetos de Sustentação: SEMPRE com ticket
                        if ($ticketCount > 0) {
                            $ticket = $tickets[$ticketIndex % $ticketCount];
                            $ticketId = $ticket->ticket_id;
                            $ticketIndex++;
                        } else {
                            $ticketId = null;
                        }
                    } else {
                        // Outros projetos: 80% com ticket
                        if ($ticketIndex < $ticketCount && rand(0, 100) < 80) {
                            $ticket = $tickets[$ticketIndex % $ticketCount];
                            $ticketId = $ticket->ticket_id;
                            $ticketIndex++;
                        } else {
                            $ticketId = null;
                        }
                    }
                    
                    // Criar 1-3 timesheets por dia
                    $timesheetsPerDay = rand(1, 3);
                    for ($t = 0; $t < $timesheetsPerDay; $t++) {
                        $user = $users->random();
                        $startHour = rand(8, 16);
                        $endHour = min(18, $startHour + rand(1, 8));
                        $effortMinutes = ($endHour - $startHour) * 60;
                        
                        // Para tickets grandes (20000+), garantir mais horas
                        if ($ticketId && strpos($ticketId, '20000') === 0) {
                            $effortMinutes = rand(480, 960); // 8 a 16 horas
                            $startHour = 8;
                            $endHour = min(18, 8 + ($effortMinutes / 60));
                        }
                        
                        $statuses = ['pending', 'approved', 'rejected'];
                        $status = $statuses[array_rand($statuses)];
                        
                        // Se aprovado, adicionar reviewed_by
                        $reviewedBy = null;
                        $reviewedAt = null;
                        if ($status === 'approved' && rand(0, 100) < 70) {
                            $reviewedBy = $users->random()->id;
                            $reviewedAt = $date->copy()->addDays(rand(1, 3));
                        }
                        
                        Timesheet::create([
                            'user_id' => $user->id,
                            'customer_id' => $projectModel->customer_id,
                            'project_id' => $projectModel->id,
                            'date' => $date->format('Y-m-d'),
                            'start_time' => sprintf('%02d:00', $startHour),
                            'end_time' => sprintf('%02d:00', $endHour),
                            'effort_minutes' => $effortMinutes,
                            'observation' => $ticketId ? "Trabalho no ticket {$ticketId}" : "Trabalho geral",
                            'ticket' => $ticketId,
                            'status' => $status,
                            'reviewed_by' => $reviewedBy,
                            'reviewed_at' => $reviewedAt,
                            'created_at' => $date,
                            'updated_at' => $date,
                        ]);
                    }
                }
            }
        }
        
        // Garantir que projetos de Sustentação tenham timesheets suficientes com tickets
        if ($maintenanceServiceType) {
            $maintenanceProjects = Project::where('service_type_id', $maintenanceServiceType->id)->get();
            
            foreach ($maintenanceProjects as $maintenanceProject) {
                // Verificar quantos timesheets com tickets já existem
                $existingTimesheetsWithTickets = Timesheet::where('project_id', $maintenanceProject->id)
                    ->whereNotNull('ticket')
                    ->where('ticket', '!=', '')
                    ->count();
                
                // Se tiver menos de 50 timesheets com tickets, criar mais
                if ($existingTimesheetsWithTickets < 50 && $ticketCount > 0) {
                    $this->command->info("   📝 Garantindo timesheets com tickets para projeto de Sustentação: {$maintenanceProject->name}");
                    
                    for ($i = 0; $i < 50 - $existingTimesheetsWithTickets; $i++) {
                        $ticket = $tickets[($ticketIndex + $i) % $ticketCount];
                        $date = Carbon::now()->subDays(rand(1, 180));
                        $user = $users->random();
                        $startHour = rand(8, 16);
                        $endHour = min(18, $startHour + rand(1, 8));
                        $effortMinutes = ($endHour - $startHour) * 60;
                        
                        $statuses = ['pending', 'approved', 'rejected'];
                        $status = $statuses[array_rand($statuses)];
                        
                        $reviewedBy = null;
                        $reviewedAt = null;
                        if ($status === 'approved' && rand(0, 100) < 70) {
                            $reviewedBy = $users->random()->id;
                            $reviewedAt = $date->copy()->addDays(rand(1, 3));
                        }
                        
                        Timesheet::create([
                            'user_id' => $user->id,
                            'customer_id' => $maintenanceProject->customer_id,
                            'project_id' => $maintenanceProject->id,
                            'date' => $date->format('Y-m-d'),
                            'start_time' => sprintf('%02d:00', $startHour),
                            'end_time' => sprintf('%02d:00', $endHour),
                            'effort_minutes' => $effortMinutes,
                            'observation' => "Trabalho no ticket {$ticket->ticket_id}",
                            'ticket' => $ticket->ticket_id,
                            'status' => $status,
                            'reviewed_by' => $reviewedBy,
                            'reviewed_at' => $reviewedAt,
                            'created_at' => $date,
                            'updated_at' => $date,
                        ]);
                    }
                }
            }
        }
        
        // Criar timesheets adicionais para tickets grandes (8h+)
        $bigTickets = MovideskTicket::where('ticket_id', 'like', '20000%')->get();
        foreach ($bigTickets as $ticket) {
            $project = $projectsCollection->random();
            $projectModel = Project::find($project['id']);
            
            // Criar múltiplos timesheets para totalizar mais de 8 horas
            $totalMinutes = 0;
            $targetMinutes = rand(480, 1200); // 8 a 20 horas
            
            while ($totalMinutes < $targetMinutes) {
                $user = $users->random();
                $date = Carbon::now()->subDays(rand(1, 60));
                $startHour = rand(8, 16);
                $effortMinutes = rand(60, 480); // 1 a 8 horas por apontamento
                $endHour = min(18, $startHour + ($effortMinutes / 60));
                
                if ($totalMinutes + $effortMinutes > $targetMinutes) {
                    $effortMinutes = $targetMinutes - $totalMinutes;
                    $endHour = min(18, $startHour + ($effortMinutes / 60));
                }
                
                Timesheet::create([
                    'user_id' => $user->id,
                    'customer_id' => $projectModel->customer_id,
                    'project_id' => $projectModel->id,
                    'date' => $date->format('Y-m-d'),
                    'start_time' => sprintf('%02d:00', $startHour),
                    'end_time' => sprintf('%02d:00', $endHour),
                    'effort_minutes' => $effortMinutes,
                    'observation' => "Trabalho extenso no ticket {$ticket->ticket_id}",
                    'ticket' => $ticket->ticket_id,
                    'status' => 'approved',
                    'reviewed_by' => $users->random()->id,
                    'reviewed_at' => $date->copy()->addDays(1),
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
                
                $totalMinutes += $effortMinutes;
            }
        }
    }
    
    private function createProjectChangeLogs($projects): void
    {
        $users = User::all();
        if ($users->isEmpty()) {
            return;
        }
        
        // Buscar apenas projetos pais
        $parentProjects = collect($projects)->filter(function($p) {
            if ($p instanceof Project) {
                return $p->parent_project_id === null;
            }
            return ($p['parent_project_id'] ?? $p->parent_project_id) === null;
        });
        
        foreach ($parentProjects as $projectData) {
            $project = $projectData instanceof Project ? $projectData : Project::find($projectData['id'] ?? $projectData->id);
            if (!$project) continue;
            
            // Criar 2-5 mudanças de aporte de horas
            $changes = rand(2, 5);
            $currentContribution = $project->hour_contribution ?? 0;
            
            for ($i = 0; $i < $changes; $i++) {
                $oldValue = $currentContribution;
                $newValue = $oldValue + rand(-50, 100);
                if ($newValue < 0) $newValue = 0;
                
                $changeDate = Carbon::now()->subMonths(rand(1, 6))->subDays(rand(1, 27));
                
                ProjectChangeLog::create([
                    'project_id' => $project->id,
                    'field_name' => 'hour_contribution',
                    'old_value' => (string) $oldValue,
                    'new_value' => (string) $newValue,
                    'reason' => "Ajuste de aporte de horas - " . ['Revisão mensal', 'Ajuste de escopo', 'Negociação com cliente', 'Correção de cálculo'][rand(0, 3)],
                    'changed_by' => $users->random()->id,
                    'created_at' => $changeDate,
                    'updated_at' => $changeDate,
                ]);
                
                $currentContribution = $newValue;
            }
        }
    }
}
