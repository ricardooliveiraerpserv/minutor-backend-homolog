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
        // Multi-empresa: perfil EFETIVO (papel na empresa ativa) — flag off/sem empresa → users.type.
        $base = match ($user->effectiveType()) {
            'admin'          => self::adminPermissions(),
            'administrativo' => self::administrativoPermissions(),
            'coordenador'    => self::coordenadorPermissions(),
            'consultor'      => self::consultorPermissions(),
            'cliente'        => self::clientePermissions(),
            'parceiro_admin' => self::parceiroAdminPermissions(),
            default          => [],
        };

        // Coordenador de Projetos: acesso ao Cadastro de Usuários restrito a
        // RESETAR SENHA (precisa de view_all pra enxergar a lista e do
        // reset_password pro endpoint). Demais ações ficam gateadas na tela /users.
        if ($user->effectiveType() === 'coordenador' && $user->coordinator_type === 'projetos') {
            $base = array_merge($base, ['users.view_all', 'users.reset_password']);
        }

        // Executivo de conta: aprova apontamentos/despesas de Investimento Comercial dos seus
        // clientes — precisa acessar a tela de Aprovações. Fonte da verdade é o vínculo
        // customers.executive_id (não o flag is_executive, que nem sempre está setado).
        // A visibilidade fica restrita no ApprovalController (só os registros de investimento
        // Comercial onde ele é o executivo).
        if ($user->is_executive || \App\Models\Customer::where('executive_id', $user->id)->exists()) {
            $base = array_merge($base, ['approvals.view', 'timesheets.approve', 'expenses.approve']);
        }

        // Admin já tem tudo — extras são irrelevantes
        if (in_array('*', $base, true)) {
            return $base;
        }

        $extra = $user->extra_permissions ?? [];

        // Permissões dos grupos vinculados ao usuário
        $groupPermissions = [];
        if ($user->relationLoaded('permissionGroups')) {
            foreach ($user->permissionGroups as $group) {
                $groupPermissions = array_merge($groupPermissions, $group->permissions ?? []);
            }
        } else {
            $groupPermissions = $user->permissionGroups()
                ->get()
                ->pluck('permissions')
                ->flatten()
                ->all();
        }

        return array_values(array_unique(array_merge($base, $extra, $groupPermissions)));
    }

    /**
     * Lista MASTER de todas as permissões existentes no sistema, mesmo que
     * não façam parte da base de nenhum perfil — usadas como "extras" via
     * usuário ou grupo. Esta é a fonte de verdade exposta na UI de grupos.
     */
    private const ALL_AVAILABLE = [
        // Dashboard
        'dashboard.view', 'dashboard.manager', 'dashboard.consultant',
        // Dashboards (granular por tipo de contrato)
        'dashboards.view', 'dashboards.bank_hours_fixed.view',
        'dashboards.bank_hours_monthly.view', 'dashboards.on_demand.view',
        'dashboards.fechado.view',
        // Banco de Horas
        'hora_banco.view',
        // Contratos
        'contracts.view', 'contracts.manage',
        // Projetos
        'projects.view', 'projects.create', 'projects.update', 'projects.delete',
        'projects.assign_consultants', 'projects.change_status', 'projects.view_financial',
        // Gestão de Projetos (acesso ao módulo Gestão de Projetos)
        'gestao_projetos.view', 'gestao_projetos.update',
        // Fechamento
        'fechamento.view', 'fechamento.manage', 'fechamento.fechar', 'fechamento.reabrir',
        // Apontamentos
        'timesheets.view', 'timesheets.manage', 'timesheets.approve',
        'timesheets.view_project_full', 'timesheets.view_project_summary',
        'hours.view_all', 'hours.update_all', 'hours.delete_all',
        // Despesas
        'expenses.view', 'expenses.manage', 'expenses.approve', 'expenses.reject',
        'expenses.view_all', 'expenses.pay',
        // Aprovações
        'approvals.view', 'approvals.manage',
        // Clientes
        'customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.manage',
        // Cadastros (acesso ao CRUD de cada cadastro)
        'services.manage', 'executives.manage', 'groups.manage', 'holidays.manage',
        'expense_categories.manage', 'expense_types.manage', 'payment_methods.manage',
        'consultant_groups.view',
        // Follow Up (pendências/compromissos)
        'followups.view', 'followups.view_all', 'followups.manage',
        // CRM (Fase 1)
        'crm.view', 'crm.manage', 'crm.leads.view', 'crm.leads.manage',
        'crm.opportunities.view', 'crm.opportunities.view_all',
        'crm.opportunities.manage', 'crm.proposals.manage', 'crm.products.manage',
        'crm.pipeline.manage', 'crm.convert', 'crm.reports.view', 'crm.custom_fields.manage',
        // Parceiros
        'partners.view', 'partners.manage',
        // Usuários
        'users.view', 'users.view_all', 'users.create', 'users.update', 'users.delete',
        'users.reset_password', 'users.view_own_profile', 'users.update_own_profile',
        'users.view_team',
        // Financeiro
        'financial.view_own_rate', 'financial.view_partner_rate', 'financial.view_project_cost',
        // Relatórios
        'reports.view', 'reports.export',
        // CRM (Fase 1)
        'crm.view', 'crm.manage', 'crm.leads.view', 'crm.leads.manage',
        'crm.opportunities.view', 'crm.opportunities.view_all',
        'crm.opportunities.manage', 'crm.proposals.manage', 'crm.products.manage',
        'crm.pipeline.manage', 'crm.convert', 'crm.reports.view', 'crm.custom_fields.manage',
        // Help Desk — acende o módulo no menu (sidebar). O acesso ao chamado em si
        // é decidido pelo HelpDeskAccessPolicy, não por esta chave.
        'help_desk.tickets.view',
        // Banco de Competências
        'competencias.view', 'competencias.manage', 'competencias.view_team',
        'competencias.respond', 'competencias.reports.view',
        // Sistema
        'settings.view',
        // Cofre de Senhas (zero-knowledge; só equipe interna)
        'vault.use', 'vault.audit.view',
        // Cofre de Ambientes (infra Protheus; só equipe interna)
        'environments.use', 'environments.admin', 'environments.audit.view',
        // Prosight C3 — Ambientes: projeção READ-ONLY segura do Cofre por customer_id.
        // NÃO concede acesso ao Cofre/reveal/secrets; só a projeção allowlist. Cliente externo NÃO recebe.
        'prosight.environments.view',
        // Solicitação de código-fonte (Help Desk) — configurável por perfil/usuário/grupo.
        // manage=cadastrar fontes Git do cliente · request=solicitar fonte · reindex=atualizar índice.
        'source_code.manage', 'source_code.request', 'source_code.reindex',
        // Central de Fontes (C1) — acesso ao catálogo de documentação de fonte (leitura).
        'source_docs.view',
        // Central de Fontes (C3) — ações operacionais, uma permissão por ação.
        'source_docs.validate', 'source_docs.reprocess', 'source_docs.download',
        'source_docs.view_git', 'source_docs.view_diff',
        // Central de Fontes — Análise de Qualidade (CodeAnalysis). view=consultar resultado;
        // run=disparar nova análise (run NÃO implica acesso global; escopo por customer sempre vale).
        'source_docs.quality.view', 'source_docs.quality.run',
        // Central de Fontes (C3.5) — inventário/cobertura do acervo (Admin/ops).
        'source_docs.inventory',
        // GMUD — Publicação Governada de Fontes (wizard). Recebe ZIP, extrai, casa com o acervo e
        // (G7) publica com aceite explícito. Gate do fluxo governado; interno ERPSERV (Admin via '*').
        'source_docs.gmud_publish',
        // Central de Fontes (C4a) — governança/escopo multi-tenant.
        // view_all_customers = enxergar o acervo de TODOS os clientes por permissão explícita
        //   (auditável; NÃO se aplica a cliente externo — regra de segurança tem precedência).
        // view_cross_customer = RESERVADA para C4b (impacto multi-cliente); ainda sem efeito.
        'source_docs.view_all_customers',
        'source_docs.view_cross_customer',
        // Campanha de Documentação Semântica Inicial (baseline). Admin-only na prática (via '*').
        'source_docs.semantic_campaign',
        // Frente A — Governança de custo de IA (config + fila de aprovação). Interno ERPSERV (Admin via '*').
        'source_docs.cost_settings.view', 'source_docs.cost_settings.manage',
        'source_docs.cost_approval.view', 'source_docs.cost_approval.decide',
    ];

    /**
     * Retorna todas as permissões disponíveis para exibição na UI.
     * Não inclui '*' (admin) — é usado apenas para selecionar extras.
     */
    public static function allPermissions(): array
    {
        return self::ALL_AVAILABLE;
    }

    // ── Administrator ────────────────────────────────────────────────────────
    private static function adminPermissions(): array
    {
        return ['*'];
    }

    // ── Administrativo ───────────────────────────────────────────────────────
    private static function administrativoPermissions(): array
    {
        return [
            'dashboard.view',
            // Contratos / Kanban
            'contracts.view', 'contracts.manage',
            'projects.view', 'projects.assign_consultants', 'projects.change_status',
            // Fechamento completo
            'fechamento.view', 'fechamento.manage', 'fechamento.fechar', 'fechamento.reabrir',
            // Apontamentos
            'timesheets.view', 'timesheets.manage', 'timesheets.approve', 'timesheets.view_project_full',
            'hours.view_all', 'hours.update_all', 'hours.delete_all',
            // Despesas — incluindo pagamento e estorno
            'expenses.view', 'expenses.manage', 'expenses.approve', 'expenses.reject', 'expenses.view_all', 'expenses.pay',
            // Clientes — acesso total
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
            'customers.manage',
            // Parceiros — acesso total (filtro + dropdown "Empresa parceira" no cadastro de usuários)
            'partners.view', 'partners.manage',
            // Usuários — SÓ ver a lista + resetar senha. create/update/delete REMOVIDOS: a tela
            // /users já força reset-only p/ administrativo; antes o backend concedia CRUD total
            // (a API aceitava criar/editar/excluir usuário mesmo com os botões escondidos).
            'users.view', 'users.view_all',
            'users.reset_password', 'users.view_own_profile', 'users.update_own_profile',
            // Aprovações
            'approvals.view', 'approvals.manage',
            // CRM (gestão comercial)
            'crm.view', 'crm.manage', 'crm.leads.view', 'crm.leads.manage',
            'crm.opportunities.view', 'crm.opportunities.view_all',
            'crm.opportunities.manage', 'crm.proposals.manage', 'crm.products.manage',
            'crm.pipeline.manage', 'crm.convert', 'crm.reports.view', 'crm.custom_fields.manage',
            // Relatórios
            'reports.view', 'reports.export',
            // Banco de Competências (gestão completa)
            'competencias.view', 'competencias.manage', 'competencias.view_team',
            'competencias.respond', 'competencias.reports.view',
            // Configurações
            'settings.view',
                    // CRM (gestão comercial)
            'crm.view', 'crm.manage', 'crm.leads.view', 'crm.leads.manage',
            'crm.opportunities.view', 'crm.opportunities.view_all',
            'crm.opportunities.manage', 'crm.proposals.manage', 'crm.products.manage',
            'crm.pipeline.manage', 'crm.convert', 'crm.reports.view', 'crm.custom_fields.manage',
            // Cofre de Senhas
            'vault.use',
            // Cofre de Ambientes
            'environments.use',
            // Prosight — Ambientes (projeção segura read-only, escopada por cliente)
            'prosight.environments.view',
        ];
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
            // Cadastro de feriados: alimenta o calendário do cronograma (rotina do coordenador).
            'holidays.manage',
            // Follow Up: coordenador vê/gerencia os do seu contexto.
            'followups.view', 'followups.manage',
            // Banco de Competências: consulta competências da equipe + responde a própria.
            'competencias.view', 'competencias.view_team', 'competencias.respond',
            // Cofre de Senhas
            'vault.use',
            'environments.use',
            // Prosight — Ambientes (projeção segura read-only, escopada por cliente)
            'prosight.environments.view',
            // Central de Fontes (C1/C3) — coordenador vê o catálogo e usa ações NÃO-destrutivas.
            // reprocess fica SÓ p/ Admin no MVP (única ação que cria versão/consome IA).
            'source_docs.view', 'source_docs.validate', 'source_docs.download',
            'source_docs.view_git', 'source_docs.view_diff',
            // Qualidade: coordenador CONSULTA; disparar (run) fica p/ Admin no MVP (consome serviço externo).
            'source_docs.quality.view',
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
            // Follow Up: consultor vê/gerencia os atribuídos a ele.
            'followups.view', 'followups.manage',
            // Help Desk: acende o item no menu do consultor (sidebar gateia por esta chave).
            // NÃO amplia dado: o que ele enxerga de chamado segue no HelpDeskAccessPolicy.
            'help_desk.tickets.view',
            // Banco de Competências: responde a própria pesquisa.
            'competencias.respond',
            // Cofre de Senhas
            'vault.use',
            'environments.use',
            // Prosight — Ambientes (projeção segura read-only, escopada por cliente)
            'prosight.environments.view',
        ];
    }

    // ── Cliente ──────────────────────────────────────────────────────────────
    private static function clientePermissions(): array
    {
        return [
            'dashboard.view',
            'dashboards.view',
            'dashboards.bank_hours_fixed.view',
            'dashboards.bank_hours_monthly.view',
            'dashboards.on_demand.view',
            'projects.view',
            'timesheets.view', 'timesheets.view_project_summary',
            'expenses.view',
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
            'customers.view',
            'users.view_own_profile', 'users.update_own_profile', 'users.view_team',
            'users.create', 'users.update', 'users.reset_password',
            'financial.view_partner_rate',
            'partners.view',
            'reports.view',
        ];
    }
}
