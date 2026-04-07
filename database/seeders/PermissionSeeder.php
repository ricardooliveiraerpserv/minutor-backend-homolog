<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Resetar cache de permissões
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Definir todas as permissões do sistema
        $permissions = [
            // Permissões de Sistema/Admin
            'admin.full_access',
            // 'users.view',
            // 'users.create',
            // 'users.update',
            // 'users.delete',
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'permissions.view',
            'permissions.create',
            'permissions.update',
            'permissions.delete',

            // Permissões de Projetos
            'projects.view',
            'projects.view_sensitive_data',
            'projects.view_costs',
            'projects.create',
            'projects.update',
            'projects.delete',
            'projects.assign_people',
            'projects.change_status',

            // Permissões de Horas/Apontamentos
            'hours.view',
            'hours.view_own',
            'hours.view_all',
            'hours.view_sensitive_data',
            'hours.create',
            'hours.update_own',
            'hours.update_all',
            'hours.delete_own',
            'hours.delete_all',
            'hours.approve',
            'hours.reject',

            // Permissões de Despesas
            'expenses.view',
            'expenses.view_own',
            'expenses.view_all',
            'expenses.view_sensitive_data',
            'expenses.create',
            'expenses.update_own',
            'expenses.update_all',
            'expenses.delete_own',
            'expenses.delete_all',
            'expenses.approve',
            'expenses.reject',

            // Permissões de Relatórios
            'reports.view',
            'reports.generate',
            'reports.export',
            'reports.financial',

            // Permissões de Dashboard
            'dashboard.view',
            'dashboard.admin',
            'dashboard.manager',
            'dashboard.consultant',

            // Permissões de Dashboards (Clientes)
            'dashboards.view',                          // Acesso geral à seção de dashboards
            'dashboards.bank_hours_fixed.view',         // Dashboard de banco de horas fixo
            'dashboards.bank_hours_monthly.view',       // Dashboard de banco de horas mensais

            // Permissões de Customers
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',

            // Permissões de Usuários
            'users.view',
            'users.view_all',
            'users.create',
            'users.update',
            'users.update_own_profile',
            'users.delete',
            'users.manage_roles',
            'users.reset_password',

            // Permissões de Grupos de Consultores
            'consultant_groups.view',
            'consultant_groups.create',
            'consultant_groups.update',
            'consultant_groups.delete',

            // Permissões de Configurações do Sistema
            'system_settings.view',
            'system_settings.update',

            // Permissões de Categorias de Despesas
            'expense_categories.view',
            'expense_categories.create',
            'expense_categories.update',
            'expense_categories.delete',

            // Permissões de Tipos de Despesas
            'expense_types.view',
            'expense_types.create',
            'expense_types.update',
            'expense_types.delete',

            // Permissões de Métodos de Pagamento
            'payment_methods.view',
            'payment_methods.create',
            'payment_methods.update',
            'payment_methods.delete',

            // Permissões de Tipos de Serviço
            'service_types.view',
            'service_types.create',
            'service_types.update',
            'service_types.delete',

            // Permissões de Tipos de Contrato
            'contract_types.view',
            'contract_types.create',
            'contract_types.update',
            'contract_types.delete',

            // Permissões de Status de Projetos
            'project_statuses.view',
            'project_statuses.create',
            'project_statuses.update',
            'project_statuses.delete',
        ];

        // Criar as permissões (usando firstOrCreate para evitar duplicatas)
        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web'
            ]);
        }

        $this->command->info('Permissões criadas com sucesso!');
    }
}
