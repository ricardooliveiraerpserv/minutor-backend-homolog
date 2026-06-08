<?php

/*
|--------------------------------------------------------------------------
| Registry de Workflows de E-mail
|--------------------------------------------------------------------------
| Fonte canônica de TODO workflow de e-mail do sistema. Cada workflow declara
| as audiências (papéis) disponíveis e o canal padrão (to | cc | off).
|
| A Central (admin) lista a partir daqui e grava overrides em `workflow_recipients`
| / `workflow_extra_emails`. O WorkflowRecipientResolver consulta o override e,
| na ausência, usa o default daqui.
|
| ⚠️ Todo workflow NOVO deve ser registrado aqui — assim aparece automático na
| Central. Há um guard de teste que falha se uma Notification/Mailable de negócio
| não tiver workflow correspondente.
*/

return [

    // E-mails de papéis "fixos" (resolvidos por config, não por relação).
    'diretor_email'   => env('WORKFLOW_DIRETOR_EMAIL', 'ricardo.badawi@erpserv.com.br'),
    'financeiro_email' => env('MAIL_REAJUSTES_ALERTA_TO', 'financeiro@erpserv.com.br'),

    // Catálogo global de audiências (papéis) — label exibido na Central.
    'audiences' => [
        'cliente'              => 'Cliente (usuários do cliente)',
        'contatos_do_contrato' => 'Contatos do contrato',
        'executivo_de_contas'  => 'Executivo de contas',
        'coordenador'          => 'Coordenador(es) do projeto',
        'administrativo'       => 'Administrativo',
        'diretor'              => 'Diretor de projetos',
        'envolvidos_do_card'   => 'Envolvidos do card',
        'watchers'             => 'Em cópia (watchers)',
        'autor'                => 'Autor da ação',
        'consultor'            => 'Consultor',
        'parceiro'             => 'Parceiro',
        'responsavel'          => 'Responsável',
        'financeiro'           => 'Financeiro (e-mail fixo)',
    ],

    // Workflows agrupados por domínio.
    'workflows' => [

        // ───────────────────────── Contratos ─────────────────────────
        'contract.created' => [
            'label'       => 'Contrato cadastrado',
            'domain'      => 'Contratos',
            'description' => 'Ao cadastrar um contrato ou quando o card cai na coluna "Novo Contrato".',
            'audiences'   => [
                'administrativo'      => 'to',
                'autor'               => 'to',
                'executivo_de_contas' => 'off',
                'diretor'             => 'off',
            ],
        ],
        'contract.project_generated' => [
            'label'       => 'Projeto gerado',
            'domain'      => 'Contratos',
            'description' => 'Ao gerar o projeto do contrato e quando o card entra em "Alocado".',
            'audiences'   => [
                'executivo_de_contas'  => 'to',
                'coordenador'          => 'to',
                'contatos_do_contrato' => 'to',
                'diretor'              => 'off',
                'cliente'              => 'off',
            ],
        ],
        'contract.inicio_autorizado' => [
            'label'       => 'Início autorizado',
            'domain'      => 'Contratos',
            'description' => 'Quando o contrato entra na fase "Início Autorizado".',
            'audiences'   => [
                'executivo_de_contas' => 'to',
                'diretor'             => 'to',
                'coordenador'         => 'off',
            ],
        ],
        'contract.aporte' => [
            'label'       => 'Novo aporte de horas',
            'domain'      => 'Contratos',
            'description' => 'Ao registrar um aporte (motivo=aporte). Em contrato filho, o cliente nunca entra.',
            'audiences'   => [
                'cliente'             => 'to',
                'executivo_de_contas' => 'cc',
                'autor'               => 'cc',
            ],
        ],
        'contract.reajuste' => [
            'label'       => 'Reajuste de contrato',
            'domain'      => 'Contratos',
            'description' => 'Ao aplicar reajuste de valor no contrato.',
            'audiences'   => [
                'cliente'             => 'to',
                'executivo_de_contas' => 'off',
            ],
        ],
        'contract.reajustes_pendentes' => [
            'label'       => 'Alerta de reajustes pendentes',
            'domain'      => 'Contratos',
            'description' => 'Rotina diária que alerta sobre reajustes vencidos/pendentes.',
            'audiences'   => [
                'financeiro' => 'to',
            ],
        ],

        // ───────────────────────── Triagem ─────────────────────────
        'request.lifecycle' => [
            'label'       => 'Requisição movida (lifecycle)',
            'domain'      => 'Triagem',
            'description' => 'A cada movimentação da requisição no kanban, até virar contrato/projeto.',
            'audiences'   => [
                'cliente'             => 'to',
                'executivo_de_contas' => 'to',
                'watchers'            => 'cc',
                'autor'               => 'off',
            ],
        ],
        'card.chat_message' => [
            'label'       => 'Mensagem no chat do card',
            'domain'      => 'Triagem',
            'description' => 'Nova mensagem no chat de uma requisição/projeto.',
            'audiences'   => [
                'envolvidos_do_card' => 'to',
            ],
        ],
        'card.phase_movement' => [
            'label'       => 'Movimentação de fase do card',
            'domain'      => 'Triagem',
            'description' => 'Quando um card de requisição/projeto muda de fase.',
            'audiences'   => [
                'envolvidos_do_card' => 'to',
            ],
        ],

        // ───────────────────────── Fechamento ─────────────────────────
        'fechamento.cliente' => [
            'label'       => 'Fechamento do cliente',
            'domain'      => 'Fechamento',
            'description' => 'Envio do relatório de fechamento ao cliente (From = usuário logado).',
            'audiences'   => [
                'cliente'             => 'to',
                'executivo_de_contas' => 'cc',
                'coordenador'         => 'off',
            ],
        ],
        'fechamento.consultor' => [
            'label'       => 'Fechamento do consultor',
            'domain'      => 'Fechamento',
            'description' => 'Envio do relatório de fechamento ao consultor.',
            'audiences'   => [
                'consultor' => 'to',
            ],
        ],
        'fechamento.parceiro' => [
            'label'       => 'Fechamento do parceiro',
            'domain'      => 'Fechamento',
            'description' => 'Envio do relatório de fechamento ao parceiro.',
            'audiences'   => [
                'parceiro' => 'to',
            ],
        ],

        // ───────────────────────── Apontamento / Outros ─────────────────────────
        'timesheet.status' => [
            'label'       => 'Status de apontamento',
            'domain'      => 'Apontamento',
            'description' => 'Quando um apontamento é rejeitado ou tem ajuste solicitado.',
            'audiences'   => [
                'autor'       => 'to',
                'coordenador' => 'off',
            ],
        ],
        'followup.reminder' => [
            'label'       => 'Lembrete de follow-up',
            'domain'      => 'Outros',
            'description' => 'Rotina que lembra o responsável de um follow-up pendente.',
            'audiences'   => [
                'responsavel' => 'to',
            ],
        ],
    ],
];
