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

    // Cor de destaque — uma por workflow, pra cada e-mail ser distinguível à primeira vista.
    'workflow_accents' => [
        'contract.created'             => '#FBBF24', // amarelo
        'contract.project_generated'   => '#22C55E', // verde
        'project.coordinator_assigned' => '#A3E635', // lima
        'project.finished'             => '#94A3B8', // slate (encerrado)
        'project.cancelled'            => '#EF4444', // vermelho (cancelado)
        'project.paused'               => '#EAB308', // amarelo (pausado)
        'contract.inicio_autorizado'   => '#22D3EE', // ciano
        'contract.aporte'              => '#34D399', // esmeralda (aporte pai)
        'contract.aporte.child'        => '#6EE7B7', // esmeralda claro (aporte subprojeto)
        'contract.reajuste'            => '#F472B6', // pink
        'contract.reajustes_pendentes' => '#FB923C', // laranja
        'request.phase.backlog'              => '#93C5FD',
        'request.phase.novo_projeto'         => '#60A5FA',
        'request.phase.em_planejamento'      => '#38BDF8',
        'request.phase.em_validacao'         => '#22D3EE',
        'request.phase.em_revisao'           => '#2DD4BF',
        'request.phase.aprovado'             => '#34D399',
        'request.phase.req_inicio_autorizado' => '#818CF8',
        'card.chat_message.request'    => '#818CF8', // índigo (chat requisição)
        'card.chat_message.project'    => '#6366F1', // índigo escuro (chat projeto)
        'card.phase_movement.request'  => '#A78BFA', // roxo (movimento requisição)
        'card.phase_movement.project'  => '#8B5CF6', // roxo escuro (movimento projeto)
        'chat.mention'                 => '#C084FC', // lilás (marcação @)
        'fechamento.cliente'           => '#F59E0B', // âmbar
        'fechamento.consultor'         => '#2DD4BF', // teal
        'fechamento.parceiro'          => '#FACC15', // dourado
        'fechamento.diretoria'         => '#FDBA74', // pêssego
        'timesheet.rejected'           => '#FB7185', // rosa (rejeitado)
        'timesheet.adjustment'         => '#F97316', // laranja (ajuste)
        'timesheet.pending_approval'             => '#38BDF8', // azul (aprovar projetos)
        'timesheet.pending_approval.sustentacao' => '#0EA5E9', // azul escuro (aprovar sustentação)
        'expense.pending_approval'     => '#F59E0B', // âmbar (aprovar despesa)
        'expense.adjustment'           => '#FB923C', // laranja claro (ajuste despesa)
        'expense.rejected'             => '#EF4444', // vermelho (despesa rejeitada)
    ],
    // Fallback por domínio (workflow novo sem cor própria).
    'domain_accents' => [
        'Requisições' => '#38BDF8',
        'Contratos'   => '#22C55E',
        'Triagem'     => '#38BDF8',
        'Fechamento'  => '#F59E0B',
        'Apontamento' => '#A78BFA',
        'Projetos'    => '#94A3B8',
        'Despesas'    => '#F97316',
        'Outros'      => '#FB7185',
    ],

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
        'financeiro'           => 'Financeiro (e-mail fixo)',
        'mencionado'           => 'Pessoa marcada (@) na mensagem',
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
        'financeiro'           => [],
        'mencionado'           => ['mentioned'],
    ],

    // Capacidades que CADA workflow fornece no contexto (define o que faz sentido nele).
    'context' => [
        'contract.created'             => ['contract', 'customer', 'actor'],
        'contract.project_generated'   => ['contract', 'project', 'customer', 'actor'],
        'project.coordinator_assigned' => ['contract', 'project', 'customer', 'actor'],
        'project.finished'             => ['project', 'contract', 'customer', 'actor'],
        'project.cancelled'            => ['project', 'contract', 'customer', 'actor'],
        'project.paused'               => ['project', 'contract', 'customer', 'actor'],
        'contract.inicio_autorizado'   => ['contract', 'customer', 'actor'],
        'contract.aporte'              => ['contract', 'project', 'customer', 'actor'],
        'contract.aporte.child'        => ['contract', 'project', 'customer', 'actor'],
        'contract.reajuste'            => ['contract', 'customer', 'actor'],
        'contract.reajustes_pendentes' => [],
        'expense.approved_pending_payment' => ['actor'],
        'request.phase.backlog'              => ['request', 'customer', 'actor'],
        'request.phase.novo_projeto'         => ['request', 'customer', 'actor'],
        'request.phase.em_planejamento'      => ['request', 'customer', 'actor'],
        'request.phase.em_validacao'         => ['request', 'customer', 'actor'],
        'request.phase.em_revisao'           => ['request', 'customer', 'actor'],
        'request.phase.aprovado'             => ['request', 'customer', 'actor'],
        'request.phase.req_inicio_autorizado' => ['request', 'customer', 'actor'],
        'card.chat_message.request'    => ['card', 'actor', 'mentioned'],
        'card.chat_message.project'    => ['card', 'project', 'contract', 'customer', 'actor', 'mentioned'],
        'card.phase_movement.request'  => ['card', 'actor'],
        'card.phase_movement.project'  => ['card', 'actor'],
        'chat.mention'                 => ['card', 'actor', 'mentioned'],
        'fechamento.cliente'           => ['contract', 'customer', 'actor'],
        'fechamento.consultor'         => ['consultant', 'actor'],
        'fechamento.parceiro'          => ['partner', 'actor'],
        'fechamento.diretoria'         => ['actor'],
        'timesheet.rejected'           => ['project', 'actor'],
        'timesheet.adjustment'         => ['project', 'actor'],
        'timesheet.pending_approval'             => ['project', 'actor'],
        'timesheet.pending_approval.sustentacao' => ['project', 'actor'],
        'expense.pending_approval'     => ['project', 'actor'],
        'expense.adjustment'           => ['project', 'actor'],
        'expense.rejected'             => ['project', 'actor'],
    ],

    // Modelo de e-mail por workflow: título (assunto) + texto (corpo) + variáveis.
    // Editável na Central; o layout/branding (logo, card de dados, botão) é automático.
    'templates' => [
        'expense.approved_pending_payment' => [
            'subject'   => 'Despesa aprovada e pendente de pagamento — {valor}',
            'body'      => 'A despesa "{descricao}" ({categoria}) no valor de {valor}, lançada por {autor}, foi aprovada e está pendente de pagamento.',
            'variables' => [
                'valor'     => 'Valor',
                'descricao' => 'Descrição',
                'categoria' => 'Categoria',
                'projeto'   => 'Projeto',
                'autor'     => 'Lançado por',
                'data'      => 'Data da despesa',
            ],
        ],
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
        'project.coordinator_assigned' => [
            'subject'   => 'Você foi designado(a) coordenador(a) — {codigo}',
            'body'      => 'Você foi designado(a) coordenador(a) do projeto {projeto} ({cliente}). Ele está pronto para iniciar.',
            'variables' => ['codigo' => 'Código', 'projeto' => 'Projeto', 'cliente' => 'Cliente'],
        ],
        'project.finished' => [
            'subject'   => 'Projeto encerrado — {codigo}',
            'body'      => 'O projeto {projeto} ({cliente}) foi encerrado.',
            'variables' => ['codigo' => 'Código', 'projeto' => 'Projeto', 'cliente' => 'Cliente'],
        ],
        'project.cancelled' => [
            'subject'   => 'Projeto cancelado — {codigo}',
            'body'      => 'O projeto {projeto} ({cliente}) foi cancelado.',
            'variables' => ['codigo' => 'Código', 'projeto' => 'Projeto', 'cliente' => 'Cliente'],
        ],
        'project.paused' => [
            'subject'   => 'Projeto pausado — {codigo}',
            'body'      => 'O projeto {projeto} ({cliente}) foi pausado.',
            'variables' => ['codigo' => 'Código', 'projeto' => 'Projeto', 'cliente' => 'Cliente'],
        ],
        'contract.aporte' => [
            'subject'   => 'Novo aporte de horas no contrato — {codigo}',
            'body'      => 'Foi registrado um novo aporte de horas no contrato {codigo}.',
            'variables' => ['codigo' => 'Código do contrato', 'projeto' => 'Projeto', 'cliente' => 'Cliente', 'horas' => 'Horas aportadas', 'saldo' => 'Saldo total', 'data' => 'Data'],
        ],
        'contract.aporte.child' => [
            'subject'   => 'Novo aporte de horas no subprojeto — {codigo}',
            'body'      => 'Foi registrado um novo aporte de horas no subprojeto {projeto}.',
            'variables' => ['codigo' => 'Código', 'projeto' => 'Subprojeto', 'cliente' => 'Cliente', 'horas' => 'Horas aportadas', 'saldo' => 'Saldo total', 'data' => 'Data'],
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
        'request.phase.backlog' => [
            'subject'   => 'Requisição {codigo} criada — Backlog',
            'body'      => 'A requisição {codigo} ({cliente}) foi criada e está em Backlog.',
            'variables' => ['codigo' => 'Código da requisição', 'cliente' => 'Cliente'],
        ],
        'request.phase.novo_projeto' => [
            'subject'   => 'Requisição {codigo} → Novo Projeto',
            'body'      => 'A requisição {codigo} ({cliente}) entrou em Novo Projeto.',
            'variables' => ['codigo' => 'Código da requisição', 'cliente' => 'Cliente'],
        ],
        'request.phase.em_planejamento' => [
            'subject'   => 'Requisição {codigo} → Em Planejamento',
            'body'      => 'A requisição {codigo} ({cliente}) entrou em Em Planejamento.',
            'variables' => ['codigo' => 'Código da requisição', 'cliente' => 'Cliente'],
        ],
        'request.phase.em_validacao' => [
            'subject'   => 'Requisição {codigo} → Em Validação',
            'body'      => 'A requisição {codigo} ({cliente}) entrou em Em Validação.',
            'variables' => ['codigo' => 'Código da requisição', 'cliente' => 'Cliente'],
        ],
        'request.phase.em_revisao' => [
            'subject'   => 'Requisição {codigo} → Em Revisão',
            'body'      => 'A requisição {codigo} ({cliente}) entrou em Em Revisão.',
            'variables' => ['codigo' => 'Código da requisição', 'cliente' => 'Cliente'],
        ],
        'request.phase.aprovado' => [
            'subject'   => 'Requisição {codigo} → Aprovado',
            'body'      => 'A requisição {codigo} ({cliente}) foi Aprovada.',
            'variables' => ['codigo' => 'Código da requisição', 'cliente' => 'Cliente'],
        ],
        'request.phase.req_inicio_autorizado' => [
            'subject'   => 'Requisição {codigo} → Aguardando Início',
            'body'      => 'A requisição {codigo} ({cliente}) está Aguardando Início.',
            'variables' => ['codigo' => 'Código da requisição', 'cliente' => 'Cliente'],
        ],
        'card.chat_message.request' => [
            'subject'   => 'Nova mensagem em {codigo} — {titulo}',
            'body'      => '{autor} enviou uma nova mensagem no chat da requisição {titulo}.',
            'variables' => ['codigo' => 'Código do card', 'titulo' => 'Título do card', 'autor' => 'Autor da mensagem'],
        ],
        'card.chat_message.project' => [
            'subject'   => 'Nova interação no Diário do Projeto — {codigo} {titulo}',
            'body'      => '{autor} registrou uma nova interação no Diário do Projeto {titulo}.',
            'variables' => ['codigo' => 'Código do card', 'titulo' => 'Título do card', 'autor' => 'Autor da interação'],
        ],
        'card.phase_movement.request' => [
            'subject'   => '{codigo} avançou para {para}',
            'body'      => 'A requisição {titulo} foi movida de {de} para {para} por {autor}.',
            'variables' => ['codigo' => 'Código', 'titulo' => 'Título', 'de' => 'Fase anterior', 'para' => 'Nova fase', 'autor' => 'Responsável'],
        ],
        'card.phase_movement.project' => [
            'subject'   => '{codigo} avançou para {para}',
            'body'      => 'O projeto {titulo} foi movido de {de} para {para} por {autor}.',
            'variables' => ['codigo' => 'Código', 'titulo' => 'Título', 'de' => 'Fase anterior', 'para' => 'Nova fase', 'autor' => 'Responsável'],
        ],
        'chat.mention' => [
            'subject'   => 'Você foi marcado(a) por {autor} — {titulo}',
            'body'      => '{autor} marcou você em uma mensagem de {titulo}.',
            'variables' => ['titulo' => 'Título do card', 'autor' => 'Autor da mensagem'],
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
        'fechamento.diretoria' => [
            'subject'   => 'Fechamento Diretoria — {periodo} | {diretor}',
            'body'      => 'Segue o fechamento da diretoria referente a {periodo}.',
            'variables' => ['periodo' => 'Competência', 'diretor' => 'Diretor'],
        ],
        'timesheet.rejected' => [
            'subject'   => 'Apontamento rejeitado',
            'body'      => 'Seu apontamento de {data} foi rejeitado. {motivo}',
            'variables' => ['data' => 'Data do apontamento', 'motivo' => 'Motivo/observação'],
        ],
        'timesheet.adjustment' => [
            'subject'   => 'Apontamento — ajuste solicitado',
            'body'      => 'Seu apontamento de {data} precisa de ajuste. {motivo}',
            'variables' => ['data' => 'Data do apontamento', 'motivo' => 'Motivo/observação'],
        ],
        'timesheet.pending_approval' => [
            'subject'   => 'Apontamentos aguardando sua aprovação',
            'body'      => 'Há apontamentos pendentes aguardando sua aprovação.',
            'variables' => [],
        ],
        'timesheet.pending_approval.sustentacao' => [
            'subject'   => 'Apontamentos de sustentação do dia anterior para aprovar',
            'body'      => 'Há apontamentos de sustentação do dia anterior aguardando sua aprovação.',
            'variables' => [],
        ],
        'expense.pending_approval' => [
            'subject'   => 'Despesas aguardando sua aprovação',
            'body'      => 'Há despesas pendentes aguardando sua aprovação.',
            'variables' => [],
        ],
        'expense.adjustment' => [
            'subject'   => 'Despesa — ajuste solicitado',
            'body'      => 'Você tem despesas com ajuste solicitado.',
            'variables' => [],
        ],
        'expense.rejected' => [
            'subject'   => 'Despesa rejeitada',
            'body'      => 'Você tem despesas rejeitadas.',
            'variables' => [],
        ],
    ],

    // Workflows agrupados por domínio.
    'workflows' => [

        // ───────────────────────── Despesas ─────────────────────────
        'expense.approved_pending_payment' => [
            'label'       => 'Despesa aprovada — pendente de pagamento',
            'domain'      => 'Despesas',
            'description' => 'Quando uma despesa é APROVADA, avisa o administrativo que há despesa a pagar. Defina a recorrência abaixo para reenviar o aviso a cada N dias enquanto a despesa não for marcada como paga (0 = só o aviso na aprovação).',
            'recurrence'  => true,
            'audiences'   => [
                'administrativo' => 'to',
                'financeiro'     => 'off',
                'autor'          => 'off',
            ],
        ],

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
        'project.coordinator_assigned' => [
            'label'       => 'Coordenador atribuído ao projeto',
            'domain'      => 'Contratos',
            'description' => 'Quando um coordenador é atribuído/trocado num projeto JÁ gerado (move o card para a coluna do coordenador). Notifica o coordenador da coluna — sem reincomodar cliente/executivo a cada troca.',
            'audiences'   => [
                'coordenador'         => 'to',
                'diretor'             => 'off',
                'executivo_de_contas' => 'off',
            ],
        ],

        // ───────────────────────── Projetos (fases) ─────────────────────────
        'project.finished' => [
            'label'       => 'Projeto encerrado (Fechado)',
            'domain'      => 'Projetos',
            'description' => 'Quando um projeto é movido para Fechado (status finished).',
            'audiences'   => [
                'coordenador'         => 'to',
                'executivo_de_contas' => 'cc',
                'diretor'             => 'off',
                'cliente'             => 'off',
            ],
        ],
        'project.cancelled' => [
            'label'       => 'Projeto cancelado',
            'domain'      => 'Projetos',
            'description' => 'Quando um projeto é movido para Cancelado (status cancelled).',
            'audiences'   => [
                'coordenador'         => 'to',
                'executivo_de_contas' => 'cc',
                'diretor'             => 'off',
                'cliente'             => 'off',
            ],
        ],
        'project.paused' => [
            'label'       => 'Projeto pausado',
            'domain'      => 'Projetos',
            'description' => 'Quando um projeto é movido para Pausado (status paused).',
            'audiences'   => [
                'coordenador'         => 'to',
                'executivo_de_contas' => 'cc',
                'diretor'             => 'off',
                'cliente'             => 'off',
            ],
        ],
        'contract.aporte' => [
            'label'       => 'Novo aporte de horas — Projeto pai',
            'domain'      => 'Contratos',
            'description' => 'Ao registrar um aporte no projeto pai. O cliente é avisado.',
            'audiences'   => [
                'cliente'             => 'to',
                'executivo_de_contas' => 'cc',
                'autor'               => 'cc',
            ],
        ],
        'contract.aporte.child' => [
            'label'       => 'Novo aporte de horas — Subprojeto',
            'domain'      => 'Contratos',
            'description' => 'Ao registrar um aporte em subprojeto (contrato filho). Interno — o cliente NÃO é avisado.',
            'audiences'   => [
                'executivo_de_contas' => 'to',
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
        'request.phase.backlog' => [
            'label'       => 'Requisição criada / Backlog',
            'domain'      => 'Requisições',
            'description' => 'Quando a requisição é criada (entra em Backlog) ou movida para a coluna Backlog. Também é o fallback de colunas sem workflow próprio.',
            'audiences'   => ['solicitante' => 'to', 'executivo_de_contas' => 'to', 'watchers' => 'cc', 'cliente' => 'off'],
        ],
        'request.phase.novo_projeto' => [
            'label'       => 'Requisição → Novo Projeto',
            'domain'      => 'Requisições',
            'description' => 'Quando uma requisição entra na coluna Novo Projeto.',
            'audiences'   => ['solicitante' => 'to', 'executivo_de_contas' => 'to', 'watchers' => 'cc', 'cliente' => 'off'],
        ],
        'request.phase.em_planejamento' => [
            'label'       => 'Requisição → Em Planejamento',
            'domain'      => 'Requisições',
            'description' => 'Quando uma requisição entra na coluna Em Planejamento.',
            'audiences'   => ['solicitante' => 'to', 'executivo_de_contas' => 'to', 'watchers' => 'cc', 'cliente' => 'off'],
        ],
        'request.phase.em_validacao' => [
            'label'       => 'Requisição → Em Validação',
            'domain'      => 'Requisições',
            'description' => 'Quando uma requisição entra na coluna Em Validação (visível ao cliente).',
            'audiences'   => ['solicitante' => 'to', 'executivo_de_contas' => 'to', 'watchers' => 'cc', 'cliente' => 'off'],
        ],
        'request.phase.em_revisao' => [
            'label'       => 'Requisição → Em Revisão',
            'domain'      => 'Requisições',
            'description' => 'Quando uma requisição entra na coluna Em Revisão.',
            'audiences'   => ['solicitante' => 'to', 'executivo_de_contas' => 'to', 'watchers' => 'cc', 'cliente' => 'off'],
        ],
        'request.phase.aprovado' => [
            'label'       => 'Requisição → Aprovado',
            'domain'      => 'Requisições',
            'description' => 'Quando uma requisição entra na coluna Aprovado.',
            'audiences'   => ['solicitante' => 'to', 'executivo_de_contas' => 'to', 'watchers' => 'cc', 'cliente' => 'off'],
        ],
        'request.phase.req_inicio_autorizado' => [
            'label'       => 'Requisição → Aguardando Início',
            'domain'      => 'Requisições',
            'description' => 'Quando uma requisição entra na coluna Aguardando Início.',
            'audiences'   => ['solicitante' => 'to', 'executivo_de_contas' => 'to', 'watchers' => 'cc', 'cliente' => 'off'],
        ],
        'card.chat_message.request' => [
            'label'       => 'Mensagem no chat — Requisição',
            'domain'      => 'Requisições',
            'description' => 'Nova mensagem no chat de uma requisição. O cliente participa (até a requisição virar projeto).',
            'audiences'   => [
                'envolvidos_internos' => 'to',
                'envolvidos_cliente'  => 'to',
            ],
        ],
        'card.chat_message.project' => [
            'label'       => 'Diário do Projeto',
            'domain'      => 'Projetos',
            'description' => 'Nova interação no Diário do Projeto. Interno (cliente não participa). Sem menção, recebem o executivo de contas, o(s) coordenador(es) e o diretor de projetos. Pessoas fora desses papéis só recebem se forem citadas (@).',
            'audiences'   => [
                'executivo_de_contas' => 'to',
                'coordenador'         => 'to',
                'diretor'             => 'to',
            ],
        ],
        'card.phase_movement.request' => [
            'label'       => 'Movimentação de fase — Requisição',
            'domain'      => 'Requisições',
            'description' => 'Quando uma requisição muda de fase no kanban.',
            'audiences'   => [
                'envolvidos_internos' => 'to',
                'envolvidos_cliente'  => 'to',
            ],
        ],
        'card.phase_movement.project' => [
            'label'       => 'Movimentação de fase — Projeto',
            'domain'      => 'Projetos',
            'description' => 'Quando um projeto muda de fase no kanban. Interno — o cliente não participa.',
            'audiences'   => [
                'envolvidos_internos' => 'to',
            ],
        ],
        'chat.mention' => [
            'label'       => 'Marcação em chat (@)',
            'domain'      => 'Requisições',
            'description' => 'Quando alguém marca (@) uma pessoa numa mensagem de chat (requisição, contrato ou projeto). Notifica a pessoa marcada (além do sino in-app). Não duplica com quem já recebe o e-mail de "Mensagem no chat".',
            'audiences'   => [
                'mencionado' => 'to',
            ],
        ],

        // ───────────────────────── Fechamento ─────────────────────────
        'fechamento.cliente' => [
            'label'       => 'Fechamento do cliente',
            'domain'      => 'Fechamento',
            'description' => 'Envio do fechamento ao cliente. Os e-mails escolhidos na tela são sempre o destinatário; a Central adiciona papéis (ex.: executivo em cópia).',
            'audiences'   => [
                'executivo_de_contas' => 'cc',
                'coordenador'         => 'off',
                'cliente'             => 'off',
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
        'fechamento.diretoria' => [
            'label'       => 'Fechamento da diretoria',
            'domain'      => 'Fechamento',
            'description' => 'Envio do fechamento ao diretor. Os e-mails escolhidos na tela são sempre o destinatário; a Central adiciona papéis em cópia (ex.: administrativo/financeiro).',
            'audiences'   => [
                'administrativo' => 'off',
                'financeiro'     => 'off',
            ],
        ],

        // ───────────────────────── Apontamento / Outros ─────────────────────────
        'timesheet.rejected' => [
            'label'       => 'Apontamento rejeitado',
            'domain'      => 'Apontamento',
            'description' => 'Quando um apontamento é rejeitado. O dono do apontamento (quem o criou) sempre recebe automaticamente — aqui você só adiciona papéis em cópia (ex.: coordenador).',
            'audiences'   => [
                'coordenador' => 'off',
            ],
        ],
        'timesheet.adjustment' => [
            'label'       => 'Apontamento — ajuste solicitado',
            'domain'      => 'Apontamento',
            'description' => 'Quando é solicitado ajuste num apontamento. O dono do apontamento (quem o criou) sempre recebe automaticamente — aqui você só adiciona papéis em cópia (ex.: coordenador).',
            'audiences'   => [
                'coordenador' => 'off',
            ],
        ],

        // ── Lembretes de AÇÕES NÃO RESOLVIDAS (Central de Ações / Meu Dia) ──
        'timesheet.pending_approval' => [
            'label'       => 'Apontamentos para aprovar (projetos)',
            'domain'      => 'Apontamento',
            'description' => 'Lembrete recorrente ao coordenador com apontamentos pendentes de aprovação. O coordenador responsável recebe automaticamente; adicione papéis/e-mails em cópia se quiser.',
            'recurrence'  => true,
            'audiences'   => ['administrativo' => 'off'],
        ],
        'timesheet.pending_approval.sustentacao' => [
            'label'       => 'Apontamentos para aprovar (sustentação)',
            'domain'      => 'Apontamento',
            'description' => 'Lembrete ao coordenador de sustentação — só os apontamentos do dia anterior. O responsável recebe automaticamente.',
            'recurrence'  => true,
            'audiences'   => ['administrativo' => 'off'],
        ],
        'expense.pending_approval' => [
            'label'       => 'Despesas para aprovar',
            'domain'      => 'Despesas',
            'description' => 'Lembrete ao coordenador com despesas pendentes de aprovação. O responsável recebe automaticamente.',
            'recurrence'  => true,
            'audiences'   => ['administrativo' => 'off'],
        ],
        'expense.adjustment' => [
            'label'       => 'Despesa — ajuste solicitado',
            'domain'      => 'Despesas',
            'description' => 'Lembrete ao autor da despesa com ajuste solicitado. O autor recebe automaticamente; adicione papéis em cópia (ex.: coordenador).',
            'recurrence'  => true,
            'audiences'   => ['coordenador' => 'off'],
        ],
        'expense.rejected' => [
            'label'       => 'Despesa rejeitada',
            'domain'      => 'Despesas',
            'description' => 'Lembrete ao autor da despesa rejeitada. O autor recebe automaticamente; adicione papéis em cópia (ex.: coordenador).',
            'recurrence'  => true,
            'audiences'   => ['coordenador' => 'off'],
        ],
    ],
];
