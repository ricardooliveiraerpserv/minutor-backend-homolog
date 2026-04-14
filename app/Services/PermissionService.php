<?php

namespace App\Services;

use App\Models\User;

/**
 * Permissões definidas em código — nunca dependem de seed ou banco.
 * Espelha exatamente o RoleSeeder, mas como arrays PHP estáticos.
 */
class PermissionService
{
    public static function for(User $user): array
    {
        return match ($user->type) {
            'admin'          => self::adminPermissions(),
            'coordenador'    => self::coordenadorPermissions(),
            'consultor'      => self::consultorPermissions(),
            'cliente'        => self::clientePermissions(),
            'parceiro_admin' => self::parceiroAdminPermissions(),
            default          => [],
        };
    }

    // ── Administrator ────────────────────────────────────────────────────────
    // Acesso total — sem restrições de escopo
    private static function adminPermissions(): array
    {
        // Admin tem tudo — retornar wildcard sinaliza acesso irrestrito
        return ['*'];
    }

    // ── Coordenador ──────────────────────────────────────────────────────────
    private static function coordenadorPermissions(): array
    {
        return [
            'dashboard.view', 'dashboard.manager',
            'customers.view',
            'projects.view', 'projects.view_financial', 'projects.assign_consultants', 'projects.change_status',
            'timesheets.view', 'timesheets.manage', 'timesheets.approve', 'timesheets.view_project_full',
            'expenses.view', 'expenses.manage', 'expenses.approve',
            'users.view', 'users.view_own_profile', 'users.view_team', 'users.reset_password',
            'financial.view_own_rate', 'financial.view_project_cost',
            'reports.view', 'reports.export',
            'consultant_groups.view',
        ];
    }

    // ── Consultor ────────────────────────────────────────────────────────────
    private static function consultorPermissions(): array
    {
        return [
            'dashboard.view', 'dashboard.consultant',
            'customers.view',
            'projects.view',
            'timesheets.view', 'timesheets.manage',
            'expenses.view', 'expenses.manage',
            'users.view_own_profile',
            'financial.view_own_rate', 'financial.view_partner_rate',
        ];
    }

    // ── Cliente ──────────────────────────────────────────────────────────────
    private static function clientePermissions(): array
    {
        return [
            'dashboard.view',
            'projects.view',
            'timesheets.view_project_summary',
            'users.view_own_profile',
            'reports.view',
        ];
    }

    // ── Parceiro ADM ─────────────────────────────────────────────────────────
    private static function parceiroAdminPermissions(): array
    {
        return [
            'dashboard.view',
            'projects.view',
            'timesheets.view', 'timesheets.manage', 'timesheets.approve',
            'timesheets.view_project_full',
            'expenses.view', 'expenses.manage',
            'users.view_own_profile', 'users.view_team',
            'users.create', 'users.update', 'users.reset_password',
            'financial.view_partner_rate',
            'partners.view',
            'reports.view',
        ];
    }
}
