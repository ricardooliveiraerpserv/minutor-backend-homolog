<?php

namespace App\Policies;

use App\Models\Timesheet;
use App\Models\User;

class TimesheetPolicy
{
    /** Admin passa direto */
    private function isAdmin(User $user): bool
    {
        return $user->isAdmin() || $user->can('admin.full_access');
    }

    /** Consultor parceiro: só vê apontamentos do próprio partner_id */
    private function samePartner(User $user, Timesheet $ts): bool
    {
        return $user->partner_id !== null
            && $ts->user->partner_id === $user->partner_id;
    }

    /** Coordenador: vê apontamentos dos projetos que coordena */
    private function coordinatesProject(User $user, Timesheet $ts): bool
    {
        return $user->coordinatorProjects()
            ->where('projects.id', $ts->project_id)
            ->exists();
    }

    /** Parceiro ADM: escopo restrito ao próprio partner_id — nunca global */
    private function isOwnPartnerScope(User $user, Timesheet $ts): bool
    {
        return $user->partner_id !== null
            && $ts->user->partner_id === $user->partner_id;
    }

    // ── viewAny: pode listar? ─────────────────────────────────────────────────

    public function viewAny(User $user): bool
    {
        return $user->isAdmin()
            || $user->hasAccess('timesheets.view')
            || $user->hasAccess('timesheets.view_project_summary')
            || $user->hasAccess('timesheets.view_project_full');
    }

    // ── view: pode ver este registro específico? ──────────────────────────────

    public function view(User $user, Timesheet $ts): bool
    {
        if ($this->isAdmin($user)) return true;

        // Próprio apontamento
        if ($ts->user_id === $user->id) return true;

        // Coordenador vê equipe dos projetos que coordena
        if ($user->isCoordenador() && $this->coordinatesProject($user, $ts)) return true;

        // Parceiro ADM vê apenas apontamentos do próprio partner_id
        if ($user->isParceiroAdmin()) return $this->isOwnPartnerScope($user, $ts);

        // Consultor parceiro vê colegas do mesmo parceiro
        if ($user->isConsultor() && $user->partner_id)
            return $this->samePartner($user, $ts);

        // Cliente: ver_project_summary → apenas se o projeto é da própria empresa
        if ($user->isCliente() && $user->can('timesheets.view_project_summary'))
            return $ts->project?->customer_id === $user->customer_id;

        return false;
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function create(User $user): bool
    {
        return $user->can('timesheets.manage');
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function update(User $user, Timesheet $ts): bool
    {
        if ($this->isAdmin($user)) return true;
        if (!$user->can('timesheets.manage')) return false;

        // Parceiro ADM pode editar apontamentos do parceiro
        if ($user->isParceiroAdmin()) return $this->isOwnPartnerScope($user, $ts);

        // Consultor/Coordenador: apenas próprios
        return $ts->user_id === $user->id;
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function delete(User $user, Timesheet $ts): bool
    {
        return $this->update($user, $ts);
    }

    // ── approve ───────────────────────────────────────────────────────────────

    public function approve(User $user, Timesheet $ts): bool
    {
        if ($this->isAdmin($user)) return true;
        if (!$user->can('timesheets.approve')) return false;

        // Coordenador aprova dos projetos que coordena
        if ($user->isCoordenador()) return $this->coordinatesProject($user, $ts);

        // Parceiro ADM aprova apontamentos do próprio parceiro
        if ($user->isParceiroAdmin()) return $this->isOwnPartnerScope($user, $ts);

        return false;
    }

    // ── viewFinancial: ver valores/taxas no apontamento ───────────────────────

    public function viewFinancial(User $user, Timesheet $ts): bool
    {
        if ($this->isAdmin($user)) return true;
        if ($user->can('financial.view_all')) return true;
        if ($user->can('financial.view_project_cost') && $this->coordinatesProject($user, $ts)) return true;

        // Consultor interno vê própria taxa
        if ($user->can('financial.view_own_rate') && !$user->partner_id && $ts->user_id === $user->id)
            return true;

        // Consultor parceiro vê própria taxa — mas não vê taxas internas
        if ($user->can('financial.view_partner_rate') && $user->partner_id && $ts->user_id === $user->id)
            return true;

        return false;
    }
}
