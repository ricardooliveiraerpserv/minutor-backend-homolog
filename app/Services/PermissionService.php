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
        $base = match ($user->type) {
            'admin'          => self::adminPermissions(),
            'coordenador'    => self::coordenadorPermissions(),
            'consultor'      => self::consultorPermissions(),
            'cliente'        => self::clientePermissions(),
            'parceiro_admin' => self::parceiroAdminPermissions(),
            default          => [],
        };

        // Admin já tem tudo — extras são irrelevantes
        if (in_array('*', $base, true)) {
            return $base;
        }

        $extra = $user->extra_permissions ?? [];

        return array_values(array_unique(array_merge($base, $extra)));
    }

    /**
     * Retorna todas as permissões disponíveis para exibição na UI.
     * Não inclui '*' (admin) — é usado apenas para selecionar extras.
     */
    public static function allPermissions(): array
    {
        return array_values(array_unique(array_merge(
            self::coordenadorPermissions(),
            self::consultorPermissions(),
            self::clientePermissions(),
            self::parceiroAdminPermissions(),
        )));
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
            'dashboards.view', 'dashboards.bank_hours_fixed.view', 'dashboards.bank_hours_monthly.view',
            'customers.view',
            'projects.view', 'projects.assign_consultants', 'projects.change_status',
            'timesheets.view', 'timesheets.manage', 'timesheets.approve', 'timesheets.view_project_full',
            'hours.view_all', 'hours.update_all', 'hours.delete_all',
            'expenses.view', 'expenses.manage', 'expenses.approve', 'expenses.view_all',
            'users.view', 'users.view_own_profile', 'users.update_own_profile', 'users.view_team',
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
            'users.view_own_profile', 'users.update_own_profile',
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
            'users.view_own_profile', 'users.update_own_profile',
            'reports.view',
        ];
    }

    // ── Parceiro ADM ─────────────────────────────────────────────────────────
    private static function parceiroAdminPermissions(): array
    {
        return [
            'dashboard.view', 'dashboards.view',
            'projects.view',
            'timesheets.view', 'timesheets.manage', 'timesheets.approve',
            'timesheets.view_project_full',
            'expenses.view', 'expenses.manage',
            'users.view_own_profile', 'users.update_own_profile', 'users.view_team',
            'users.create', 'users.update', 'users.reset_password',
            'financial.view_partner_rate',
            'partners.view',
            'reports.view',
        ];
    }
}
