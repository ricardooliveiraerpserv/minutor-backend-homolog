<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Usuário administrador padrão
        User::firstOrCreate(
            ['email' => 'admin@minutor.com'],
            [
                'name' => 'Administrador',
                'email' => 'admin@minutor.com',
                'password' => Hash::make('admin123456'),
                'email_verified_at' => now(),
                'type' => 'admin',
            ]
        );

        // Usuário de teste (Coordenador)
        User::firstOrCreate(
            ['email' => 'teste@minutor.com'],
            [
                'name' => 'Usuário Teste',
                'email' => 'teste@minutor.com',
                'password' => Hash::make('teste123456'),
                'email_verified_at' => now(),
                'type' => 'coordenador',
            ]
        );

        // Usuário demo (Consultor)
        User::firstOrCreate(
            ['email' => 'demo@minutor.com'],
            [
                'name' => 'Demo User',
                'email' => 'demo@minutor.com',
                'password' => Hash::make('demo123456'),
                'email_verified_at' => now(),
                'type' => 'consultor',
            ]
        );

        $this->command->info('Usuários criados com sucesso!');
        $this->command->line('');
        $this->command->line('Credenciais de acesso:');
        $this->command->line('Admin: admin@minutor.com / admin123456 (admin)');
        $this->command->line('Teste: teste@minutor.com / teste123456 (coordenador)');
        $this->command->line('Demo: demo@minutor.com / demo123456 (consultor)');
    }
}
