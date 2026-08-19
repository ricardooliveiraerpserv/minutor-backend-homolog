<?php

namespace App\SourceCode;

use App\Models\Customer;
use App\Models\SourceDoc;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * C4a — FONTE ÚNICA DE VERDADE do escopo por cliente da Central de Fontes.
 *
 * Resolve quais clientes um usuário PODE enxergar no acervo de documentação de fonte.
 * Deny-by-default: quem não tem vínculo não vê nada. UI não é segurança — a decisão
 * é sempre no backend, aplicada em query (whereIn), nunca por filtro do frontend.
 *
 * A autorização final de qualquer endpoint = PERMISSÃO DA AÇÃO (PermissionService)
 *                                          + ESCOPO DO CLIENTE (este resolver).
 * Este resolver responde apenas ao ESCOPO. A permissão da ação é checada nos middlewares.
 *
 * Semântica do retorno de accessibleCustomerIds():
 *   null  → GLOBAL (sem restrição de cliente)
 *   []    → NEGA TUDO (usuário escopado, porém sem clientes vinculados)
 *   [ids] → restrito a esses customer_id
 *
 * PRECEDÊNCIA DE SEGURANÇA (ordem obrigatória — não reordenar):
 *   1) Cliente externo  → SOMENTE o próprio cliente. NUNCA global, mesmo que
 *                         tenha admin/permissão/config indevida. Regra de maior precedência.
 *   2) Admin            → global por política administrativa.
 *   3) view_all_customers → global por permissão explícita e auditável.
 *   4) Demais internos  → união dos vínculos REAIS (executivo, coordenador, consultor);
 *                         vazio ⇒ [] (nega).
 */
class SourceDocCustomerScope
{
    /**
     * Memoização POR INSTÂNCIA (chaveada por user id). O container do Laravel recria a
     * instância a cada request, então o memo é naturalmente por-request — não vaza entre
     * requests num worker persistente (o que deixaria escopo obsoleto se os vínculos mudam).
     */
    private array $memo = [];

    /**
     * Clientes acessíveis pelo usuário. Ver semântica na doc da classe.
     */
    public function accessibleCustomerIds(User $user): ?array
    {
        if (array_key_exists($user->id, $this->memo)) {
            return $this->memo[$user->id];
        }

        return $this->memo[$user->id] = $this->compute($user);
    }

    /**
     * true se o usuário enxerga o acervo de TODOS os clientes (sem restrição).
     */
    public function isGlobal(User $user): bool
    {
        return $this->accessibleCustomerIds($user) === null;
    }

    /**
     * true se o usuário pode acessar o cliente informado.
     * customer_id nulo em recurso escopado ⇒ só GLOBAL enxerga (deny-by-default).
     */
    public function canAccessCustomerId(User $user, ?int $customerId): bool
    {
        $ids = $this->accessibleCustomerIds($user);

        if ($ids === null) {
            return true; // global
        }

        if ($customerId === null || $ids === []) {
            return false;
        }

        return in_array((int) $customerId, $ids, true);
    }

    /**
     * true se o usuário pode acessar a ficha/versões/ações de um SourceDoc.
     */
    public function canAccessDoc(User $user, SourceDoc $doc): bool
    {
        return $this->canAccessCustomerId($user, $doc->customer_id !== null ? (int) $doc->customer_id : null);
    }

    /**
     * Aplica o escopo a uma query, em SQL (sem filtragem em memória).
     * $column deve ser a coluna customer_id da tabela/alias sob escopo, ex:
     *   'source_docs.customer_id', 'source_doc_entities.customer_id', 'source_doc_index.customer_id'.
     *
     * @template TBuilder of EloquentBuilder|QueryBuilder
     * @param TBuilder $query
     * @return TBuilder
     */
    public function applyScope($query, User $user, string $column)
    {
        $ids = $this->accessibleCustomerIds($user);

        if ($ids === null) {
            return $query; // global — sem restrição
        }

        if ($ids === []) {
            return $query->whereRaw('1 = 0'); // nega tudo determinístico
        }

        return $query->whereIn($column, $ids);
    }

    /**
     * Esconde das consultas os repositórios DESABILITADOS na Central (source_doc_repo_settings.hidden).
     * Chave = (customer_id, repository). NÃO mexe na ingestão (o repo continua sendo clonado/reprocessado);
     * só some das telas. Passe $includeHidden=true (tela de gestão / toggle "Mostrar desabilitados").
     * $alias = tabela/alias que expõe customer_id + repository (ex.: 'source_docs', 'd', 'dep').
     *
     * @template TBuilder of EloquentBuilder|QueryBuilder
     * @param TBuilder $query
     * @return TBuilder
     */
    public function applyRepoVisibility($query, string $alias = 'source_docs', bool $includeHidden = false)
    {
        if ($includeHidden) {
            return $query;
        }

        return $query->whereNotExists(function ($sub) use ($alias) {
            $sub->from('source_doc_repo_settings as rs')
                ->whereColumn('rs.customer_id', "$alias.customer_id")
                ->whereColumn('rs.repository', "$alias.repository")
                ->where('rs.hidden', true);
        });
    }

    /**
     * Limpa a memoização desta instância (útil se os vínculos mudarem no mesmo request).
     */
    public function flush(): void
    {
        $this->memo = [];
    }

    // ────────────────────────────────────────────────────────────────────────

    private function compute(User $user): ?array
    {
        // (1) CLIENTE EXTERNO — precedência máxima de segurança.
        //     Qualquer usuário vinculado a um cliente (customer_id) OU do tipo 'cliente'
        //     enxerga SOMENTE o próprio cliente e NUNCA recebe escopo global,
        //     independentemente de admin/permissão/configuração indevida.
        if ($user->isCustomerUser() || $user->isCliente()) {
            return $user->customer_id ? [(int) $user->customer_id] : [];
        }

        // (2) ADMIN — global por política administrativa.
        if ($user->isAdmin()) {
            return null;
        }

        // (3) view_all_customers — global por permissão explícita e auditável.
        if ($user->hasAccess('source_docs.view_all_customers')) {
            return null;
        }

        // (4) Demais internos — união dos vínculos reais. Deny-by-default se vazio.
        return $this->unionOfRealLinks($user);
    }

    /**
     * União (em SQL) dos clientes vinculados ao usuário interno:
     *   - executivo de conta do cliente (Customer::activeExecutiveColumn())
     *   - coordenador de projeto (pivot project_coordinators)
     *   - consultor de projeto (pivot project_consultants)
     *   - consultor via grupo (consultant_group_user × project_consultant_groups)
     *   - alocação em etapa (stage_allocations → project_stages → projects)
     *   - responsável por entrega de etapa (stage_deliveries → project_stages → projects)
     */
    private function unionOfRealLinks(User $user): array
    {
        $ids = collect();

        // Executivo de conta — vínculo direto no cadastro do cliente.
        $ids = $ids->merge(
            Customer::query()
                ->where(Customer::activeExecutiveColumn(), $user->id)
                ->pluck('id')
        );

        // Coordenador de projeto.
        $ids = $ids->merge(
            DB::table('project_coordinators')
                ->join('projects', 'project_coordinators.project_id', '=', 'projects.id')
                ->where('project_coordinators.user_id', $user->id)
                ->pluck('projects.customer_id')
        );

        // Consultor de projeto (vínculo direto).
        $ids = $ids->merge(
            DB::table('project_consultants')
                ->join('projects', 'project_consultants.project_id', '=', 'projects.id')
                ->where('project_consultants.user_id', $user->id)
                ->pluck('projects.customer_id')
        );

        // Consultor via grupo de consultores.
        $ids = $ids->merge(
            DB::table('consultant_group_user')
                ->join('project_consultant_groups', 'consultant_group_user.consultant_group_id', '=', 'project_consultant_groups.consultant_group_id')
                ->join('projects', 'project_consultant_groups.project_id', '=', 'projects.id')
                ->where('consultant_group_user.user_id', $user->id)
                ->pluck('projects.customer_id')
        );

        // Alocação em etapa.
        $ids = $ids->merge(
            DB::table('stage_allocations')
                ->join('project_stages', 'stage_allocations.stage_id', '=', 'project_stages.id')
                ->join('projects', 'project_stages.project_id', '=', 'projects.id')
                ->where('stage_allocations.user_id', $user->id)
                ->pluck('projects.customer_id')
        );

        // Responsável por entrega de etapa.
        $ids = $ids->merge(
            DB::table('stage_deliveries')
                ->join('project_stages', 'stage_deliveries.stage_id', '=', 'project_stages.id')
                ->join('projects', 'project_stages.project_id', '=', 'projects.id')
                ->where('stage_deliveries.responsible_user_id', $user->id)
                ->pluck('projects.customer_id')
        );

        return $ids
            ->filter()                       // remove null/0
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
