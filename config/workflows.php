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
        'envolvidos_internos'  => 'Envolvidos internos (time)',
        'envolvidos_cliente'   => 'Cliente (envolvido no card)',
        'watchers'             => 'Em cópia (watchers)',
        'solicitante'          => 'Solicitante (criador da requisição)',
        'autor'                => 'Autor da ação',
        'consultor'            => 'Consultor',
        'parceiro'             => 'Parceiro',
        'responsavel'          => 'Responsável',
        'financeiro'           => 'Financeiro (e-mail fixo)',
    ],

    // O que cada audiência PRECISA do contexto pra resolver (any-of). [] = sempre aplicável.
    'audience_requires' => [
        'cliente'              => ['customer'],
        'contatos_do_contrato' => ['contract'],
        'executivo_de_contas'  => ['contract', 'customer'],
        'coordenador'          => ['project'],
        'administrativo'       => [],
        'diretor'              => [],
        'envolvidos_internos'  => ['card'],
        'envolvidos_cliente'   => ['card'],
        'watchers'             => ['request'],
        'solicitante'          => ['request'],
        'autor'                => ['actor'],
        'consultor'            => ['consultant'],
        'parceiro'             => ['partner'],
        'responsavel'          => ['followup'],
        'financeiro'           => [],
    ],

    // Capacidades que CADA workflow fornece no contexto (define o que faz sentido nele).
    'context' => [
        'contract.created'             => ['contract', 'customer', 'actor'],
        'contract.project_generated'   => ['contract', 'project', 'customer', 'actor'],
        'contract.inicio_autorizado'   => ['contract', 'customer', 'actor'],
        'contract.aporte'              => ['contract', 'project', 'customer', 'actor'],
        'contract.reajuste'            => ['contract', 'customer', 'actor'],
        'contract.reajustes_pendentes' => [],
        'request.lifecycle'            => ['request', 'customer', 'actor'],
        'card.chat_message'            => ['card', 'actor'],
        'card.phase_movement'          => ['card', 'actor'],
        'fechamento.cliente'           => ['contract', 'customer', 'actor'],
        'fechamento.consultor'         => ['consultant', 'actor'],
        'fechamento.parceiro'          => ['partner', 'actor'],
        'timesheet.status'             => ['project', 'actor'],
        'followup.reminder'            => ['followup', 'actor'],
    ],

    // Modelo de e-mail por workflow: título (assunto) + texto (corpo) + variáveis.
    // Editável na Central; o layout/branding (logo, card de dados, botão) é automático.
    'templates' => [
        'contract.created' => [
            'subject'   => 'Novo contrato cadastrado — {codigo}',
            'body'      => 'O contrato {codigo} do cliente {cliente} foi cadastrado e está em Novo Contrato.',
            'variables' => ['codigo' => 'Código do contrato', 'cliente' => 'Cliente'],
        ],
        'contract.project_generated' => [
            'subject'   => 'Projeto criado — {codigo}',
            'body'      => 'O contrato {codigo} ({cliente}) virou projeto e está pronto para iniciar.',
            'variables' => ['codigo' => 'Código', 'cliente' => 'Cliente', 'projeto' => 'Projeto'],
        ],
        'contract.inicio_autorizado' => [
            'subject'   => 'Contrato com início autorizado — {codigo}',
            'body'      => 'O contrato {codigo} ({cliente}) entrou na fase Início Autorizado.',
            'variables' => ['codigo' => 'Código', 'cliente' => 'Cliente'],
        ],
        'contract.aporte' => [
            'subject'   => 'Novo aporte de horas no contrato — {codigo}',
            'body'      => 'Foi registrado um novo aporte de horas no contrato {codigo}.',
            'variables' => ['codigo' => 'Código do contrato', 'projeto' => 'Projeto', 'cliente' => 'Cliente', 'horas' => 'Horas aportadas', 'saldo' => 'Saldo total', 'data' => 'Data'],
        ],
        'contract.reajuste' => [
            'subject'   => 'Reajuste do contrato — {codigo}',
            'body'      => 'O contrato {codigo} ({cliente}) teve um reajuste de valor aplicado.',
            'variables' => ['codigo' => 'Código', 'cliente' => 'Cliente'],
        ],
        'contract.reajustes_pendentes' => [
            'subject'   => 'Reajustes de contrato pendentes',
            'body'      => 'Há contratos com reajuste vencido/pendente aguardando ação.',
            'variables' => [],
        ],
        'request.lifecycle' => [
            'subject'   => 'Requisição {codigo} avançou para {para}',
            'body'      => 'A requisição {codigo} do cliente {cliente} foi movida de {de} para {para}.',
            'variables' => ['codigo' => 'Código da requisição', 'cliente' => 'Cliente', 'de' => 'Fase anterior', 'para' => 'Nova fase'],
        ],
        'card.chat_message' => [
            'subject'   => 'Nova mensagem em {codigo} — {titulo}',
            'body'      => '{autor} enviou uma nova mensagem no chat de {titulo}.',
            'variables' => ['codigo' => 'Código do card', 'titulo' => 'Título do card', 'autor' => 'Autor da mensagem'],
        ],
        'card.phase_movement' => [
            'subject'   => '{codigo} avançou para {para}',
            'body'      => 'O card {titulo} foi movido de {de} para {para} por {autor}.',
            'variables' => ['codigo' => 'Código', 'titulo' => 'Título', 'de' => 'Fase anterior', 'para' => 'Nova fase', 'autor' => 'Responsável'],
        ],
        'fechamento.cliente' => [
            'subject'   => 'Fechamento {periodo} — {cliente}',
            'body'      => 'Segue o relatório de fechamento referente a {periodo}.',
            'variables' => ['periodo' => 'Competência', 'cliente' => 'Cliente'],
        ],
        'fechamento.consultor' => [
            'subject'   => 'Seu fechamento {periodo}',
            'body'      => 'Segue o relatório de fechamento referente a {periodo}.',
            'variables' => ['periodo' => 'Competência', 'consultor' => 'Consultor'],
        ],
        'fechamento.parceiro' => [
            'subject'   => 'Fechamento {periodo} — {parceiro}',
            'body'      => 'Segue o relatório de fechamento referente a {periodo}.',
            'variables' => ['periodo' => 'Competência', 'parceiro' => 'Parceiro'],
        ],
        'timesheet.status' => [
            'subject'   => 'Apontamento {status}',
            'body'      => 'Seu apontamento de {data} foi marcado como {status}. {motivo}',
            'variables' => ['data' => 'Data do apontamento', 'status' => 'Novo status', 'motivo' => 'Motivo/observação'],
        ],
        'followup.reminder' => [
            'subject'   => 'Lembrete de follow-up — {assunto}',
            'body'      => 'Você tem um follow-up pendente: {assunto}.',
            'variables' => ['assunto' => 'Assunto', 'data' => 'Data'],
        ],
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
            'description' => 'Ao aplicar reajuste. Os destinatários escolhidos no envio entram sempre; a Central adiciona papéis em cópia.',
            'audiences'   => [
                'contatos_do_contrato' => 'off',
                'executivo_de_contas'  => 'off',
                'cliente'              => 'off',
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
                'solicitante'         => 'to',
                'executivo_de_contas' => 'to',
                'watchers'            => 'cc',
                'cliente'             => 'off',
            ],
        ],
        'card.chat_message' => [
            'label'       => 'Mensagem no chat do card',
            'domain'      => 'Triagem',
            'description' => 'Nova mensagem no chat de uma requisição/projeto.',
            'audiences'   => [
                'envolvidos_internos' => 'to',
                'envolvidos_cliente'  => 'to',
            ],
        ],
        'card.phase_movement' => [
            'label'       => 'Movimentação de fase do card',
            'domain'      => 'Triagem',
            'description' => 'Quando um card de requisição/projeto muda de fase.',
            'audiences'   => [
                'envolvidos_internos' => 'to',
                'envolvidos_cliente'  => 'to',
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
