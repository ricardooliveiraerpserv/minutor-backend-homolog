<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /** Admin passa direto */
    private function isAdmin(User $user): bool
    {
        return $user->isAdmin() || $user->can('admin.full_access');
    }

    /** Verifica se o usuário coordena este projeto */
    private function coordinatesProject(User $user, Project $project): bool
    {
        return $user->coordinatorProjects()
            ->where('projects.id', $project->id)
            ->exists();
    }

    // ── viewAny: pode listar projetos? ────────────────────────────────────────

    public function viewAny(User $user): bool
    {
        return $user->can('projects.view');
    }

    // ── view: pode ver este projeto específico? ───────────────────────────────

    public function view(User $user, Project $project): bool
    {
        if ($this->isAdmin($user)) return true;
        if (!$user->can('projects.view')) return false;

        // Coordenador vê apenas projetos que coordena
        if ($user->isCoordenador()) return $this->coordinatesProject($user, $project);

        // Parceiro ADM vê apenas projetos do seu parceiro
        // (projetos em que pelo menos um consultor do parceiro está alocado)
        if ($user->isParceiroAdmin() && $user->partner_id !== null) {
            return $project->consultants()
                ->where('users.partner_id', $user->partner_id)
                ->exists();
        }

        // Consultor parceiro vê apenas projetos onde está alocado
        if ($user->isConsultor() && $user->partner_id !== null) {
            return $project->consultants()
                ->where('users.id', $user->id)
                ->exists();
        }

        // Consultor interno vê todos os projetos (filtro adicional pode ser feito no controller)
        if ($user->isConsultor()) return true;

        // Cliente vê apenas projetos da própria empresa
        if ($user->isCliente() && $user->customer_id !== null) {
            return $project->customer_id === $user->customer_id;
        }

        return false;
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function create(User $user): bool
    {
        return $this->isAdmin($user) || $user->can('projects.create');
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function update(User $user, Project $project): bool
    {
        if ($this->isAdmin($user)) return true;
        if (!$user->can('projects.update')) return false;

        // Coordenador pode atualizar projetos que coordena
        if ($user->isCoordenador()) return $this->coordinatesProject($user, $project);

        return false;
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function delete(User $user, Project $project): bool
    {
        return $this->isAdmin($user) || $user->can('projects.delete');
    }

    // ── viewFinancial: ver valores financeiros do projeto ─────────────────────

    public function viewFinancial(User $user, Project $project): bool
    {
        if ($this->isAdmin($user)) return true;
        if ($user->can('financial.view_all')) return true;

        // Coordenador vê custos dos projetos que coordena
        if ($user->can('financial.view_project_cost') && $this->coordinatesProject($user, $project))
            return true;

        // Parceiro ADM vê dados financeiros do próprio parceiro (taxa parceiro)
        if ($user->can('financial.view_partner_rate') && $user->isParceiroAdmin()
            && $user->partner_id !== null) {
            return $project->consultants()
                ->where('users.partner_id', $user->partner_id)
                ->exists();
        }

        return false;
    }

    // ── changeStatus ─────────────────────────────────────────────────────────

    public function changeStatus(User $user, Project $project): bool
    {
        if ($this->isAdmin($user)) return true;
        if (!$user->can('projects.change_status')) return false;

        // Coordenador altera status dos projetos que coordena
        if ($user->isCoordenador()) return $this->coordinatesProject($user, $project);

        return false;
    }

    // ── assignConsultants ─────────────────────────────────────────────────────

    public function assignConsultants(User $user, Project $project): bool
    {
        if ($this->isAdmin($user)) return true;
        if (!$user->can('projects.assign_consultants')) return false;

        // Coordenador gerencia consultores dos próprios projetos
        if ($user->isCoordenador()) return $this->coordinatesProject($user, $project);

        return false;
    }
}
