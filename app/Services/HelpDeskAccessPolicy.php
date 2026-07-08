<?php

namespace App\Services;

use App\Models\HelpDeskAccessProfile;
use App\Models\HelpDeskTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Enforcement do NÚCLEO dos Perfis de Acesso do Help Desk.
 *
 * Rollout seguro: ADMIN e usuário SEM perfil vinculado têm acesso total (comportamento
 * atual). As restrições só valem para quem tem um perfil atribuído (aba Pessoas). Assim,
 * ligar o enforcement não quebra nada enquanto os vínculos não forem definidos.
 *
 * Núcleo coberto: ver / editar tickets (escopo), abrir, excluir, reabrir, ser responsável.
 * (Os demais toggles do perfil ficam guardados p/ enforcement incremental.)
 *
 * Obs.: "parent/assigned_or_parent" degradam para "assigned" — não há hierarquia pai/filho
 * de tickets no nosso modelo.
 */
class HelpDeskAccessPolicy
{
    private array $cache = [];

    private function profile(?User $user): ?HelpDeskAccessProfile
    {
        if (!$user) return null;
        if (!array_key_exists($user->id, $this->cache)) {
            $this->cache[$user->id] = $user->helpdesk_access_profile_id
                ? HelpDeskAccessProfile::find($user->helpdesk_access_profile_id) : null;
        }
        return $this->cache[$user->id];
    }

    private function perm(?User $user, string $key, $default)
    {
        $p = $this->profile($user);
        if (!$p || !is_array($p->permissions)) return $default;
        return $p->permissions[$key] ?? $default;
    }

    /** Admin sempre, e quem não tem perfil = sem restrição (compat). */
    private function unrestricted(?User $user): bool
    {
        return !$user || $user->isAdmin() || !$this->profile($user);
    }

    // ── Escopos ────────────────────────────────────────────────────────────
    public function viewScope(?User $user): string
    {
        return $this->unrestricted($user) ? 'all' : (string) $this->perm($user, 'policies.view_tickets', 'all');
    }

    public function editScope(?User $user): string
    {
        return $this->unrestricted($user) ? 'all' : (string) $this->perm($user, 'policies.edit_tickets', 'all');
    }

    public function canOpen(?User $user): bool
    {
        return $this->unrestricted($user) ? true : $this->perm($user, 'tickets.open', 'public_and_internal') !== 'none';
    }

    public function canDelete(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'service.delete_tickets', true);
    }

    public function canReopen(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'service.reopen_tickets', true);
    }

    public function canBeAssignee(?User $user): bool
    {
        if (!$user) return false;
        return ($user->isAdmin() || !$this->profile($user)) ? true : (bool) $this->perm($user, 'policies.can_be_assignee', true);
    }

    /** Pode editar interações em geral (qualquer escopo != none)? Usado p/ a Descrição (1ª interação). */
    public function canEditActions(?User $user): bool
    {
        if (!$user) return false;
        if ($this->unrestricted($user)) return true;
        return (string) $this->perm($user, 'service.edit_actions', 'last_own') !== 'none';
    }

    /**
     * Pode editar esta interação? Lê 'service.edit_actions' do perfil:
     * none | last_own | last_any | any_own | any_any.
     */
    public function canEditComment(?User $user, \App\Models\HelpDeskTicketComment $comment): bool
    {
        if (!$user) return false;
        if ($this->unrestricted($user)) return true;
        $scope = (string) $this->perm($user, 'service.edit_actions', 'last_own');
        if ($scope === 'none') return false;

        $isOwn  = (int) $comment->author_user_id === (int) $user->id;
        $isLast = \App\Models\HelpDeskTicketComment::where('ticket_id', $comment->ticket_id)->max('id') === $comment->id;

        return match ($scope) {
            'last_own' => $isLast && $isOwn,
            'last_any' => $isLast,
            'any_own'  => $isOwn,
            'any_any'  => true,
            default    => false,
        };
    }

    // ── Aplicação ────────────────────────────────────────────────────────────
    /** Restringe uma query de tickets ao escopo de VISÃO do usuário (por responsável). */
    public function applyViewScope(Builder $q, ?User $user): Builder
    {
        return match ($this->viewScope($user)) {
            'none' => $q->whereRaw('1 = 0'),
            'all'  => $q,
            default => $q->where('assignee_id', $user?->id), // assigned / parent / assigned_or_parent
        };
    }

    // ── Campos: o que pode INFORMAR na abertura (agente ou cliente) ───────────
    public function informAllowed(?User $user, string $field): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, "tickets.inform.$field", true);
    }

    /** Mapa {campo => bool} p/ o FE esconder os campos não permitidos. */
    public function informMap(?User $user, array $fields): array
    {
        $m = [];
        foreach ($fields as $f) $m[$f] = $this->informAllowed($user, $f);
        return $m;
    }

    // ── Campos: o que o CLIENTE vê no ticket (Portal) ─────────────────────────
    public function clientCanView(?User $user, string $field): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, "tickets.view_in.$field", true);
    }

    /**
     * Campo visível p/ o cliente com DEFAULT por campo: sem perfil → usa $default
     * (campos legados visíveis = true; campos novos = false, preservando o comportamento atual).
     */
    public function clientViewField(?User $user, string $field, bool $default): bool
    {
        return $this->unrestricted($user) ? $default : (bool) $this->perm($user, "tickets.view_in.$field", $default);
    }

    public function clientViewMap(?User $user, array $fields): array
    {
        $m = [];
        foreach ($fields as $f) $m[$f] = $this->clientCanView($user, $f);
        return $m;
    }

    // ── Portal do Cliente ─────────────────────────────────────────────────────
    /** Cliente pode abrir chamados? (perfil cliente: tickets.open != none) */
    public function clientCanOpen(?User $user): bool
    {
        return $this->unrestricted($user) ? true : $this->perm($user, 'tickets.open', 'same_org') !== 'none';
    }

    /**
     * Escopo de visão do CLIENTE: own (só os que ele abriu) | same_org (da empresa) | none.
     * Por segurança, "any" (todos de qualquer cliente) degrada para same_org — não expomos
     * tickets de outras empresas a um cliente.
     */
    public function clientViewScope(?User $user): string
    {
        if ($this->unrestricted($user)) return 'same_org';
        $s = (string) $this->perm($user, 'tickets.view_tickets', 'same_org');
        return in_array($s, ['own', 'same_org', 'none'], true) ? $s : 'same_org';
    }

    public function canSee(?User $user, HelpDeskTicket $t): bool
    {
        $s = $this->viewScope($user);
        return $s === 'all' ? true : ($s === 'none' ? false : (int) $t->assignee_id === (int) $user?->id);
    }

    public function canEdit(?User $user, HelpDeskTicket $t): bool
    {
        $s = $this->editScope($user);
        return $s === 'all' ? true : ($s === 'none' ? false : (int) $t->assignee_id === (int) $user?->id);
    }
}
