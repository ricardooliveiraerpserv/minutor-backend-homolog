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

    /**
     * Sem restrição APENAS quando não há perfil vinculado (compat/rollout) ou sem usuário.
     * ⚠️ Admin NÃO é mais bypass: se um perfil de acesso estiver vinculado ao usuário, ele
     * governa — inclusive para admin (pedido: "até o admin deve seguir o perfil dedicado a ele").
     * Para o admin mandar em tudo, deixá-lo SEM perfil vinculado (ou com um perfil liberado).
     */
    private function unrestricted(?User $user): bool
    {
        return !$user || !$this->profile($user);
    }

    // ── Escopos ────────────────────────────────────────────────────────────
    public function viewScope(?User $user): string
    {
        return $this->unrestricted($user) ? 'all' : (string) $this->perm($user, 'policies.view_tickets', 'own');
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

    /** Card/filtro "Triagem" (chamados sem responsável) na fila — liberado por perfil (opt-in). */
    public function canTriage(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'tickets.triagem', false);
    }

    /** Pode CRIAR visualizações pessoais (salvar filtros da fila). Default: sim. */
    public function canCreatePersonalViews(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'tickets.personal_views', true);
    }

    /** Pode CRIAR/EDITAR visualizações compartilhadas (todos os agentes). Default: sim. */
    public function canCreateSharedViews(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'tickets.shared_views', true);
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

    /**
     * Atualização em massa (barra de seleção da lista de chamados). Gate em 2 níveis:
     * o master `tickets.bulk_actions` + a ação específica `tickets.bulk.{action}`.
     * $action ∈ delete|level|service|category|urgency|responsible. Default: liberado.
     */
    public function canBulk(?User $user, string $action): bool
    {
        if ($this->unrestricted($user)) return true;
        if (!(bool) $this->perm($user, 'tickets.bulk_actions', true)) return false;
        return (bool) $this->perm($user, 'tickets.bulk.' . $action, true);
    }

    /** Mapa das ações em massa permitidas (para o FE montar a barra). */
    public function bulkPermsMap(?User $user): array
    {
        $master = $this->unrestricted($user) ? true : (bool) $this->perm($user, 'tickets.bulk_actions', true);
        $m = ['enabled' => $master];
        foreach (['delete', 'level', 'service', 'category', 'urgency', 'responsible'] as $a) {
            $m[$a] = $master && $this->canBulk($user, $a);
        }
        return $m;
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

    /** Cliente pode abrir chamado EM NOME DE outra pessoa (contato) da mesma empresa. */
    public function clientOpenOnBehalf(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'tickets.open_on_behalf', false);
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

    /** Pode ver o RESUMO do contrato dentro do chamado (bloco de horas/consumo). Default: sim. */
    public function canViewContractSummary(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'time.contract_summary', true);
    }

    /** Tela padrão após o logon (perfil de acesso): 'principal' | 'tickets'. Default: principal. */
    public function defaultScreen(?User $user): string
    {
        if ($this->unrestricted($user)) return 'principal';
        return (string) $this->perm($user, 'general.default_screen', 'principal') === 'tickets' ? 'tickets' : 'principal';
    }

    /** Vê o GRÁFICO de consumo de horas do contrato (barra banco de horas). Default: sim. */
    public function canViewContractChart(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'time.contract_chart', true);
    }

    /** Pode ver VALORES trabalhados (R$ / taxa) no chamado. Default: sim. */
    public function canSeeWorkedValues(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'time.see_worked_values', true);
    }

    /** Pode editar o PRÓPRIO perfil (conta: nome/senha/assinatura). Default: sim. */
    public function canEditOwnProfile(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'general.edit_own_profile', true);
    }

    /** Vê a sinalização de COLISÃO (quem mais está no chamado). Default: sim. */
    public function canSeeCollision(?User $user): bool
    {
        if ($user && $user->type === 'admin') return true; // admin sempre enxerga a colisão (quem mais está no chamado)
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'service.collision', true);
    }

    /** Acesso a TODO o catálogo de serviços. Off = só os marcados "visível ao agente". Default: sim. */
    public function canSeeAllCatalog(?User $user): bool
    {
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'policies.all_catalog', true);
    }

    /** Tipo PADRÃO da nova ação em chamados: 'public' | 'internal'. Default: public. */
    public function defaultActionType(?User $user): string
    {
        if ($this->unrestricted($user)) return 'public';
        return (string) $this->perm($user, 'service.default_action', 'public') === 'internal' ? 'internal' : 'public';
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
        // Sem perfil vinculado: baseline (admin + coordenador podem). Com perfil: segue o perfil (inclusive admin).
        if (!$this->profile($user)) return $user->isAdmin() || $user->isCoordenador();
        return (bool) $this->perm($user, 'service.close_tickets', false);
    }

    /** Pode mesclar/desmesclar chamados? */
    public function canMerge(?User $user): bool
    {
        if (!$user) return false;
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'service.merge_tickets', true);
    }

    /** Pode ALTERAR a empresa (cliente) do chamado? Opt-in pelo perfil de acesso (default: não). */
    public function canChangeCustomer(?User $user): bool
    {
        if (!$user) return false;
        return $this->unrestricted($user) ? true : (bool) $this->perm($user, 'policies.change_customer', false);
    }

    public function canBeAssignee(?User $user): bool
    {
        if (!$user) return false;
        return !$this->profile($user) ? true : (bool) $this->perm($user, 'policies.can_be_assignee', true);
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
            // Escopo "atribuídos" + os PRÓPRIOS chamados (que o agente abriu ou é solicitante),
            // para ele acompanhar o que abriu mesmo sem ser o responsável.
            default => $q->where(fn ($w) => $w
                ->where('assignee_id', $user?->id)
                ->orWhere('created_by_id', $user?->id)
                ->orWhere('requester_user_id', $user?->id)),
        };
    }

    // ── Escopo de EMPRESAS (multi-empresa no Help Desk) ───────────────────────
    /**
     * Empresas (company_id) que o usuário ATENDE (agente) ou ACESSA (cliente, abas do portal).
     * FONTE DE VERDADE: empresas vinculadas ao usuário no CADASTRO (company_user) — definidas na
     * aba Help Desk do cadastro de usuários. Vale para agente E cliente.
     * Fallbacks (compat/rollout, só quando o cadastro ainda não tem empresas):
     *  - perfil de acesso com policies.companies definido → essa lista;
     *  - perfil sem a lista → a empresa do próprio perfil;
     *  - sem perfil → a empresa atual do usuário.
     */
    public function companiesScope(?User $user): array
    {
        if (!$user) return [];
        // 1) Cadastro do usuário (company_user) manda — para agente e cliente.
        $own = $user->companies()->pluck('companies.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if ($own) return $own;
        // 2) Fallback: perfil de acesso (compat enquanto o cadastro não define as empresas).
        $p = $this->profile($user);
        if ($p) {
            $list = is_array($p->permissions) ? ($p->permissions['policies.companies'] ?? null) : null;
            if (is_array($list) && count($list)) {
                return array_values(array_unique(array_map('intval', $list)));
            }
            if ((int) $p->company_id) return [(int) $p->company_id];
        }
        // 3) Último recurso: empresa atual do usuário.
        return array_values(array_filter([(int) $user->current_company_id]));
    }

    /** Atende (pode ver/atuar em) tickets desta empresa? */
    public function attendsCompany(?User $user, ?int $companyId): bool
    {
        if (!$companyId) return true;
        $scope = $this->companiesScope($user);
        return empty($scope) || in_array((int) $companyId, $scope, true);
    }

    /** A fila mostra o selo de empresa? (perfil atende 2+ empresas → visão unificada) */
    public function isMultiCompany(?User $user): bool
    {
        return count($this->companiesScope($user)) > 1;
    }

    /**
     * Aplica o escopo de EMPRESAS do perfil à query de tickets: desliga o scope de empresa
     * ÚNICA (global) e filtra pelas empresas que o agente atende. Com 1 empresa mostra só ela;
     * com 2+ traz tudo unificado (ignora o seletor de empresa do topo).
     */
    public function applyCompanyScope(Builder $q, ?User $user): Builder
    {
        $ids = $this->companiesScope($user);
        if (empty($ids)) return $q; // não resolveu escopo → mantém o comportamento padrão
        $table = $q->getModel()->getTable();
        return $q->withoutCompanyScope()->whereIn($table . '.company_id', $ids);
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
        // FAIL-SAFE (LGPD): na dúvida, o cliente vê SÓ os próprios chamados ('own'), nunca "tudo".
        if (!$user) return 'none';
        if ($user->isAdmin()) return 'same_org';           // admin (não usa o portal, mas por garantia)
        if (!$this->profile($user)) return 'own';           // cliente SEM perfil → só os dele
        $s = (string) $this->perm($user, 'tickets.view_tickets', 'own'); // default restritivo
        if ($s === 'any') return 'same_org';                // 'any' no portal = tudo da PRÓPRIA empresa (nunca cross-customer)
        return in_array($s, ['own', 'department', 'same_org', 'none'], true) ? $s : 'own';
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
