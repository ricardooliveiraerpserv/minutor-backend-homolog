<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ── 1. ADMINISTRATOR ─────────────────────────────────────────────────
        // Acesso total — sem restrições de escopo
        $admin = Role::firstOrCreate(['name' => 'Administrator', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::all());

        // ── 2. COORDENADOR ───────────────────────────────────────────────────
        // Gestão de equipe, aprovações, visão financeira dos projetos
        $coord = Role::firstOrCreate(['name' => 'Coordenador', 'guard_name' => 'web']);
        $coord->syncPermissions([
            'dashboard.view', 'dashboard.manager',
            'customers.view',
            'projects.view', 'projects.view_financial', 'projects.assign_consultants', 'projects.change_status',
            'timesheets.view', 'timesheets.manage', 'timesheets.approve', 'timesheets.view_project_full',
            'expenses.view', 'expenses.manage', 'expenses.approve',
            'users.view', 'users.view_own_profile', 'users.view_team', 'users.reset_password',
            'financial.view_own_rate', 'financial.view_project_cost',
            'reports.view', 'reports.export',
            'consultant_groups.view',
        ]);

        // ── 3. CONSULTOR ─────────────────────────────────────────────────────
        // Interno (partner_id IS NULL) ou Parceiro (partner_id preenchido)
        // Escopo de dados controlado via Policy — mesma role, contexto diferente
        // Financeiro: view_own_rate + view_partner_rate → Policy decide qual exibir
        $consultor = Role::firstOrCreate(['name' => 'Consultor', 'guard_name' => 'web']);
        $consultor->syncPermissions([
            'dashboard.view', 'dashboard.consultant',
            'customers.view',
            'projects.view',
            'timesheets.view', 'timesheets.manage',
            'expenses.view', 'expenses.manage',
            'users.view_own_profile',
            'financial.view_own_rate', 'financial.view_partner_rate',
        ]);

        // ── 4. CLIENTE ───────────────────────────────────────────────────────
        // Acesso somente leitura — restrito à própria empresa (customer_id)
        // Nunca vê consultores, valores ou dados de outros clientes
        $cliente = Role::firstOrCreate(['name' => 'Cliente', 'guard_name' => 'web']);
        $cliente->syncPermissions([
            'dashboard.view',
            'projects.view',
            'timesheets.view_project_summary',
            'users.view_own_profile',
            'reports.view',
        ]);

        // ── 5. PARCEIRO ADM ──────────────────────────────────────────────────
        // Admin da empresa parceira — SEMPRE escopo restrito ao próprio partner_id
        // Nunca acessa dados globais
        $parceiroAdm = Role::firstOrCreate(['name' => 'Parceiro ADM', 'guard_name' => 'web']);
        $parceiroAdm->syncPermissions([
            'dashboard.view',
            'projects.view',                   // escopo: projetos do parceiro (via Policy)
            'timesheets.view', 'timesheets.manage', 'timesheets.approve',
            'timesheets.view_project_full',    // escopo: apenas do próprio parceiro
            'expenses.view', 'expenses.manage',
            'users.view_own_profile', 'users.view_team', // escopo: usuários do parceiro
            'users.create', 'users.update', 'users.reset_password',
            'financial.view_partner_rate',
            'partners.view',
            'reports.view',
        ]);

        $this->command->info('Roles sincronizados:');
        foreach ([$admin, $coord, $consultor, $cliente, $parceiroAdm] as $role) {
            $role->load('permissions');
            $this->command->info("  [{$role->name}] {$role->permissions->count()} permissões");
        }
    }
}
