<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Resetar cache de permissões
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. ADMINISTRADOR - Acesso full
        $adminRole = Role::firstOrCreate([
            'name' => 'Administrator',
            'guard_name' => 'web'
        ]);

        // Administrador tem todas as permissões (só atribui se não tiver)
        if ($adminRole->permissions->isEmpty()) {
            $adminRole->givePermissionTo(Permission::all());
        }

        // 2. GESTOR DE PROJETOS
        $managerRole = Role::firstOrCreate([
            'name' => 'Project Manager',
            'guard_name' => 'web'
        ]);

        $managerPermissions = [
            // Dashboard e visualizações
            'dashboard.view',
            'dashboard.manager',

            // Projetos - pode criar, alterar status, vincular pessoas
            'projects.view',
            'projects.view_sensitive_data',
            'projects.view_costs',
            'projects.create',
            'projects.update',
            'projects.change_status',
            'projects.assign_people',

            // Horas - pode ver todas, aprovar e rejeitar
            'hours.view',
            'hours.view_all',
            'hours.view_sensitive_data',
            'hours.approve',
            'hours.reject',

            // Despesas - pode ver todas, aprovar e rejeitar
            'expenses.view',
            'expenses.view_all',
            'expenses.view_sensitive_data',
            'expenses.approve',
            'expenses.reject',

            // Relatórios
            'reports.view',
            'reports.generate',
            'reports.export',
            'reports.financial',

            // Usuários - pode gerenciar todos os usuários
            'users.view',
            'users.view_all',
            'users.create',
            'users.update',
            'users.delete',
            'users.manage_roles',
            'users.reset_password',

            // Grupos de Consultores - pode gerenciar todos os grupos de consultores
            'consultant_groups.view',
            'consultant_groups.create',
            'consultant_groups.update',
            'consultant_groups.delete',

            // Configurações do Sistema - pode gerenciar todas as configurações do sistema
            'system_settings.view',
            'system_settings.update',
        ];

        // Só atribui permissões se a role não tiver nenhuma
        if ($managerRole->permissions->isEmpty()) {
            $managerRole->givePermissionTo($managerPermissions);
        }

        // 3. CONSULTOR
        $consultantRole = Role::firstOrCreate([
            'name' => 'Consultant',
            'guard_name' => 'web'
        ]);

        $consultantPermissions = [
            // Dashboard básico
            'dashboard.view',
            'dashboard.consultant',

            // Customers - visualizar todos os clientes
            'customers.view',

            // Projetos - visualizar para apontamentos (sem dados sensíveis)
            'projects.view',

            // Horas - apontar e corrigir as próprias
            'hours.view',
            'hours.view_own',
            'hours.create',
            'hours.update_own',
            'hours.delete_own',

            // Despesas - apontar e corrigir as próprias
            'expenses.view',
            'expenses.view_own',
            'expenses.create',
            'expenses.update_own',
            'expenses.delete_own',

            // Usuários - apenas o próprio perfil
            'users.view',
            'users.update_own_profile',
        ];

        // Só atribui permissões se a role não tiver nenhuma
        if ($consultantRole->permissions->isEmpty()) {
            $consultantRole->givePermissionTo($consultantPermissions);
        }

        $this->command->info('Roles criados com sucesso!');
        $this->command->info('- Administrator: ' . $adminRole->permissions->count() . ' permissões');
        $this->command->info('- Project Manager: ' . $managerRole->permissions->count() . ' permissões');
        $this->command->info('- Consultant: ' . $consultantRole->permissions->count() . ' permissões');
    }
}
