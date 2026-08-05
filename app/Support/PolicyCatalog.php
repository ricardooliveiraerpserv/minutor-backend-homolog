<?php

namespace App\Support;

/**
 * CATÁLOGO DE POLÍTICAS — fonte única de verdade das capacidades por módulo.
 * Nenhuma tela nem controller hardcoda permissão: todos leem daqui. Adicionar uma
 * capacidade = editar este catálogo (sem migração de schema, pois os valores moram
 * em JSON no perfil/exceção). O primeiro módulo é o CRM ("Política Comercial").
 *
 * Controles:
 *   scope   → botão segmentado. options: all|team|own|assigned|none
 *   toggle  → liga/desliga (bool)
 *
 * A ordem de "amplitude" dos escopos (para resolução por menos-permissivo e bypass admin):
 *   none < own < assigned < team < all
 */
class PolicyCatalog
{
    public const SCOPE_ORDER = ['none' => 0, 'own' => 1, 'assigned' => 2, 'team' => 3, 'all' => 4];

    /** Blocos e capacidades por módulo (monta a tela + valida chaves). */
    public static function blocks(string $module): array
    {
        return self::CATALOG[$module]['blocks'] ?? [];
    }

    /** Todas as chaves válidas de um módulo. */
    public static function keys(string $module): array
    {
        $keys = [];
        foreach (self::blocks($module) as $b) {
            foreach ($b['caps'] as $c) $keys[$c['key']] = $c;
        }
        return $keys;
    }

    /** Valor "máximo" de uma chave (usado no bypass do admin e como teto). */
    public static function maxValue(array $cap)
    {
        return $cap['control'] === 'toggle' ? true : 'all';
    }

    /** Valor de fallback quando não há perfil nem exceção (padrão seguro). */
    public static function fallback(array $cap)
    {
        return $cap['fallback'] ?? ($cap['control'] === 'toggle' ? false : 'none');
    }

    /** Perfis de sistema semeados por empresa, com seus padrões (a Matriz do PRD). */
    public static function systemRoles(string $module): array
    {
        return self::ROLES[$module] ?? [];
    }

    // ── CRM ─────────────────────────────────────────────────────────────────
    private const CATALOG = [
        'crm' => [
            'label' => 'Política Comercial',
            'blocks' => [
                ['id' => 'pipelines_leads', 'label' => 'Pipelines & Leads', 'caps' => [
                    ['key' => 'pipelines.view', 'label' => 'Funis visíveis', 'control' => 'scope', 'options' => ['all', 'assigned', 'none'], 'help' => 'Quais pipelines o usuário opera. "Atribuídos" usa a liberação por funil.'],
                    ['key' => 'leads.view', 'label' => 'Ver leads', 'control' => 'scope', 'options' => ['all', 'team', 'own', 'none'], 'help' => 'Universo de leads visível na fila e na carteira.'],
                ]],
                ['id' => 'oportunidades', 'label' => 'Oportunidades', 'caps' => [
                    ['key' => 'opp.view', 'label' => 'Visualizar', 'control' => 'scope', 'options' => ['all', 'team', 'own', 'none'], 'help' => 'Universo de oportunidades visível.'],
                    ['key' => 'opp.edit', 'label' => 'Editar', 'control' => 'scope', 'options' => ['all', 'team', 'own', 'none'], 'help' => 'Onde pode alterar campos/etapa. Nunca maior que Visualizar.'],
                    ['key' => 'opp.reassign', 'label' => 'Alterar responsável', 'control' => 'toggle', 'help' => 'Reatribuir o dono comercial (afeta comissão).'],
                    ['key' => 'opp.win', 'label' => 'Marcar como Ganha', 'control' => 'toggle', 'help' => 'Fechar como ganha (dispara conversão em contrato).'],
                    ['key' => 'opp.lose', 'label' => 'Marcar como Perdida', 'control' => 'toggle', 'help' => 'Fechar como perdida (com motivo).'],
                    ['key' => 'opp.delete', 'label' => 'Excluir oportunidade', 'control' => 'toggle', 'danger' => true, 'help' => 'Ação destrutiva — nasce desligada.'],
                    ['key' => 'proposals.create', 'label' => 'Gerar propostas', 'control' => 'toggle', 'help' => 'Criar/versão de proposta comercial.'],
                ]],
                ['id' => 'clientes', 'label' => 'Clientes', 'caps' => [
                    ['key' => 'customers.view', 'label' => 'Ver clientes', 'control' => 'scope', 'options' => ['all', 'team', 'own', 'none'], 'help' => 'Empresas visíveis no cadastro e na Ficha 360.'],
                ]],
                ['id' => 'financeiro', 'label' => 'Financeiro', 'caps' => [
                    ['key' => 'values.view', 'label' => 'Ver valores', 'control' => 'toggle', 'sensitive' => true, 'help' => 'Exibir R$ das oportunidades; oculto = mascarado.'],
                    ['key' => 'commission.view', 'label' => 'Ver comissão', 'control' => 'scope', 'options' => ['all', 'team', 'own', 'none'], 'sensitive' => true, 'help' => 'Comissão calculada — dado sensível.'],
                    ['key' => 'goals.view', 'label' => 'Ver metas', 'control' => 'scope', 'options' => ['all', 'team', 'own', 'none'], 'sensitive' => true, 'help' => 'Metas e atingimento.'],
                    ['key' => 'profit.view', 'label' => 'Ver rentabilidade', 'control' => 'toggle', 'sensitive' => true, 'help' => 'Margem/custo do negócio.'],
                ]],
                ['id' => 'painel', 'label' => 'Dashboards & Indicadores', 'caps' => [
                    ['key' => 'dashboards.view', 'label' => 'Dashboards', 'control' => 'scope', 'options' => ['all', 'team', 'own', 'none'], 'help' => 'Painéis agregados acessíveis.'],
                    ['key' => 'indicators.view', 'label' => 'Indicadores', 'control' => 'scope', 'options' => ['all', 'team', 'own', 'none'], 'help' => 'KPIs comerciais visíveis.'],
                ]],
                ['id' => 'relatorios', 'label' => 'Relatórios', 'caps' => [
                    ['key' => 'reports.export', 'label' => 'Exportar relatórios', 'control' => 'toggle', 'help' => 'Extrair dados (CSV/PDF) — vetor de vazamento.'],
                ]],
                ['id' => 'admin', 'label' => 'Administração', 'caps' => [
                    ['key' => 'policy.manage', 'label' => 'Configurar políticas', 'control' => 'toggle', 'help' => 'Administrar perfis e exceções desta camada.'],
                ]],
            ],
        ],
    ];

    // Padrões dos perfis de sistema (a Matriz da Parte 6). Personalizado nasce vazio.
    private const ROLES = [
        'crm' => [
            ['name' => 'Administrador', 'sort' => 1, 'defaults' => [
                'pipelines.view' => 'all', 'leads.view' => 'all', 'opp.view' => 'all', 'opp.edit' => 'all',
                'opp.reassign' => true, 'opp.win' => true, 'opp.lose' => true, 'opp.delete' => true, 'proposals.create' => true,
                'customers.view' => 'all', 'values.view' => true, 'commission.view' => 'all', 'goals.view' => 'all', 'profit.view' => true,
                'dashboards.view' => 'all', 'indicators.view' => 'all', 'reports.export' => true, 'policy.manage' => true,
            ]],
            ['name' => 'Diretor Comercial', 'sort' => 2, 'defaults' => [
                'pipelines.view' => 'all', 'leads.view' => 'all', 'opp.view' => 'all', 'opp.edit' => 'team',
                'opp.reassign' => true, 'opp.win' => true, 'opp.lose' => true, 'opp.delete' => false, 'proposals.create' => true,
                'customers.view' => 'all', 'values.view' => true, 'commission.view' => 'all', 'goals.view' => 'all', 'profit.view' => true,
                'dashboards.view' => 'all', 'indicators.view' => 'all', 'reports.export' => true, 'policy.manage' => false,
            ]],
            ['name' => 'Gerente Comercial', 'sort' => 3, 'defaults' => [
                'pipelines.view' => 'all', 'leads.view' => 'team', 'opp.view' => 'team', 'opp.edit' => 'team',
                'opp.reassign' => true, 'opp.win' => true, 'opp.lose' => true, 'opp.delete' => false, 'proposals.create' => true,
                'customers.view' => 'team', 'values.view' => true, 'commission.view' => 'team', 'goals.view' => 'team', 'profit.view' => false,
                'dashboards.view' => 'team', 'indicators.view' => 'team', 'reports.export' => true, 'policy.manage' => false,
            ]],
            ['name' => 'Executivo Comercial', 'sort' => 4, 'defaults' => [
                'pipelines.view' => 'assigned', 'leads.view' => 'own', 'opp.view' => 'own', 'opp.edit' => 'own',
                'opp.reassign' => false, 'opp.win' => true, 'opp.lose' => true, 'opp.delete' => false, 'proposals.create' => true,
                'customers.view' => 'own', 'values.view' => true, 'commission.view' => 'own', 'goals.view' => 'own', 'profit.view' => false,
                'dashboards.view' => 'own', 'indicators.view' => 'own', 'reports.export' => false, 'policy.manage' => false,
            ]],
            ['name' => 'SDR', 'sort' => 5, 'defaults' => [
                'pipelines.view' => 'assigned', 'leads.view' => 'team', 'opp.view' => 'own', 'opp.edit' => 'own',
                'opp.reassign' => false, 'opp.win' => false, 'opp.lose' => true, 'opp.delete' => false, 'proposals.create' => false,
                'customers.view' => 'team', 'values.view' => false, 'commission.view' => 'none', 'goals.view' => 'own', 'profit.view' => false,
                'dashboards.view' => 'own', 'indicators.view' => 'own', 'reports.export' => false, 'policy.manage' => false,
            ]],
            ['name' => 'Pré-vendas', 'sort' => 6, 'defaults' => [
                'pipelines.view' => 'assigned', 'leads.view' => 'team', 'opp.view' => 'team', 'opp.edit' => 'own',
                'opp.reassign' => false, 'opp.win' => false, 'opp.lose' => true, 'opp.delete' => false, 'proposals.create' => true,
                'customers.view' => 'team', 'values.view' => true, 'commission.view' => 'own', 'goals.view' => 'own', 'profit.view' => false,
                'dashboards.view' => 'own', 'indicators.view' => 'own', 'reports.export' => false, 'policy.manage' => false,
            ]],
            ['name' => 'Parceiro', 'sort' => 7, 'defaults' => [
                'pipelines.view' => 'assigned', 'leads.view' => 'own', 'opp.view' => 'own', 'opp.edit' => 'own',
                'opp.reassign' => false, 'opp.win' => false, 'opp.lose' => false, 'opp.delete' => false, 'proposals.create' => false,
                'customers.view' => 'own', 'values.view' => true, 'commission.view' => 'own', 'goals.view' => 'none', 'profit.view' => false,
                'dashboards.view' => 'none', 'indicators.view' => 'none', 'reports.export' => false, 'policy.manage' => false,
            ]],
            ['name' => 'Personalizado', 'sort' => 8, 'defaults' => []],
        ],
    ];
}
