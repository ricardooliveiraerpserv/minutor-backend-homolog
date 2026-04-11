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

        // ── 1. ADMINISTRADOR ────────────────────────────────────────────────────
        $admin = Role::firstOrCreate(['name' => 'Administrator', 'guard_name' => 'web']);
        if ($admin->permissions->isEmpty()) {
            $admin->givePermissionTo(Permission::all());
        }

        // ── 2. CONSULTOR HORISTA ─────────────────────────────────────────────────
        // Apontamento de horas simples (sem banco de horas)
        $horista = Role::firstOrCreate(['name' => 'Consultor Horista', 'guard_name' => 'web']);
        if ($horista->permissions->isEmpty()) {
            $horista->givePermissionTo([
                'dashboard.view',
                'customers.view',
                'projects.view',
                'hours.view', 'hours.view_own', 'hours.create', 'hours.update_own', 'hours.delete_own',
                'expenses.view', 'expenses.view_own', 'expenses.create', 'expenses.update_own', 'expenses.delete_own',
                'users.view', 'users.update_own_profile',
            ]);
        }

        // ── 3. CONSULTOR BH FIXO ────────────────────────────────────────────────
        // Banco de horas com carga fixa mensal
        $bhFixo = Role::firstOrCreate(['name' => 'Consultor BH Fixo', 'guard_name' => 'web']);
        if ($bhFixo->permissions->isEmpty()) {
            $bhFixo->givePermissionTo([
                'dashboard.view',
                'customers.view',
                'projects.view',
                'hours.view', 'hours.view_own', 'hours.create', 'hours.update_own', 'hours.delete_own',
                'expenses.view', 'expenses.view_own', 'expenses.create', 'expenses.update_own', 'expenses.delete_own',
                'users.view', 'users.update_own_profile',
            ]);
        }

        // ── 4. CONSULTOR BH MENSAL ──────────────────────────────────────────────
        // Banco de horas apurado mês a mês
        $bhMensal = Role::firstOrCreate(['name' => 'Consultor BH Mensal', 'guard_name' => 'web']);
        if ($bhMensal->permissions->isEmpty()) {
            $bhMensal->givePermissionTo([
                'dashboard.view',
                'customers.view',
                'projects.view',
                'hours.view', 'hours.view_own', 'hours.create', 'hours.update_own', 'hours.delete_own',
                'expenses.view', 'expenses.view_own', 'expenses.create', 'expenses.update_own', 'expenses.delete_own',
                'users.view', 'users.update_own_profile',
            ]);
        }

        // ── 5. CLIENTE ──────────────────────────────────────────────────────────
        // Acesso de leitura ao portal do cliente (vinculado a customer_id)
        $cliente = Role::firstOrCreate(['name' => 'Cliente', 'guard_name' => 'web']);
        if ($cliente->permissions->isEmpty()) {
            $cliente->givePermissionTo([
                'dashboard.view',
                'projects.view',
                'hours.view',
                'expenses.view',
                'users.view', 'users.update_own_profile',
            ]);
        }

        // ── 6. COORDENADOR ──────────────────────────────────────────────────────
        // Aprova/rejeita horas e despesas da equipe
        $coord = Role::firstOrCreate(['name' => 'Coordenador', 'guard_name' => 'web']);
        if ($coord->permissions->isEmpty()) {
            $coord->givePermissionTo([
                'dashboard.view', 'dashboard.manager',
                'customers.view',
                'projects.view', 'projects.assign_people',
                'hours.view', 'hours.view_all', 'hours.create', 'hours.update_own', 'hours.delete_own',
                'hours.approve', 'hours.reject',
                'expenses.view', 'expenses.view_all', 'expenses.create', 'expenses.update_own', 'expenses.delete_own',
                'expenses.approve', 'expenses.reject',
                'reports.view', 'reports.generate',
                'users.view', 'users.update_own_profile',
                'consultant_groups.view',
            ]);
        }

        // ── 7. PARCEIRO ─────────────────────────────────────────────────────────
        // Usuário externo vinculado a um parceiro (leitura de dados do parceiro)
        $parceiro = Role::firstOrCreate(['name' => 'Parceiro', 'guard_name' => 'web']);
        if ($parceiro->permissions->isEmpty()) {
            $parceiro->givePermissionTo([
                'dashboard.view',
                'projects.view',
                'hours.view', 'hours.view_own', 'hours.create', 'hours.update_own', 'hours.delete_own',
                'expenses.view', 'expenses.view_own', 'expenses.create', 'expenses.update_own', 'expenses.delete_own',
                'users.view', 'users.update_own_profile',
            ]);
        }

        // ── 8. PARCEIRO ADM ─────────────────────────────────────────────────────
        // Administrador do parceiro — gerencia usuários do próprio parceiro
        $parceiroAdm = Role::firstOrCreate(['name' => 'Parceiro ADM', 'guard_name' => 'web']);
        if ($parceiroAdm->permissions->isEmpty()) {
            $parceiroAdm->givePermissionTo([
                'dashboard.view',
                'projects.view',
                'hours.view', 'hours.view_all', 'hours.create', 'hours.update_own', 'hours.delete_own',
                'expenses.view', 'expenses.view_all', 'expenses.create', 'expenses.update_own', 'expenses.delete_own',
                'reports.view', 'reports.generate',
                'users.view', 'users.update_own_profile', 'users.create', 'users.update',
                'partners.view',
            ]);
        }

        $this->command->info('Roles criados/atualizados com sucesso!');
        foreach ([$admin, $horista, $bhFixo, $bhMensal, $cliente, $coord, $parceiro, $parceiroAdm] as $role) {
            $this->command->info("  - {$role->name}: {$role->permissions->count()} permissões");
        }
    }
}
