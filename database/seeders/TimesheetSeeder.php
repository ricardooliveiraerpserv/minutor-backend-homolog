<?php

namespace Database\Seeders;

use App\Models\Timesheet;
use App\Models\User;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Database\Seeder;

class TimesheetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Buscar usuários, clientes e projetos existentes
        $users = User::all();
        $customers = Customer::all();
        $projects = Project::all();

        if ($users->isEmpty() || $customers->isEmpty() || $projects->isEmpty()) {
            $this->command->warn('Usuários, clientes ou projetos não encontrados. Execute os seeders anteriores primeiro.');
            return;
        }

        // Criar timesheets com tickets específicos para teste
        $tickets = [
            'TICKET-001',
            'TICKET-002', 
            'TICKET-003',
            'TICKET-123',
            'TICKET-456',
            'TICKET-789',
            'BUG-001',
            'FEATURE-001',
            'HOTFIX-001',
            'SUPPORT-001'
        ];

        foreach ($tickets as $index => $ticket) {
            $user = $users->random();
            $customer = $customers->random();
            $project = $projects->where('customer_id', $customer->id)->first() ?? $projects->random();
            
            $startHour = rand(8, 16);
            $endHour = $startHour + rand(1, 8);
            
            Timesheet::create([
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'project_id' => $project->id,
                'date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'start_time' => sprintf('%02d:00', $startHour),
                'end_time' => sprintf('%02d:00', min(23, $endHour)),
                'effort_minutes' => ($endHour - $startHour) * 60,
                'observation' => "Trabalho relacionado ao ticket {$ticket}",
                'ticket' => $ticket,
                'status' => ['pending', 'approved', 'rejected'][rand(0, 2)],
                'created_at' => now()->subDays(rand(1, 30)),
                'updated_at' => now()->subDays(rand(1, 30)),
            ]);
        }

        // Criar alguns timesheets sem ticket para testar o filtro
        for ($i = 0; $i < 5; $i++) {
            $user = $users->random();
            $customer = $customers->random();
            $project = $projects->where('customer_id', $customer->id)->first() ?? $projects->random();
            
            $startHour = rand(8, 16);
            $endHour = $startHour + rand(1, 8);
            
            Timesheet::create([
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'project_id' => $project->id,
                'date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'start_time' => sprintf('%02d:00', $startHour),
                'end_time' => sprintf('%02d:00', min(23, $endHour)),
                'effort_minutes' => ($endHour - $startHour) * 60,
                'observation' => "Trabalho sem ticket específico",
                'ticket' => null,
                'status' => ['pending', 'approved', 'rejected'][rand(0, 2)],
                'created_at' => now()->subDays(rand(1, 30)),
                'updated_at' => now()->subDays(rand(1, 30)),
            ]);
        }

        $this->command->info('Timesheets com tickets criados com sucesso!');
    }
}
