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

    /** Exibe a coluna "Novo" (tickets ainda não distribuídos) na fila? Default: sim. */
    public function seeNewColumn(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'policies.see_new_column', true);
    }

    /**
     * Busca global: pesquisa QUALQUER chamado (fora do escopo da fila) para abrir e assumir.
     * O agente não vê tickets de outros na fila dele, mas pode encontrá-los pela lupa. Default: sim.
     */
    public function canGlobalSearch(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'policies.global_search', true);
    }

    public function canOpen(?User $user): bool
    {
        return $this->unrestricted($user) ? true : $this->perm($user, 'tickets.open', 'public_and_internal') !== 'none';
    }

    public function canDelete(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'service.delete_tickets', true);
    }

    /** Pode gerar a versão para impressão/PDF do chamado. Default: sim. */
    public function canPrint(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'service.print_ticket', true);
    }

    /** Pode abrir o painel "Detalhes do SLA" (política, prazos, situação). Default: sim. */
    public function canViewSla(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'service.view_sla', true);
    }

    /** Pode clonar um chamado (abrir uma cópia com a mesma classificação). Default: sim. */
    public function canClone(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'service.clone_tickets', true);
    }

    /** Pode enviar e-mail avulso a partir do chamado. Default: sim. */
    public function canSendEmail(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'service.send_email', true);
    }

    /** Recebe sugestões de artigos da Base de Conhecimento na abertura do chamado. Default: sim. */
    public function kbSuggestionsEnabled(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'tickets.kb_suggestions', true);
    }

    /**
     * Escopo p/ candidatar uma interação como artigo da KB: 'none' | 'own' | 'any_any'.
     * Default: none (não permite) — precisa liberar no perfil.
     */
    public function kbCandidateScope(?User $user): string
    {
        if ($this->unrestricted($user)) return 'any_any';
        return (string) ($this->perm($user, 'service.candidate_kb', 'none') ?: 'none');
    }

    /** Pode transformar ESTA interação em artigo (respeita own/any). */
    public function canCandidateKb(?User $user, ?\App\Models\HelpDeskTicketComment $comment = null): bool
    {
        $scope = $this->kbCandidateScope($user);
        if ($scope === 'none') return false;
        if ($scope === 'own' && $comment && (int) $comment->author_user_id !== (int) ($user->id ?? 0)) return false;
        return true;
    }

    /** Pode ver o bloco de CONTRATO (tipo + saldo/banco de horas) no resumo do chamado. Default: sim. */
    public function canViewContract(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'time.view_contract', true);
    }

    /**
     * Escopo do "Ver apontamentos" do chamado: 'all' (todos) ou 'own' (só os do usuário logado).
     * Default: todos. Configurável no perfil de acesso (time.apontamentos_scope).
     */
    public function apontamentosScope(?User $user): string
    {
        if ($this->unrestricted($user)) return 'all';
        return $this->perm($user, 'time.apontamentos_scope', 'all') === 'own' ? 'own' : 'all';
    }

    public function canReopen(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'service.reopen_tickets', true);
    }

    /**
     * Pode ENCERRAR (fechar/cancelar) um chamado — mover para um status terminal.
     * Baseline: coordenador + admin SEMPRE podem. Para os demais (consultor/agente), só se o
     * perfil de acesso liberar explicitamente (service.close_tickets). Assim, por padrão só
     * coordenador e admin têm a opção, mas ela fica configurável no perfil.
     */
    public function canClose(?User $user): bool
    {
        if (!$user) return false;
        if ($user->isAdmin() || $user->isCoordenador() || !$this->profile($user)) return true;
        return (bool) $this->perm($user, 'service.close_tickets', false);
    }

    /** Pode mesclar/desmesclar chamados? */
    public function canMerge(?User $user): bool
    {
        if (!$user) return false;
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'service.merge_tickets', true);
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
        // Interações vindas do CLIENTE (portal ou e-mail) NUNCA são editáveis — nem por admin.
        if ($this->isCustomerComment($comment)) return false;
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

    /** Interação escrita pelo CLIENTE: contato externo (e-mail) ou autor com perfil cliente. */
    private function isCustomerComment(\App\Models\HelpDeskTicketComment $comment): bool
    {
        if ($comment->author_contact_id) return true;
        $author = $comment->relationLoaded('author') ? $comment->author : $comment->author()->first();
        return $author && $author->type === 'cliente';
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
     * Escopo de visão do CLIENTE:
     *   own        — só os que ele abriu (requester = ele)
     *   department — todos os chamados abertos por pessoas do MESMO departamento dele
     *   same_org   — todos os chamados da empresa (customer)
     *   none       — nenhum
     * Por segurança, "any" (todos de qualquer cliente) degrada para same_org — não expomos
     * tickets de outras empresas a um cliente.
     */
    public function clientViewScope(?User $user): string
    {
        if ($this->unrestricted($user)) return 'same_org';
        $s = (string) $this->perm($user, 'tickets.view_tickets', 'same_org');
        return in_array($s, ['own', 'department', 'same_org', 'none'], true) ? $s : 'same_org';
    }

    public function canSee(?User $user, HelpDeskTicket $t): bool
    {
        $s = $this->viewScope($user);
        if ($s === 'all') return true;
        if ($s === 'none') return false;
        if ((int) $t->assignee_id === (int) $user?->id) return true;
        // Fora do escopo da fila, mas a busca global permite ABRIR e ASSUMIR chamados de outros.
        return $this->canGlobalSearch($user);
    }

    public function canEdit(?User $user, HelpDeskTicket $t): bool
    {
        $s = $this->editScope($user);
        return $s === 'all' ? true : ($s === 'none' ? false : (int) $t->assignee_id === (int) $user?->id);
    }
}
