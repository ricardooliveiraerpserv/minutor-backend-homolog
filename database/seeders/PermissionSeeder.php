<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // ── Sistema ──────────────────────────────────────────────────────
            'admin.full_access',

            // ── Roles / Permissões ────────────────────────────────────────────
            'roles.view', 'roles.create', 'roles.update', 'roles.delete',
            'permissions.view', 'permissions.create', 'permissions.update', 'permissions.delete',

            // ── Projetos ──────────────────────────────────────────────────────
            'projects.view',
            'projects.view_financial',   // ver custos e valores do projeto
            'projects.create',
            'projects.update',
            'projects.delete',
            'projects.assign_consultants',
            'projects.change_status',
            'projects.view_sensitive_data',

            // ── Apontamentos (simplificado — escopo via Policy) ───────────────
            'timesheets.view',                   // ver próprios ou da equipe (Policy decide)
            'timesheets.manage',                 // criar / editar / excluir próprios
            'timesheets.approve',                // aprovar / rejeitar
            'timesheets.view_project_summary',   // cliente: ver resumo do projeto (sem consultores/valores)
            'timesheets.view_project_full',      // interno: ver tudo do projeto

            // ── Despesas ──────────────────────────────────────────────────────
            'expenses.view',
            'expenses.manage',
            'expenses.approve',
            'expenses.view_sensitive_data',

            // ── Financeiro (separado interno vs parceiro) ─────────────────────
            'financial.view_own_rate',       // consultor interno vê própria taxa
            'financial.view_partner_rate',   // consultor parceiro vê própria taxa (sem ver taxas internas)
            'financial.view_project_cost',   // coordenador vê custo total do projeto
            'financial.view_all',            // admin vê tudo

            // ── Usuários ──────────────────────────────────────────────────────
            'users.view',
            'users.view_all',
            'users.view_own_profile',
            'users.view_team',               // ver usuários da própria equipe / parceiro
            'users.create',
            'users.update',
            'users.update_own_profile',
            'users.delete',
            'users.manage_roles',
            'users.reset_password',

            // ── Clientes ──────────────────────────────────────────────────────
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',

            // ── Parceiros ─────────────────────────────────────────────────────
            'partners.view',
            'partners.create',
            'partners.update',
            'partners.delete',

            // ── Grupos de Consultores ─────────────────────────────────────────
            'consultant_groups.view',
            'consultant_groups.create',
            'consultant_groups.update',
            'consultant_groups.delete',

            // ── Relatórios ────────────────────────────────────────────────────
            'reports.view',
            'reports.export',
            'reports.financial',

            // ── Dashboard ─────────────────────────────────────────────────────
            'dashboard.view',
            'dashboard.admin',
            'dashboard.manager',
            'dashboard.consultant',

            // ── Dashboards (clientes) ─────────────────────────────────────────
            'dashboards.view',
            'dashboards.bank_hours_fixed.view',
            'dashboards.bank_hours_monthly.view',

            // ── Configurações ─────────────────────────────────────────────────
            'system_settings.view',
            'system_settings.update',

            // ── Categorias / Tipos auxiliares ─────────────────────────────────
            'expense_categories.view', 'expense_categories.create', 'expense_categories.update', 'expense_categories.delete',
            'expense_types.view', 'expense_types.create', 'expense_types.update', 'expense_types.delete',
            'payment_methods.view', 'payment_methods.create', 'payment_methods.update', 'payment_methods.delete',
            'service_types.view', 'service_types.create', 'service_types.update', 'service_types.delete',
            'contract_types.view', 'contract_types.create', 'contract_types.update', 'contract_types.delete',
            'project_statuses.view', 'project_statuses.create', 'project_statuses.update', 'project_statuses.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->command->info('Permissões criadas: ' . count($permissions));
    }
}
