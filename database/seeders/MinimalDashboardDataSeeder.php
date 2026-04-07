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
use Carbon\Carbon;

class MinimalDashboardDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Cria um cliente com projetos mínimos necessários para validar
     * todas as regras de negócio do DashboardController.
     */
    public function run(): void
    {
        $this->command->info('🎯 Criando dados mínimos para validação do Dashboard...');
        
        // 1. Criar Cliente
        $this->command->info('📋 Criando cliente...');
        $customer = Customer::firstOrCreate(
            ['cgc' => '12345678000190'],
            [
                'name' => 'Cliente Teste Dashboard',
                'company_name' => 'Cliente Teste Dashboard Ltda',
                'active' => true,
            ]
        );
        
        // 2. Criar Tipos de Serviço
        $this->command->info('🔧 Criando tipos de serviço...');
        $projetoServiceType = ServiceType::firstOrCreate(
            ['code' => 'projeto'],
            [
                'name' => 'Projeto',
                'description' => 'Projetos de desenvolvimento',
                'active' => true,
            ]
        );
        
        $sustentacaoServiceType = ServiceType::firstOrCreate(
            ['code' => 'sustentacao'],
            [
                'name' => 'Sustentação',
                'description' => 'Serviços de sustentação',
                'active' => true,
            ]
        );
        
        // 3. Criar Tipos de Contrato
        $this->command->info('📝 Criando tipos de contrato...');
        $fechadoContractType = ContractType::firstOrCreate(
            ['code' => 'closed'],
            [
                'name' => 'Fechado',
                'description' => 'Projeto fechado',
                'active' => true,
            ]
        );
        
        $fixedHoursContractType = ContractType::firstOrCreate(
            ['code' => 'fixed_hours'],
            [
                'name' => 'Banco de Horas Fixo',
                'description' => 'Banco de horas fixo',
                'active' => true,
            ]
        );
        
        // 4. Verificar se há usuários
        $users = User::all();
        if ($users->isEmpty()) {
            $this->command->warn('⚠️  Nenhum usuário encontrado. Execute UserSeeder primeiro.');
            return;
        }
        $user = $users->first();
        
        // 5. Criar Projetos
        $this->command->info('📦 Criando projetos...');
        
        // Projeto pai 1: Banco de Horas Fixo (para consumo normal com timesheets)
        $parent1 = Project::firstOrCreate(
            ['code' => 'PRJ-TEST-001'],
            [
                'name' => 'Projeto Banco de Horas Fixo',
                'description' => 'Projeto para testar consumo normal com timesheets',
                'customer_id' => $customer->id,
                'parent_project_id' => null,
                'service_type_id' => $projetoServiceType->id,
                'contract_type_id' => $fixedHoursContractType->id,
                'status' => 'started',
                'sold_hours' => 100,
                'hour_contribution' => 50,
                'additional_hourly_rate' => 180.50,
                'hourly_rate' => 150.00,
                'start_date' => Carbon::now()->subMonths(2)->format('Y-m-d'),
                'max_expense_per_consultant' => 5000.00,
                'unlimited_expense' => false,
                'expense_responsible_party' => 'consultancy',
            ]
        );
        
        // Projeto pai 2: Fechado (para consumo com sold_hours + hour_contribution)
        $parent2 = Project::firstOrCreate(
            ['code' => 'PRJ-TEST-002'],
            [
                'name' => 'Projeto Fechado',
                'description' => 'Projeto fechado para testar consumo com sold_hours',
                'customer_id' => $customer->id,
                'parent_project_id' => null,
                'service_type_id' => $projetoServiceType->id,
                'contract_type_id' => $fechadoContractType->id,
                'status' => 'started',
                'sold_hours' => 80,
                'hour_contribution' => 20,
                'additional_hourly_rate' => null,
                'hourly_rate' => 200.00,
                'start_date' => Carbon::now()->startOfMonth()->format('Y-m-d'), // Início do mês atual
                'max_expense_per_consultant' => null,
                'unlimited_expense' => false,
            ]
        );
        
        // Projeto filho: Sustentação (para testar tickets de sustentação)
        $child1 = Project::firstOrCreate(
            ['code' => 'SUS-TEST-001'],
            [
                'name' => 'Sustentação - Projeto Banco de Horas',
                'description' => 'Projeto de sustentação para testar tickets',
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
                'max_expense_per_consultant' => 3000.00,
                'unlimited_expense' => false,
            ]
        );
        
        // 6. Criar Tickets do Movidesk
        $this->command->info('🎫 Criando tickets do Movidesk...');
        $tickets = [];
        
        // Ticket 1: Para projeto de Sustentação
        $ticket1 = MovideskTicket::firstOrCreate(
            ['ticket_id' => '10001'],
            [
                'titulo' => 'Ticket Sustentação 1',
                'categoria' => 'Bug',
                'urgencia' => 'Alta',
                'nivel' => 'N2',
                'servico' => 'Módulo Financeiro',
                'status' => 'Em Andamento',
                'solicitante' => ['name' => 'João Silva', 'email' => 'joao.silva@example.com'],
                'responsavel' => ['name' => 'Equipe Técnica', 'email' => 'tecnica@example.com'],
                'created_at' => Carbon::now()->subDays(5),
                'updated_at' => Carbon::now()->subDays(2),
            ]
        );
        $tickets[] = $ticket1;
        
        // Ticket 2: Para projeto de Sustentação
        $ticket2 = MovideskTicket::firstOrCreate(
            ['ticket_id' => '10002'],
            [
                'titulo' => 'Ticket Sustentação 2',
                'categoria' => 'Melhoria',
                'urgencia' => 'Média',
                'nivel' => 'N1',
                'servico' => 'Módulo RH',
                'status' => 'Resolvido',
                'solicitante' => ['name' => 'Maria Santos', 'email' => 'maria.santos@example.com'],
                'responsavel' => ['name' => 'Suporte N1', 'email' => 'suporte.n1@example.com'],
                'created_at' => Carbon::now()->subDays(10),
                'updated_at' => Carbon::now()->subDays(8),
            ]
        );
        $tickets[] = $ticket2;
        
        // Ticket 3: Para projeto normal (Banco de Horas Fixo)
        $ticket3 = MovideskTicket::firstOrCreate(
            ['ticket_id' => '10003'],
            [
                'titulo' => 'Ticket Projeto Normal',
                'categoria' => 'Desenvolvimento',
                'urgencia' => 'Baixa',
                'nivel' => 'N3',
                'servico' => 'Sistema Principal',
                'status' => 'Aguardando',
                'solicitante' => ['name' => 'Pedro Oliveira', 'email' => 'pedro.oliveira@example.com'],
                'responsavel' => ['name' => 'Desenvolvimento', 'email' => 'dev@example.com'],
                'created_at' => Carbon::now()->subDays(3),
                'updated_at' => Carbon::now()->subDays(1),
            ]
        );
        $tickets[] = $ticket3;
        
        // Ticket 4: Ticket com 8h+ (para testar endpoint específico)
        $ticket4 = MovideskTicket::firstOrCreate(
            ['ticket_id' => '20001'],
            [
                'titulo' => 'Ticket Grande - Mais de 8 horas',
                'categoria' => 'Desenvolvimento',
                'urgencia' => 'Alta',
                'nivel' => 'N2',
                'servico' => 'Sistema Principal',
                'status' => 'Em Andamento',
                'solicitante' => ['name' => 'Ana Costa', 'email' => 'ana.costa@example.com'],
                'responsavel' => ['name' => 'Desenvolvimento', 'email' => 'dev@example.com'],
                'created_at' => Carbon::now()->subDays(7),
                'updated_at' => Carbon::now()->subDays(5),
            ]
        );
        $tickets[] = $ticket4;
        
        // 7. Criar Timesheets (máximo 3 por projeto)
        $this->command->info('⏰ Criando timesheets...');
        
        // Timesheets para projeto pai 1 (Banco de Horas Fixo)
        // Timesheet 1: Aprovado com ticket
        Timesheet::firstOrCreate(
            [
                'user_id' => $user->id,
                'project_id' => $parent1->id,
                'date' => Carbon::now()->subDays(2)->format('Y-m-d'),
                'ticket' => '10003',
            ],
            [
                'customer_id' => $customer->id,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'effort_minutes' => 180,
                'observation' => 'Trabalho no ticket 10003',
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => Carbon::now()->subDays(1),
                'created_at' => Carbon::now()->subDays(2),
                'updated_at' => Carbon::now()->subDays(1),
            ]
        );
        
        // Timesheet 2: Pendente sem ticket
        Timesheet::firstOrCreate(
            [
                'user_id' => $user->id,
                'project_id' => $parent1->id,
                'date' => Carbon::now()->subDays(1)->format('Y-m-d'),
                'ticket' => null,
            ],
            [
                'customer_id' => $customer->id,
                'start_time' => '14:00',
                'end_time' => '17:00',
                'effort_minutes' => 180,
                'observation' => 'Trabalho geral',
                'status' => 'pending',
                'created_at' => Carbon::now()->subDays(1),
                'updated_at' => Carbon::now()->subDays(1),
            ]
        );
        
        // Timesheet 3: Rejeitado (não deve ser contado no consumo)
        Timesheet::firstOrCreate(
            [
                'user_id' => $user->id,
                'project_id' => $parent1->id,
                'date' => Carbon::now()->subDays(3)->format('Y-m-d'),
                'ticket' => null,
            ],
            [
                'customer_id' => $customer->id,
                'start_time' => '10:00',
                'end_time' => '11:00',
                'effort_minutes' => 60,
                'observation' => 'Trabalho rejeitado',
                'status' => 'rejected',
                'created_at' => Carbon::now()->subDays(3),
                'updated_at' => Carbon::now()->subDays(3),
            ]
        );
        
        // Timesheets para projeto filho (Sustentação) - TODOS com tickets
        // Timesheet 1: Aprovado com ticket
        Timesheet::firstOrCreate(
            [
                'user_id' => $user->id,
                'project_id' => $child1->id,
                'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
                'ticket' => '10001',
            ],
            [
                'customer_id' => $customer->id,
                'start_time' => '08:00',
                'end_time' => '12:00',
                'effort_minutes' => 240,
                'observation' => 'Trabalho no ticket 10001',
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => Carbon::now()->subDays(4),
                'created_at' => Carbon::now()->subDays(5),
                'updated_at' => Carbon::now()->subDays(4),
            ]
        );
        
        // Timesheet 2: Pendente com ticket
        Timesheet::firstOrCreate(
            [
                'user_id' => $user->id,
                'project_id' => $child1->id,
                'date' => Carbon::now()->subDays(10)->format('Y-m-d'),
                'ticket' => '10002',
            ],
            [
                'customer_id' => $customer->id,
                'start_time' => '13:00',
                'end_time' => '16:00',
                'effort_minutes' => 180,
                'observation' => 'Trabalho no ticket 10002',
                'status' => 'pending',
                'created_at' => Carbon::now()->subDays(10),
                'updated_at' => Carbon::now()->subDays(10),
            ]
        );
        
        // Timesheet 3: Aprovado com ticket (para garantir que há tickets)
        Timesheet::firstOrCreate(
            [
                'user_id' => $user->id,
                'project_id' => $child1->id,
                'date' => Carbon::now()->subDays(7)->format('Y-m-d'),
                'ticket' => '10001',
            ],
            [
                'customer_id' => $customer->id,
                'start_time' => '09:00',
                'end_time' => '11:00',
                'effort_minutes' => 120,
                'observation' => 'Trabalho no ticket 10001',
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => Carbon::now()->subDays(6),
                'created_at' => Carbon::now()->subDays(7),
                'updated_at' => Carbon::now()->subDays(6),
            ]
        );
        
        // Timesheets para ticket grande (8h+)
        // Criar múltiplos timesheets para o ticket 20001 totalizando mais de 8 horas
        $totalMinutes = 0;
        $targetMinutes = 540; // 9 horas
        
        // Timesheet 1: 4 horas
        Timesheet::firstOrCreate(
            [
                'user_id' => $user->id,
                'project_id' => $parent1->id,
                'date' => Carbon::now()->subDays(7)->format('Y-m-d'),
                'ticket' => '20001',
            ],
            [
                'customer_id' => $customer->id,
                'start_time' => '08:00',
                'end_time' => '12:00',
                'effort_minutes' => 240,
                'observation' => 'Trabalho extenso no ticket 20001',
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => Carbon::now()->subDays(6),
                'created_at' => Carbon::now()->subDays(7),
                'updated_at' => Carbon::now()->subDays(6),
            ]
        );
        $totalMinutes += 240;
        
        // Timesheet 2: 3 horas
        Timesheet::firstOrCreate(
            [
                'user_id' => $user->id,
                'project_id' => $parent1->id,
                'date' => Carbon::now()->subDays(6)->format('Y-m-d'),
                'ticket' => '20001',
            ],
            [
                'customer_id' => $customer->id,
                'start_time' => '14:00',
                'end_time' => '17:00',
                'effort_minutes' => 180,
                'observation' => 'Trabalho extenso no ticket 20001',
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => Carbon::now()->subDays(5),
                'created_at' => Carbon::now()->subDays(6),
                'updated_at' => Carbon::now()->subDays(5),
            ]
        );
        $totalMinutes += 180;
        
        // Timesheet 3: 2 horas (totalizando 9 horas)
        Timesheet::firstOrCreate(
            [
                'user_id' => $user->id,
                'project_id' => $parent1->id,
                'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
                'ticket' => '20001',
            ],
            [
                'customer_id' => $customer->id,
                'start_time' => '10:00',
                'end_time' => '12:00',
                'effort_minutes' => 120,
                'observation' => 'Trabalho extenso no ticket 20001',
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => Carbon::now()->subDays(4),
                'created_at' => Carbon::now()->subDays(5),
                'updated_at' => Carbon::now()->subDays(4),
            ]
        );
        $totalMinutes += 120;
        
        // 8. Criar histórico de mudanças de aporte
        $this->command->info('📊 Criando histórico de mudanças...');
        
        // Mudança 1: Aumento de aporte
        ProjectChangeLog::firstOrCreate(
            [
                'project_id' => $parent1->id,
                'field_name' => 'hour_contribution',
                'created_at' => Carbon::now()->subMonths(1)->subDays(15),
            ],
            [
                'old_value' => '30',
                'new_value' => '50',
                'reason' => 'Aumento de aporte para atender demanda',
                'changed_by' => $user->id,
                'updated_at' => Carbon::now()->subMonths(1)->subDays(15),
            ]
        );
        
        // Mudança 2: Redução de aporte
        ProjectChangeLog::firstOrCreate(
            [
                'project_id' => $parent1->id,
                'field_name' => 'hour_contribution',
                'created_at' => Carbon::now()->subMonths(2)->subDays(10),
            ],
            [
                'old_value' => '40',
                'new_value' => '30',
                'reason' => 'Ajuste de escopo',
                'changed_by' => $user->id,
                'updated_at' => Carbon::now()->subMonths(2)->subDays(10),
            ]
        );
        
        $this->command->info('✅ Dados mínimos criados com sucesso!');
        $this->command->info("   Cliente: {$customer->name}");
        $this->command->info("   Projetos criados: 3 (2 pais + 1 filho)");
        $this->command->info("   Tickets criados: " . count($tickets));
        $this->command->info("   Timesheets criados: 9");
    }
}
