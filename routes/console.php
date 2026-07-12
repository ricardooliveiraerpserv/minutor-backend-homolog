<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\CleanupContractEventsJob;
use App\Jobs\NormalizeContractStateJob;
use App\Jobs\UpdateAccumulatedSoldHoursJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Agendar atualização de horas acumuladas todo dia 1 de cada mês
// Múltiplos horários para aumentar chances de execução mesmo se servidor estiver offline
Schedule::call(function () {
    UpdateAccumulatedSoldHoursJob::dispatch();
})->monthlyOn(1, '00:00')
  ->name('update-accumulated-sold-hours-00:00')
  ->description('Atualiza accumulated_sold_hours - Tentativa 1 (00:00)');

Schedule::call(function () {
    UpdateAccumulatedSoldHoursJob::dispatch();
})->monthlyOn(1, '06:00')
  ->name('update-accumulated-sold-hours-06:00')
  ->description('Atualiza accumulated_sold_hours - Tentativa 2 (06:00)');

Schedule::call(function () {
    UpdateAccumulatedSoldHoursJob::dispatch();
})->monthlyOn(1, '12:00')
  ->name('update-accumulated-sold-hours-12:00')
  ->description('Atualiza accumulated_sold_hours - Tentativa 3 (12:00)');

Schedule::call(function () {
    UpdateAccumulatedSoldHoursJob::dispatch();
})->monthlyOn(1, '18:00')
  ->name('update-accumulated-sold-hours-18:00')
  ->description('Atualiza accumulated_sold_hours - Tentativa 4 (18:00)');

// CRM — snapshot diário de Saúde da Conta (Roadmap Fase 1).
Schedule::command('crm:snapshot-saude')
  ->dailyAt('04:30')
  ->name('crm-snapshot-saude')
  ->withoutOverlapping();

// CRM — snapshot semanal do forecast (tendência/slippage). Segunda 06:00.
Schedule::command('crm:forecast-snapshot')
  ->weeklyOn(1, '06:00')
  ->name('crm-forecast-snapshot')
  ->withoutOverlapping();

// CRM — escalona leads negligenciados (sem dono / SLA estourado / parados) p/ os gestores.
Schedule::command('crm:escalonar-leads')
  ->dailyAt('07:30')
  ->name('crm-escalonar-leads')
  ->withoutOverlapping();

// CRM — gera oportunidades de renovação p/ contratos a 90 dias do vencimento (Item 5).
Schedule::command('crm:gerar-renovacoes')
  ->dailyAt('05:00')
  ->name('crm-gerar-renovacoes')
  ->description('Cria oportunidades de renovação para contratos próximos do vencimento')
  ->withoutOverlapping();

// Portal de Propostas — follow-ups automáticos (sem abertura 3d / sem interação 7d) + expiração de links.
Schedule::command('crm:proposta-followups')
  ->dailyAt('05:30')
  ->name('crm-proposta-followups')
  ->description('Follow-ups das propostas por engajamento e expiração de links do Portal')
  ->withoutOverlapping();

// Comando de verificação que roda diariamente para garantir execução mensal
Schedule::command('projects:ensure-monthly-update')
  ->dailyAt('02:00')
  ->name('ensure-monthly-accumulated-hours-update')
  ->description('Verifica se accumulated_sold_hours foi atualizado no mês atual');

// Encerra os MESES ABERTOS (ProjectOpenPeriod) de competências anteriores à vigente, às 06h.
// Mês imediatamente anterior só fecha APÓS o 2º dia útil (carência); meses mais antigos sempre.
Schedule::command('projects:close-stale-periods')
  ->dailyAt('06:00')
  ->name('projects-close-stale-periods')
  ->description('Encerra meses abertos de projetos de competências anteriores (mês anterior só após o 2º dia útil)')
  ->withoutOverlapping();

// Rede de segurança pro reset de conflitos órfãos (observer já cobre saves/deletes
// via Eloquent; este sweep cobre mudanças via SQL bruto e legado pré-observer).
Schedule::command('timesheets:resolve-stale-conflicts')
  ->cron('*/10 * * * *')
  ->name('timesheets-resolve-stale-conflicts')
  ->description('Reverte apontamentos conflicted sem sobreposição para pending')
  ->withoutOverlapping(10)
  ->runInBackground();

// Help Desk — ingestão de e-mails (Graph Mail.Read) → cria/atualiza chamados a partir da
// caixa configurada. Idempotente (ledger de mensagens): execuções sobrepostas não duplicam.
Schedule::command('help-desk:ingest-emails --limit=25')
  ->cron('*/5 * * * *')
  ->name('help-desk-ingest-emails')
  ->description('Ingere e-mails da caixa do Help Desk e abre/atualiza chamados')
  ->withoutOverlapping(10)
  ->runInBackground();

// Sync de apontamentos Movidesk (fallback do webhook — garante que nenhum apontamento seja perdido)
Schedule::command('movidesk:sync')
  ->cron('*/5 * * * *')
  ->name('movidesk-sync')
  ->description('Sincroniza apontamentos do Movidesk via API')
  ->withoutOverlapping(10)
  ->runInBackground();

// Slow-lane: tickets que falharam no sync principal (timeout 5s) são reprocessados
// aqui de hora em hora via fetchTicketLight (timeout 30s, $expand mínimo).
Schedule::command('movidesk:retry-problem-tickets')
  ->hourly()
  ->name('movidesk-retry-problem-tickets')
  ->description('Reprocessa tickets do Movidesk que falharam na sync principal')
  ->withoutOverlapping(120)
  ->runInBackground();

// Sync do Portal de Sustentação (tickets dos últimos 90 dias com campos SLA)
try { $portalSyncInterval = (int) \App\Models\SystemSetting::get('movidesk_portal_sync_interval_minutes', 30); }
catch (\Exception $e) { $portalSyncInterval = 30; }
Schedule::command('movidesk:portal-sync')
  ->cron("*/{$portalSyncInterval} * * * *")
  ->name('movidesk-portal-sync')
  ->description('Sincroniza tickets do Movidesk para o Portal de Sustentação')
  ->withoutOverlapping(60)
  ->runInBackground();

// Sync de organizações do Movidesk — popula CNPJ e customer_id nos tickets
try { $syncOrgsInterval = (int) \App\Models\SystemSetting::get('movidesk_sync_orgs_interval_minutes', 30); }
catch (\Exception $e) { $syncOrgsInterval = 30; }
Schedule::command('movidesk:sync-orgs')
  ->cron("*/{$syncOrgsInterval} * * * *")
  ->name('movidesk-sync-orgs')
  ->description('Sincroniza organizações do Movidesk e popula CNPJs nos tickets')
  ->withoutOverlapping(60)
  ->runInBackground();

// Normalização de snapshots de contratos — detecta e corrige divergências
Schedule::job(new NormalizeContractStateJob)
  ->cron('*/30 * * * *')
  ->name('normalize-contract-state')
  ->description('Fallback: detecta e corrige inconsistências nos snapshots de contratos')
  ->withoutOverlapping();

// Fechamento automático — roda diariamente às 06:00 e só age no 2º dia útil
Schedule::command('fechamento:auto-fechar')
  ->dailyAt('06:00')
  ->name('fechamento-auto-fechar')
  ->description('Fecha a competência do mês anterior no 2º dia útil do mês atual')
  ->withoutOverlapping();

// Reset das alocações de Investimento Interno — roda diariamente às 00:05
// e só age no 2º dia útil. Alocações feitas após esse horário no mesmo dia
// são preservadas.
Schedule::command('investimento-interno:reset-allocations')
  ->dailyAt('00:05')
  ->name('investimento-interno-reset-allocations')
  ->description('Desaloca consultores dos projetos de Investimento Interno no 2º dia útil')
  ->withoutOverlapping();

// Limpeza de eventos antigos — mantém histórico dos últimos 180 dias
Schedule::job(new CleanupContractEventsJob)
  ->weekly()
  ->name('cleanup-contract-events')
  ->description('Remove eventos de contrato com mais de 180 dias')
  ->withoutOverlapping();

// Health Engine — varre customers e gera eventos de risco/oportunidade no Operational Feed
// Roda toda segunda-feira às 06:00 (espelha cadência semanal do n8n original)
Schedule::command('health:scan')
  ->weeklyOn(1, '06:00')
  ->name('operational-feed-health-scan')
  ->description('Health Engine: tickets + saldo de horas → eventos no Operational Feed')
  ->withoutOverlapping(30)
  ->runInBackground();

// IA diagnóstico — após o Health Engine, dispara IA para os top-20 customers em risco
// Roda toda segunda-feira às 07:00 (1h depois do health:scan, dá tempo de eventos estarem gravados)
Schedule::command('ai:scan-customers-at-risk --days=7 --max=20 --sync')
  ->weeklyOn(1, '07:00')
  ->name('operational-feed-ai-insights')
  ->description('IA: gera diagnóstico para customers com eventos recentes de risco no Operational Feed')
  ->withoutOverlapping(60)
  ->runInBackground();

// Alerta de reajustes vencidos ao Financeiro — roda todo dia às 08:00, mas o comando
// só envia de fato no 1º DIA ÚTIL do mês (pula fim de semana). Não aplica reajuste.
Schedule::command('reajustes:alerta-vencidos')
  ->dailyAt('08:00')
  ->name('alerta-reajustes-vencidos')
  ->description('Avisa o Financeiro sobre contratos com reajuste vencido (1º dia útil do mês)')
  ->withoutOverlapping();

// Recorrência do aviso de despesa aprovada e não paga (reenvia a cada N dias,
// N = recurrence_days da Central). O aviso inicial sai na própria aprovação.
Schedule::command('expenses:alerta-pendentes-pagamento')
  ->dailyAt('08:30')
  ->name('alerta-despesas-pendentes-pagamento')
  ->description('Reenvia aviso de despesas aprovadas e não pagas conforme recorrência da Central')
  ->withoutOverlapping();

// FASE 11.1 — Integrity sweep diário dos anexos (verifica entidade-dona, arquivo
// físico, checksum e provider). Default incremental (últimos 7 dias) pra não
// pesar; varredura completa pode ser disparada manualmente com --all.
Schedule::command('attachments:integrity-check')
  ->dailyAt('03:00')
  ->name('attachments-integrity-check')
  ->description('FASE 11: verifica integridade dos anexos (entidade-dona, arquivo, checksum)')
  ->withoutOverlapping()
  ->runInBackground();

// Digest de mensagens não lidas do chat — roda a cada 30 minutos
// e envia email pra cada user que ficou com mensagens não lidas há
// pelo menos 15 minutos, respeitando cooldown de 60 minutos por user.
Schedule::command('inbox:digest --min-quiet=15 --cooldown=60')
  ->everyThirtyMinutes()
  ->name('inbox-digest')
  ->description('Envia digest por email das mensagens não lidas do chat')
  ->withoutOverlapping()
  ->runInBackground();

// Alertas proativos do BOT (banco de horas crítico, despesas/timesheets pendentes,
// tickets parados). Roda 1x ao dia às 8h e popula o OperationalFeed — a partir
// daí, as notification_rules existentes encaminham pros responsáveis.
Schedule::command('bot:proactive-alerts')
  ->dailyAt('08:00')
  ->name('bot-proactive-alerts')
  ->description('Detecta anomalias e cria alertas no OperationalFeed')
  ->withoutOverlapping()
  ->runInBackground();

// ===== Meu Dia — Central de Notificações / Ações / Tarefas =====
Schedule::command('notifications:fire-recurring --scope=daily')
  ->dailyAt('07:00')
  ->name('notifications-fire-recurring')
  ->description('Reabre/reenvia avisos recorrentes da Central da tela inicial')
  ->withoutOverlapping();

Schedule::command('notifications:fire-recurring --scope=hours')
  ->hourly()
  ->name('notifications-fire-recurring-hours')
  ->description('Re-pergunta decisões recorrentes por horas')
  ->withoutOverlapping();

Schedule::command('actions:remind-pending')
  ->hourly()
  ->name('actions-remind-pending')
  ->description('Re-lembra ações não resolvidas conforme recorrência configurada')
  ->withoutOverlapping();

Schedule::command('tasks:generate-routines')
  ->dailyAt('06:30')
  ->name('tasks-generate-routines')
  ->description('Gera as tarefas das rotinas de equipe para o dia')
  ->withoutOverlapping();

Schedule::command('tasks:notify-overdue')
  ->dailyAt('08:00')
  ->name('tasks-notify-overdue')
  ->description('Notifica o responsável sobre tarefas atrasadas')
  ->withoutOverlapping();

// 🎫 HELP DESK — retoma SLA de agendados vencidos + reabre chamados com reabertura agendada.
Schedule::command('help-desk:resume-scheduled')
  ->cron('*/5 * * * *')
  ->name('help-desk-resume-scheduled')
  ->description('Retoma o SLA de chamados agendados cuja data/hora já passou')
  ->withoutOverlapping(10)
  ->runInBackground();

Schedule::command('help-desk:run-scheduled-reopens')
  ->cron('*/5 * * * *')
  ->name('help-desk-run-scheduled-reopens')
  ->description('Reabre chamados resolvidos/encerrados com reabertura agendada vencida')
  ->withoutOverlapping(10)
  ->runInBackground();
