<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Buscar as roles
        $adminRole = Role::findByName('Administrator', 'web');
        $managerRole = Role::findByName('Project Manager', 'web');
        $consultantRole = Role::findByName('Consultant', 'web');

        // Usuário administrador padrão
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@minutor.com'],
            [
                'name' => 'Administrador',
                'email' => 'admin@minutor.com',
                'password' => Hash::make('admin123456'),
                'email_verified_at' => now(),
            ]
        );
        
        // Atribuir role Administrator ao usuário admin
        if (!$adminUser->hasRole('Administrator')) {
            $adminUser->assignRole($adminRole);
        }

        // Usuário de teste (Project Manager)
        $testUser = User::firstOrCreate(
            ['email' => 'teste@minutor.com'],
            [
                'name' => 'Usuário Teste',
                'email' => 'teste@minutor.com',
                'password' => Hash::make('teste123456'),
                'email_verified_at' => now(),
            ]
        );
        
        // Atribuir role Project Manager ao usuário teste
        if (!$testUser->hasRole('Project Manager')) {
            $testUser->assignRole($managerRole);
        }

        // Usuário demo (Consultant)
        $demoUser = User::firstOrCreate(
            ['email' => 'demo@minutor.com'],
            [
                'name' => 'Demo User',
                'email' => 'demo@minutor.com',
                'password' => Hash::make('demo123456'),
                'email_verified_at' => now(),
            ]
        );
        
        // Atribuir role Consultant ao usuário demo
        if (!$demoUser->hasRole('Consultant')) {
            $demoUser->assignRole($consultantRole);
        }

        $this->command->info('Usuários criados com sucesso!');
        $this->command->line('');
        $this->command->line('Credenciais de acesso:');
        $this->command->line('Admin: admin@minutor.com / admin123456 (Administrator)');
        $this->command->line('Teste: teste@minutor.com / teste123456 (Project Manager)');
        $this->command->line('Demo: demo@minutor.com / demo123456 (Consultant)');
        $this->command->line('');
        $this->command->info('Roles atribuídas com sucesso!');
    }
} 