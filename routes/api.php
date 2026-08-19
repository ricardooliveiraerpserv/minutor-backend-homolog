<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AusterIndicatorsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\UserCapacityController;
use App\Http\Controllers\ContractTypeController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectStageController;
use App\Http\Controllers\StageDeliveryController;
use App\Http\Controllers\DeliveryEventController;
use App\Http\Controllers\StageAllocationController;
use App\Http\Controllers\StageHourAporteController;
use App\Http\Controllers\ServiceTypeController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseTypeController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\ConsultantGroupController;
use App\Http\Controllers\PermissionGroupController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\MovideskWebhookController;
use App\Http\Controllers\ProjectStatusController;
use App\Http\Controllers\BankHoursFixedController;
use App\Http\Controllers\BankHoursMonthlyController;
use App\Http\Controllers\OnDemandController;
use App\Http\Controllers\ExecutiveController;
use App\Http\Controllers\HourContributionController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PartnerReportController;
use App\Http\Controllers\ConsultantHourBankController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\ClientActivityController;
use App\Http\Controllers\ClientProjectController;
use App\Http\Controllers\ClientFollowUpController;
use App\Http\Controllers\ProjectClientViewerController;
use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\FechadoController;
use App\Http\Controllers\ProjectMessageController;
use App\Http\Controllers\SustentacaoController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\CustomerContactController;
use App\Http\Controllers\ProjectContactController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\ConsultantSkillController;
use App\Http\Controllers\GapController;
use App\Http\Controllers\SkillSurveyController;
use App\Http\Controllers\SkillSubmissionController;
use App\Http\Controllers\SkillDashboardController;
use App\Http\Controllers\SkillProfileController;
use App\Http\Controllers\SkillMatrixVersionController;
use App\Http\Controllers\SkillFormConfigController;
use App\Http\Controllers\SkillHireController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\SearchController;

/*
|--------------------------------------------------------------------------
| API Routes - v1
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Grupo de rotas versionadas v1
Route::prefix('v1')->group(function () {
    // Rotas públicas (sem autenticação) — throttle obrigatório para mitigar brute-force
    Route::prefix('auth')->group(function () {
        // Autenticação — 5 tentativas por minuto por e-mail+IP (limiter 'login').
        // NÃO usar throttle:5,1 (por-IP): atrás do proxy o IP colapsa no gateway
        // Docker e o balde vira coletivo do sistema inteiro. Ver AppServiceProvider.
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('auth.login');

        // Recuperação de senha — 3 solicitações por hora por IP
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])
            ->middleware('throttle:3,60')
            ->name('password.email');
        Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])
            ->middleware('throttle:5,15')
            ->name('password.reset');
        Route::post('/verify-reset-token', [PasswordResetController::class, 'verifyResetToken'])
            ->middleware('throttle:10,1')
            ->name('password.verify');
    });

    // 🎫 WEBHOOKS - Rotas públicas para receber notificações externas
    Route::post('/webhooks/movidesk/ticket', [MovideskWebhookController::class, 'handleTicket'])
        ->name('webhooks.movidesk.ticket');

    // ✍️ WEBHOOK Clicksign (v3) — REATIVADO (P-E.2.0): assinatura da PROPOSTA via Clicksign.
    Route::post('/webhooks/clicksign', [\App\Http\Controllers\ClicksignWebhookController::class, 'handle'])
        ->middleware('throttle:120,1')
        ->name('webhooks.clicksign');

    // 🔗 PORTAL DE PROPOSTAS — acesso público por token (sem login). Throttle alto: o portal faz muito
    // tracking (página/seção/heartbeat) + polling; o token de 48 chars já é a barreira anti-brute-force.
    Route::middleware('throttle:300,1')->group(function () {
        Route::get('/p/{token}',           [\App\Http\Controllers\ProposalPortalController::class, 'show']);
        Route::get('/p/{token}/pdf',       [\App\Http\Controllers\ProposalPortalController::class, 'pdf']);
        // P-E.1.1: deck HTML (pixel-fiel, rastreável por página) + tracking de leitura
        Route::get('/p/{token}/deck-html', [\App\Http\Controllers\ProposalPortalController::class, 'deckHtml']);
        Route::post('/p/{token}/pagina',   [\App\Http\Controllers\ProposalPortalController::class, 'registrarPagina']);
        Route::post('/p/{token}/heartbeat', [\App\Http\Controllers\ProposalPortalController::class, 'heartbeat']);
        // P-C.1: tracking de seções (section_entered/exited + duração)
        Route::post('/p/{token}/secao',     [\App\Http\Controllers\ProposalPortalController::class, 'registrarSecao']);
        // P-C.2: comentários e revisões por seção
        Route::get('/p/{token}/secao/{key}/threads',  [\App\Http\Controllers\ProposalPortalController::class, 'threads']);
        Route::post('/p/{token}/secao/{key}/threads', [\App\Http\Controllers\ProposalPortalController::class, 'abrirThread']);
        Route::post('/p/{token}/threads/{thread}/mensagens', [\App\Http\Controllers\ProposalPortalController::class, 'comentar']);
        Route::post('/p/{token}/threads/{thread}/resolver',  [\App\Http\Controllers\ProposalPortalController::class, 'resolverThread']);
        // P-B: cliente adiciona participantes pelo portal
        Route::post('/p/{token}/identificar', [\App\Http\Controllers\ProposalPortalController::class, 'identificar']);
        Route::post('/p/{token}/participantes', [\App\Http\Controllers\ProposalPortalController::class, 'adicionarParticipante']);
        // Fluxo P-A: Análise → Revisão → Aprovação → Assinatura (Approver ≠ Signer)
        Route::post('/p/{token}/revisao',  [\App\Http\Controllers\ProposalPortalController::class, 'solicitarRevisao']);
        Route::post('/p/{token}/aprovar',  [\App\Http\Controllers\ProposalPortalController::class, 'aprovar']);
        Route::post('/p/{token}/assinar',  [\App\Http\Controllers\ProposalPortalController::class, 'assinar']);
        Route::post('/p/{token}/iniciar-assinatura', [\App\Http\Controllers\ProposalPortalController::class, 'iniciarAssinatura']);
        Route::post('/p/{token}/sincronizar', [\App\Http\Controllers\ProposalPortalController::class, 'sincronizar']);
        Route::post('/p/{token}/reject',   [\App\Http\Controllers\ProposalPortalController::class, 'reject']);

        // 🎫 HELP DESK — aceite/recusa da solução pelo cliente via link ASSINADO (sem login).
        Route::get('/hd/aceite/{ticket}',          [\App\Http\Controllers\HelpDeskAcceptController::class, 'show'])->name('hd.accept');
        Route::post('/hd/aceite/{ticket}/encerrar', [\App\Http\Controllers\HelpDeskAcceptController::class, 'accept'])->name('hd.accept.do');
        Route::post('/hd/aceite/{ticket}/recusar',  [\App\Http\Controllers\HelpDeskAcceptController::class, 'reject'])->name('hd.reject.do');
    });

    /**
     * @OA\Get(
     *     path="/api/v1/health",
     *     tags={"Sistema"},
     *     summary="Status da API",
     *     description="Verifica se a API está funcionando",
     *     @OA\Response(
     *         response=200,
     *         description="API funcionando corretamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="ok"),
     *             @OA\Property(property="message", type="string", example="API funcionando corretamente"),
     *             @OA\Property(property="timestamp", type="string", example="2024-01-01T00:00:00.000000Z"),
     *             @OA\Property(property="version", type="string", example="1.0.0")
     *         )
     *     )
     * )
     */
    // Rota para verificar se API está funcionando
    Route::get('/health', function () {
        return response()->json([
            'status'    => 'ok',
            'timestamp' => now(),
        ]);
    })->name('api.health');

    // Rotas protegidas (com autenticação Sanctum)
    // 👤 CADASTRO PÚBLICO DE CANDIDATO — sem auth, com throttle pra evitar spam
    Route::get('/candidates/form-data', [CandidateController::class, 'formData'])
        ->middleware('throttle:30,1')
        ->name('candidates.form-data');
    Route::post('/candidates',          [CandidateController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('candidates.store');

    // ===== Meu Dia (Central de Notificações / Ações / Tarefas / Comunicações) — público =====

    // Resposta de 1 clique vinda do e-mail (link assinado, sem login) — botões de decisão no e-mail.
    Route::get('/notifications/{notification}/respond-email', [\App\Http\Controllers\NotificationController::class, 'respondEmail'])
        ->middleware('signed')->name('notifications.respond-email');
    // 🔗 Callback OAuth Microsoft 365 (público — o browser é redirecionado pela Microsoft;
    // o usuário é identificado pelo `state` assinado, não pelo bearer token).
    Route::get('/integrations/microsoft/callback', [\App\Http\Controllers\UserIntegrationController::class, 'callback'])
        ->middleware('throttle:30,1')->name('integrations.microsoft.callback');

    // 🧠 BANCO DE COMPETÊNCIAS — portal público (Parceiros / Banco de Talentos), sem login.
    // Autosave/retomada via continue_token (guardado no navegador do respondente).
    Route::get('/skills-form/{token}',                          [\App\Http\Controllers\SkillFormController::class, 'show']);
    Route::post('/skills-form/{token}/upload',                  [\App\Http\Controllers\SkillFormController::class, 'upload']);
    Route::post('/skills-form/{token}/start',                   [\App\Http\Controllers\SkillFormController::class, 'start']);
    Route::get('/skills-form/continue/{continueToken}',         [\App\Http\Controllers\SkillFormController::class, 'resume']);
    Route::patch('/skills-form/continue/{continueToken}',       [\App\Http\Controllers\SkillFormController::class, 'autosave']);
    Route::post('/skills-form/continue/{continueToken}/submit', [\App\Http\Controllers\SkillFormController::class, 'submit']);

    Route::middleware(['auth:sanctum', 'company.context', 'cliente.module'])->group(function () {
        // ===== Multi-empresa: contexto do usuário (troca de empresa sem logout) =====
        Route::get('/my-companies', [\App\Http\Controllers\CompanyController::class, 'myCompanies'])->name('companies.mine');
        Route::post('/set-company', [\App\Http\Controllers\CompanyController::class, 'setCompany'])->name('companies.set');
        // Módulo Empresas (gestão administrativa — admin)
        Route::get('/companies',                       [\App\Http\Controllers\CompanyController::class, 'index']);
        Route::post('/companies',                      [\App\Http\Controllers\CompanyController::class, 'store']);
        Route::put('/companies/{company}',             [\App\Http\Controllers\CompanyController::class, 'update']);
        Route::get('/companies/{company}/users',       [\App\Http\Controllers\CompanyController::class, 'companyUsers']);
        Route::post('/companies/{company}/users',      [\App\Http\Controllers\CompanyController::class, 'attachUser']);
        Route::put('/companies/{company}/users/{user}',    [\App\Http\Controllers\CompanyController::class, 'updateUserRole']);
        Route::delete('/companies/{company}/users/{user}', [\App\Http\Controllers\CompanyController::class, 'detachUser']);

        // ===== Ver como (impersonation) =====
        Route::get('/impersonate/kinds',      [\App\Http\Controllers\ImpersonationController::class, 'kinds'])->name('impersonate.kinds');
        Route::get('/impersonate/candidates', [\App\Http\Controllers\ImpersonationController::class, 'candidates'])->name('impersonate.candidates');
        Route::get('/impersonate/partners',   [\App\Http\Controllers\ImpersonationController::class, 'partners'])->name('impersonate.partners');
        Route::post('/impersonate',           [\App\Http\Controllers\ImpersonationController::class, 'impersonate'])->name('impersonate.start');

        // ===== Work Sessions (Modo Atendimento) =====
        Route::post('/work-sessions',                    [\App\Http\Controllers\WorkSessionController::class, 'start']);
        Route::post('/work-sessions/{session}/events',   [\App\Http\Controllers\WorkSessionController::class, 'event']);
        Route::post('/work-sessions/{session}/end',      [\App\Http\Controllers\WorkSessionController::class, 'end']);
        Route::get('/work-sessions/{session}/summary',   [\App\Http\Controllers\WorkSessionController::class, 'summary']);

        // ===== HELP DESK (módulo nativo — chamados no Minutor) =====
        Route::get('/help-desk/access-profiles',                     [\App\Http\Controllers\HelpDeskAccessProfileController::class, 'index']);
        Route::post('/help-desk/access-profiles',                    [\App\Http\Controllers\HelpDeskAccessProfileController::class, 'store']);
        Route::get('/help-desk/agents', [\App\Http\Controllers\HelpDeskController::class, 'agents']);
        Route::get('/help-desk/association-rules',                    [\App\Http\Controllers\HelpDeskAssociationRuleController::class, 'index']);
        Route::post('/help-desk/association-rules',                   [\App\Http\Controllers\HelpDeskAssociationRuleController::class, 'store']);
        Route::get('/help-desk/categories',              [\App\Http\Controllers\HelpDeskCategoryController::class, 'index']);
        Route::post('/help-desk/categories',             [\App\Http\Controllers\HelpDeskCategoryController::class, 'store']);
        Route::get('/help-desk/comm-template',  [\App\Http\Controllers\HelpDeskTriggerController::class, 'commTemplate']);
        Route::put('/help-desk/comm-template',  [\App\Http\Controllers\HelpDeskTriggerController::class, 'updateCommTemplate']);
        Route::get('/help-desk/contacts', [\App\Http\Controllers\HelpDeskTicketController::class, 'searchContacts']);
        Route::get('/help-desk/email-accounts',                      [\App\Http\Controllers\HelpDeskEmailAccountController::class, 'index']);
        Route::post('/help-desk/email-accounts',                     [\App\Http\Controllers\HelpDeskEmailAccountController::class, 'store']);
        Route::get('/help-desk/email-suggestions', [\App\Http\Controllers\HelpDeskTicketController::class, 'emailSuggestions']);
        Route::get('/help-desk/justifications',                      [\App\Http\Controllers\HelpDeskTicketJustificationController::class, 'index']);
        Route::post('/help-desk/justifications',                     [\App\Http\Controllers\HelpDeskTicketJustificationController::class, 'store']);
        Route::get('/help-desk/meta',   [\App\Http\Controllers\HelpDeskController::class, 'meta']);
        Route::get('/help-desk/integration-customers', [\App\Http\Controllers\HelpDeskController::class, 'integrationCustomers']);
        Route::get('/help-desk/ops', [\App\Http\Controllers\HelpDeskOpsController::class, 'index']);
        Route::get('/help-desk/people',                              [\App\Http\Controllers\HelpDeskAccessProfileController::class, 'people']);
        Route::get('/help-desk/playbooks', [\App\Http\Controllers\HelpDeskPlaybookController::class, 'index']);
        Route::post('/help-desk/playbooks',              [\App\Http\Controllers\HelpDeskPlaybookController::class, 'store']);
        Route::get('/help-desk/services',                [\App\Http\Controllers\HelpDeskServiceController::class, 'index']);
        Route::post('/help-desk/services',               [\App\Http\Controllers\HelpDeskServiceController::class, 'store']);
        Route::get('/help-desk/forms',                   [\App\Http\Controllers\HelpDeskFormController::class, 'index']);
        Route::post('/help-desk/forms',                  [\App\Http\Controllers\HelpDeskFormController::class, 'store']);
        Route::get('/help-desk/forms/{form}',            [\App\Http\Controllers\HelpDeskFormController::class, 'show']);
        Route::put('/help-desk/forms/{form}',            [\App\Http\Controllers\HelpDeskFormController::class, 'update']);
        Route::delete('/help-desk/forms/{form}',         [\App\Http\Controllers\HelpDeskFormController::class, 'destroy']);
        Route::get('/help-desk/sla-policies',            [\App\Http\Controllers\HelpDeskSlaController::class, 'index']);
        Route::post('/help-desk/sla-policies',           [\App\Http\Controllers\HelpDeskSlaController::class, 'store']);
        Route::get('/help-desk/statuses',                [\App\Http\Controllers\HelpDeskStatusController::class, 'index']);
        Route::post('/help-desk/statuses',               [\App\Http\Controllers\HelpDeskStatusController::class, 'store']);
        Route::get('/help-desk/tags',                    [\App\Http\Controllers\HelpDeskTagController::class, 'index']);
        Route::post('/help-desk/tags',                   [\App\Http\Controllers\HelpDeskTagController::class, 'store']);
        Route::get('/help-desk/teams',                   [\App\Http\Controllers\HelpDeskTeamController::class, 'index']);
        Route::post('/help-desk/teams',                  [\App\Http\Controllers\HelpDeskTeamController::class, 'store']);
        Route::get('/help-desk/tickets',                 [\App\Http\Controllers\HelpDeskTicketController::class, 'index']);
        Route::post('/help-desk/tickets',                [\App\Http\Controllers\HelpDeskTicketController::class, 'store']);

        // Central de Reuniões
        Route::get('/meetings/suggested-participants', [\App\Http\Controllers\MeetingController::class, 'suggestedParticipants']);
        Route::get('/meetings/calendar',            [\App\Http\Controllers\MeetingController::class, 'calendarEvents']);
        Route::get('/meetings',                     [\App\Http\Controllers\MeetingController::class, 'index']);
        Route::post('/meetings',                    [\App\Http\Controllers\MeetingController::class, 'store']);
        Route::get('/meetings/{meeting}',           [\App\Http\Controllers\MeetingController::class, 'show']);
        Route::put('/meetings/{meeting}',           [\App\Http\Controllers\MeetingController::class, 'reschedule']);
        Route::post('/meetings/{meeting}/cancel',   [\App\Http\Controllers\MeetingController::class, 'cancel']);
        Route::delete('/meetings/{meeting}',        [\App\Http\Controllers\MeetingController::class, 'destroy']);
        Route::post('/meetings/{meeting}/start',    [\App\Http\Controllers\MeetingController::class, 'start']);
        Route::post('/meetings/{meeting}/end',      [\App\Http\Controllers\MeetingController::class, 'end']);
        Route::post('/meetings/{meeting}/log-hours', [\App\Http\Controllers\MeetingController::class, 'logHours']);
        Route::post('/meetings/{meeting}/summary',   [\App\Http\Controllers\MeetingController::class, 'saveSummary']);
        Route::post('/meetings/{meeting}/ata',       [\App\Http\Controllers\MeetingController::class, 'saveAta']);
        Route::post('/meetings/{meeting}/note-to-ticket', [\App\Http\Controllers\MeetingController::class, 'noteToTicket']);
        Route::post('/meetings/{meeting}/generate-notes', [\App\Http\Controllers\MeetingController::class, 'generateNotes']);
        Route::get('/help-desk/triggers',             [\App\Http\Controllers\HelpDeskTriggerController::class, 'index']);
        Route::post('/help-desk/triggers',            [\App\Http\Controllers\HelpDeskTriggerController::class, 'store']);
        Route::post('/help-desk/usage', [\App\Http\Controllers\HelpDeskUsageController::class, 'record']);
        Route::put('/help-desk/access-profiles/{accessProfile}',     [\App\Http\Controllers\HelpDeskAccessProfileController::class, 'update']);
        Route::delete('/help-desk/access-profiles/{accessProfile}',  [\App\Http\Controllers\HelpDeskAccessProfileController::class, 'destroy']);
        Route::put('/help-desk/association-rules/{associationRule}',  [\App\Http\Controllers\HelpDeskAssociationRuleController::class, 'update']);
        Route::delete('/help-desk/association-rules/{associationRule}', [\App\Http\Controllers\HelpDeskAssociationRuleController::class, 'destroy']);
        Route::put('/help-desk/categories/{category}',   [\App\Http\Controllers\HelpDeskCategoryController::class, 'update']);
        Route::delete('/help-desk/categories/{category}', [\App\Http\Controllers\HelpDeskCategoryController::class, 'destroy']);
        Route::put('/help-desk/email-accounts/{emailAccount}',       [\App\Http\Controllers\HelpDeskEmailAccountController::class, 'update']);
        Route::delete('/help-desk/email-accounts/{emailAccount}',    [\App\Http\Controllers\HelpDeskEmailAccountController::class, 'destroy']);
        Route::put('/help-desk/justifications/{justification}',      [\App\Http\Controllers\HelpDeskTicketJustificationController::class, 'update']);
        Route::delete('/help-desk/justifications/{justification}',   [\App\Http\Controllers\HelpDeskTicketJustificationController::class, 'destroy']);
        Route::get('/help-desk/kb/articles',             [\App\Http\Controllers\HelpDeskKbArticleController::class, 'index']);
        Route::post('/help-desk/kb/articles',            [\App\Http\Controllers\HelpDeskKbArticleController::class, 'store']);
        Route::get('/help-desk/kb/categories',           [\App\Http\Controllers\HelpDeskKbCategoryController::class, 'index']);
        Route::post('/help-desk/kb/categories',          [\App\Http\Controllers\HelpDeskKbCategoryController::class, 'store']);
        Route::get('/help-desk/playbooks/manage',        [\App\Http\Controllers\HelpDeskPlaybookController::class, 'adminIndex']);
        Route::put('/help-desk/playbooks/{playbook}',    [\App\Http\Controllers\HelpDeskPlaybookController::class, 'update']);
        Route::delete('/help-desk/playbooks/{playbook}', [\App\Http\Controllers\HelpDeskPlaybookController::class, 'destroy']);
        Route::get('/help-desk/portal/kb',               [\App\Http\Controllers\HelpDeskPortalController::class, 'kbIndex']);
        Route::get('/help-desk/portal/permissions',      [\App\Http\Controllers\HelpDeskPortalController::class, 'permissions']);
        Route::get('/help-desk/portal/tickets',          [\App\Http\Controllers\HelpDeskPortalController::class, 'myTickets']);
        Route::get('/help-desk/portal/meetings',          [\App\Http\Controllers\MeetingController::class, 'portalMeetings']);
        Route::post('/help-desk/portal/tickets',         [\App\Http\Controllers\HelpDeskPortalController::class, 'openTicket']);
        Route::put('/help-desk/services/{service}',      [\App\Http\Controllers\HelpDeskServiceController::class, 'update']);
        Route::delete('/help-desk/services/{service}',   [\App\Http\Controllers\HelpDeskServiceController::class, 'destroy']);
        Route::get('/help-desk/sla-policies/{policy}',   [\App\Http\Controllers\HelpDeskSlaController::class, 'show']);
        Route::put('/help-desk/sla-policies/{policy}',   [\App\Http\Controllers\HelpDeskSlaController::class, 'update']);
        Route::post('/help-desk/sla-policies/{policy}/duplicate', [\App\Http\Controllers\HelpDeskSlaController::class, 'duplicate']);
        Route::delete('/help-desk/sla-policies/{policy}', [\App\Http\Controllers\HelpDeskSlaController::class, 'destroy']);
        Route::put('/help-desk/statuses/{status}',       [\App\Http\Controllers\HelpDeskStatusController::class, 'update']);
        Route::delete('/help-desk/statuses/{status}',    [\App\Http\Controllers\HelpDeskStatusController::class, 'destroy']);
        Route::delete('/help-desk/tags/{tag}',           [\App\Http\Controllers\HelpDeskTagController::class, 'destroy']);
        Route::put('/help-desk/teams/{team}',            [\App\Http\Controllers\HelpDeskTeamController::class, 'update']);
        Route::delete('/help-desk/teams/{team}',         [\App\Http\Controllers\HelpDeskTeamController::class, 'destroy']);
        Route::post('/help-desk/tickets/redistribute', [\App\Http\Controllers\HelpDeskTicketController::class, 'bulkAssign']);
        Route::post('/help-desk/tickets/bulk', [\App\Http\Controllers\HelpDeskTicketController::class, 'bulkUpdate']);
        Route::get('/help-desk/tickets/{ticket}',        [\App\Http\Controllers\HelpDeskTicketController::class, 'show'])->whereNumber('ticket');
        Route::get('/help-desk/tickets/{ticket}/detail',  [\App\Http\Controllers\HelpDeskTicketController::class, 'detail'])->whereNumber('ticket');
        Route::put('/help-desk/tickets/{ticket}',        [\App\Http\Controllers\HelpDeskTicketController::class, 'update']);
        Route::delete('/help-desk/tickets/{ticket}',     [\App\Http\Controllers\HelpDeskTicketController::class, 'destroy']);
        Route::post('/help-desk/triggers/apply-recipe', [\App\Http\Controllers\HelpDeskTriggerController::class, 'applyRecipe']);
        Route::get('/help-desk/triggers/email-templates', [\App\Http\Controllers\HelpDeskTriggerController::class, 'emailTemplates']);
        Route::get('/help-desk/triggers/meta',        [\App\Http\Controllers\HelpDeskTriggerController::class, 'meta']);
        Route::post('/help-desk/triggers/preview-email', [\App\Http\Controllers\HelpDeskTriggerController::class, 'previewEmail']);
        Route::get('/help-desk/triggers/recipes',     [\App\Http\Controllers\HelpDeskTriggerController::class, 'recipes']);
        Route::put('/help-desk/triggers/{trigger}',   [\App\Http\Controllers\HelpDeskTriggerController::class, 'update']);
        Route::delete('/help-desk/triggers/{trigger}', [\App\Http\Controllers\HelpDeskTriggerController::class, 'destroy']);
        Route::get('/help-desk/customers/{customer}/context', [\App\Http\Controllers\HelpDeskTicketController::class, 'customerContext']);
        Route::post('/help-desk/email-accounts/{emailAccount}/test', [\App\Http\Controllers\HelpDeskEmailAccountController::class, 'test']);
        Route::get('/help-desk/kb/articles/{article}',   [\App\Http\Controllers\HelpDeskKbArticleController::class, 'show']);
        Route::put('/help-desk/kb/articles/{article}',   [\App\Http\Controllers\HelpDeskKbArticleController::class, 'update']);
        Route::delete('/help-desk/kb/articles/{article}', [\App\Http\Controllers\HelpDeskKbArticleController::class, 'destroy']);
        Route::put('/help-desk/kb/categories/{kbCategory}', [\App\Http\Controllers\HelpDeskKbCategoryController::class, 'update']);
        Route::delete('/help-desk/kb/categories/{kbCategory}', [\App\Http\Controllers\HelpDeskKbCategoryController::class, 'destroy']);
        Route::patch('/help-desk/people/access-profile/bulk',        [\App\Http\Controllers\HelpDeskAccessProfileController::class, 'bulkSetAccessProfile']);
        Route::patch('/help-desk/people/{user}/access-profile',      [\App\Http\Controllers\HelpDeskAccessProfileController::class, 'setAccessProfile']);
        // Departamentos do Help Desk (escopo por cliente) + vínculo pessoa↔departamento.
        // Gestão interna — block.cliente barra o perfil cliente no backend (2ª camada).
        Route::middleware('block.cliente')->group(function () {
            Route::get('/help-desk/departments',                     [\App\Http\Controllers\HelpDeskDepartmentController::class, 'index']);
            Route::post('/help-desk/departments',                    [\App\Http\Controllers\HelpDeskDepartmentController::class, 'store']);
            Route::put('/help-desk/departments/{department}',        [\App\Http\Controllers\HelpDeskDepartmentController::class, 'update']);
            Route::delete('/help-desk/departments/{department}',     [\App\Http\Controllers\HelpDeskDepartmentController::class, 'destroy']);
            Route::patch('/help-desk/people/{user}/department',      [\App\Http\Controllers\HelpDeskDepartmentController::class, 'setPersonDepartment']);
        });
        Route::get('/help-desk/portal/kb/{article}',     [\App\Http\Controllers\HelpDeskPortalController::class, 'kbShow']);
        Route::get('/help-desk/portal/tickets/{ticket}', [\App\Http\Controllers\HelpDeskPortalController::class, 'showTicket']);
        Route::patch('/help-desk/tickets/{ticket}/assign', [\App\Http\Controllers\HelpDeskTicketController::class, 'assign']);
        Route::get('/help-desk/tickets/{ticket}/attachments', [\App\Http\Controllers\HelpDeskTicketController::class, 'attachments']);
        Route::post('/help-desk/tickets/{ticket}/attachments', [\App\Http\Controllers\HelpDeskTicketController::class, 'uploadAttachment']);
        Route::get('/help-desk/tickets/{ticket}/comments', [\App\Http\Controllers\HelpDeskTicketController::class, 'comments']);
        Route::post('/help-desk/tickets/{ticket}/comments', [\App\Http\Controllers\HelpDeskTicketController::class, 'addComment']);
        Route::get('/help-desk/tickets/{ticket}/context', [\App\Http\Controllers\HelpDeskTicketController::class, 'context']);
        Route::post('/help-desk/tickets/{ticket}/finalize', [\App\Http\Controllers\HelpDeskTicketController::class, 'finalize']);
        Route::patch('/help-desk/tickets/{ticket}/status', [\App\Http\Controllers\HelpDeskTicketController::class, 'changeStatus']);
        Route::get('/help-desk/tickets/{ticket}/timeline', [\App\Http\Controllers\HelpDeskTicketController::class, 'timeline']);
        Route::get('/help-desk/tickets/{ticket}/timesheets', [\App\Http\Controllers\HelpDeskTicketController::class, 'timesheets']);
        Route::get('/help-desk/kb/articles/{article}/attachments', [\App\Http\Controllers\HelpDeskKbArticleController::class, 'attachments']);
        Route::post('/help-desk/kb/articles/{article}/attachments', [\App\Http\Controllers\HelpDeskKbArticleController::class, 'uploadAttachment']);
        Route::post('/help-desk/kb/articles/{article}/publish', [\App\Http\Controllers\HelpDeskKbArticleController::class, 'publish']);
        Route::post('/help-desk/portal/kb/{article}/feedback', [\App\Http\Controllers\HelpDeskPortalController::class, 'kbFeedback']);
        Route::get('/help-desk/portal/tickets/{ticket}/attachments', [\App\Http\Controllers\HelpDeskPortalController::class, 'attachments']);
        Route::post('/help-desk/portal/tickets/{ticket}/attachments', [\App\Http\Controllers\HelpDeskPortalController::class, 'uploadAttachment']);
        Route::post('/help-desk/portal/tickets/{ticket}/comments', [\App\Http\Controllers\HelpDeskPortalController::class, 'addComment']);
        Route::delete('/help-desk/tickets/{ticket}/attachments/{attachment}', [\App\Http\Controllers\HelpDeskTicketController::class, 'deleteAttachment']);
        Route::patch('/help-desk/tickets/{ticket}/comments/{comment}', [\App\Http\Controllers\HelpDeskTicketController::class, 'updateComment']);
        Route::delete('/help-desk/kb/articles/{article}/attachments/{attachment}', [\App\Http\Controllers\HelpDeskKbArticleController::class, 'deleteAttachment']);
        Route::delete('/help-desk/portal/tickets/{ticket}/attachments/{attachment}', [\App\Http\Controllers\HelpDeskPortalController::class, 'deleteAttachment']);
        Route::get('/help-desk/tickets/{ticket}/attachments/{attachment}/download', [\App\Http\Controllers\HelpDeskTicketController::class, 'downloadAttachment']);
        Route::post('/help-desk/tickets/{ticket}/comments/{comment}/attachments', [\App\Http\Controllers\HelpDeskTicketController::class, 'addCommentAttachment']);
        Route::post('/help-desk/tickets/{ticket}/playbooks/{playbook}/execute', [\App\Http\Controllers\HelpDeskTicketController::class, 'executePlaybook']);
        Route::get('/help-desk/kb/articles/{article}/attachments/{attachment}/download', [\App\Http\Controllers\HelpDeskKbArticleController::class, 'downloadAttachment']);
        Route::get('/help-desk/portal/tickets/{ticket}/attachments/{attachment}/download', [\App\Http\Controllers\HelpDeskPortalController::class, 'downloadAttachment']);
        Route::get('/help-desk/tickets/{ticket}/comments/{comment}/attachments/{attachment}/download', [\App\Http\Controllers\HelpDeskTicketController::class, 'downloadCommentAttachment']);

        // 🎫 HELP DESK — rotas trazidas da Replica (menu Opções, SLA, relatório, clone, agendamento,
        // e-mail avulso, mesclagem, portal, colunas, KB suggest/candidatar). Promoção pra homolog.
        Route::get('/help-desk/tickets/search',          [\App\Http\Controllers\HelpDeskTicketController::class, 'search']);
        Route::get('/help-desk/tickets/{ticket}/details', [\App\Http\Controllers\HelpDeskTicketController::class, 'details']);
        Route::get('/help-desk/tickets/{ticket}/sla',    [\App\Http\Controllers\HelpDeskTicketController::class, 'sla']);
        Route::get('/help-desk/tickets/{ticket}/apontamentos', [\App\Http\Controllers\HelpDeskTicketController::class, 'apontamentos']);
        Route::get('/help-desk/tickets/{ticket}/report', [\App\Http\Controllers\HelpDeskTicketController::class, 'report']);
        Route::post('/help-desk/tickets/{ticket}/clone', [\App\Http\Controllers\HelpDeskTicketController::class, 'clone']);
        Route::post('/help-desk/tickets/{ticket}/send-email', [\App\Http\Controllers\HelpDeskTicketController::class, 'sendEmail']);
        Route::post('/help-desk/tickets/{ticket}/schedule', [\App\Http\Controllers\HelpDeskTicketController::class, 'schedule']);
        Route::delete('/help-desk/tickets/{ticket}/schedule', [\App\Http\Controllers\HelpDeskTicketController::class, 'unschedule']);
        Route::post('/help-desk/tickets/{ticket}/schedule-reopen', [\App\Http\Controllers\HelpDeskTicketController::class, 'scheduleReopen']);
        Route::delete('/help-desk/tickets/{ticket}/schedule-reopen', [\App\Http\Controllers\HelpDeskTicketController::class, 'cancelScheduledReopen']);
        Route::post('/help-desk/tickets/{ticket}/comments/{comment}/to-kb', [\App\Http\Controllers\HelpDeskTicketController::class, 'commentToKb']);
        Route::get('/help-desk/kb/suggest',              [\App\Http\Controllers\HelpDeskKbArticleController::class, 'suggest']);
        Route::post('/help-desk/tickets/merge',          [\App\Http\Controllers\HelpDeskMergeController::class, 'merge']);
        Route::post('/help-desk/tickets/{target}/unmerge/{source}', [\App\Http\Controllers\HelpDeskMergeController::class, 'unmerge']);
        Route::get('/help-desk/tickets/{target}/merged', [\App\Http\Controllers\HelpDeskMergeController::class, 'mergedList']);
        Route::get('/help-desk/portal-columns',          [\App\Http\Controllers\HelpDeskPortalColumnsController::class, 'index']);
        Route::put('/help-desk/portal-columns',          [\App\Http\Controllers\HelpDeskPortalColumnsController::class, 'update']);
        Route::get('/help-desk/portal/columns',          [\App\Http\Controllers\HelpDeskPortalColumnsController::class, 'portal']);
        Route::get('/help-desk/portal/statuses',         [\App\Http\Controllers\HelpDeskPortalController::class, 'statuses']);
        Route::post('/help-desk/portal/tickets/{ticket}/accept', [\App\Http\Controllers\HelpDeskPortalController::class, 'accept']);
        Route::post('/help-desk/portal/tickets/{ticket}/reject', [\App\Http\Controllers\HelpDeskPortalController::class, 'reject']);
        Route::get('/help-desk/portal/tickets/{ticket}/comments/{comment}/attachments/{attachment}/download', [\App\Http\Controllers\HelpDeskPortalController::class, 'downloadCommentAttachment']);

        // ===== Meu Dia: Central de Notificações + Ações + Tarefas + Calendário + Comunicações =====
        // Central de Notificações (tela inicial — só usuários internos)
        Route::get('/notifications',              [\App\Http\Controllers\NotificationController::class, 'index']);
        Route::get('/notifications/actions',      [\App\Http\Controllers\ApprovalController::class, 'homeActions']);
        Route::get('/me/badges',                  [\App\Http\Controllers\NotificationController::class, 'badges']);
        Route::get('/notifications/stream',       [\App\Http\Controllers\NotificationController::class, 'stream']);
        Route::get('/notifications/manage',       [\App\Http\Controllers\NotificationController::class, 'manage']);
        Route::get('/notifications/meta',         [\App\Http\Controllers\NotificationController::class, 'meta']);
        Route::get('/notifications/users',        [\App\Http\Controllers\NotificationController::class, 'searchUsers']);
        Route::post('/notifications/preview',     [\App\Http\Controllers\NotificationController::class, 'preview']);
        Route::post('/notifications',             [\App\Http\Controllers\NotificationController::class, 'store']);
        Route::put('/notifications/{notification}',    [\App\Http\Controllers\NotificationController::class, 'update']);
        Route::delete('/notifications/{notification}', [\App\Http\Controllers\NotificationController::class, 'destroy']);
        Route::post('/notifications/{notification}/view', [\App\Http\Controllers\NotificationController::class, 'view']);
        Route::post('/notifications/{notification}/ack',  [\App\Http\Controllers\NotificationController::class, 'ack']);
        Route::post('/notifications/{notification}/respond', [\App\Http\Controllers\NotificationController::class, 'respond']);
        Route::get('/notifications/{notification}/log',   [\App\Http\Controllers\NotificationController::class, 'log']);
        Route::post('/notifications/{notification}/resend', [\App\Http\Controllers\NotificationController::class, 'resend']);

        // Rotina (admin): recorrência dos lembretes de ações não resolvidas.
        Route::get('/action-reminders',                [\App\Http\Controllers\ActionReminderController::class, 'index']);
        Route::get('/action-reminders/{key}/preview',  [\App\Http\Controllers\ActionReminderController::class, 'preview']);
        Route::put('/action-reminders/{key}',          [\App\Http\Controllers\ActionReminderController::class, 'update']);

        // Enquetes (polls) da Central de Notificações
        Route::post('/polls/{poll}/vote',   [\App\Http\Controllers\NotificationPollController::class, 'vote']);
        Route::get('/polls/{poll}/results', [\App\Http\Controllers\NotificationPollController::class, 'resultsEndpoint']);
        Route::get('/polls/{poll}/voters',  [\App\Http\Controllers\NotificationPollController::class, 'voters']);

        // Agenda / Calendário da tela inicial (eventos do mês)
        Route::get('/calendar/events', [\App\Http\Controllers\CalendarController::class, 'events']);
        Route::get('/calendar/visibility', [\App\Http\Controllers\CalendarController::class, 'visibility']);      // config visibilidade agenda
        Route::put('/calendar/visibility', [\App\Http\Controllers\CalendarController::class, 'saveVisibility']);  // salvar (admin)

        // Tarefas rápidas (Smart To-Do) pessoais
        Route::get('/tasks',              [\App\Http\Controllers\TaskController::class, 'index']);
        Route::get('/tasks/entities',     [\App\Http\Controllers\TaskController::class, 'entities']);
        Route::get('/tasks/users',        [\App\Http\Controllers\TaskController::class, 'users']);
        Route::get('/tasks/team',         [\App\Http\Controllers\TaskController::class, 'team']);
        Route::post('/tasks',             [\App\Http\Controllers\TaskController::class, 'store']);
        Route::patch('/tasks/{task}/resolve', [\App\Http\Controllers\TaskController::class, 'resolve']);
        Route::put('/tasks/{task}',       [\App\Http\Controllers\TaskController::class, 'update']);
        Route::delete('/tasks/{task}',    [\App\Http\Controllers\TaskController::class, 'destroy']);

        // Central de Comunicação (externa, com clientes)
        Route::get('/communications/mine',           [\App\Http\Controllers\CommunicationController::class, 'mine']);      // recebidas (endereçadas a mim)
        Route::get('/communications/feed',           [\App\Http\Controllers\CommunicationController::class, 'feed']);      // mural (tudo que posso ler)
        Route::get('/communications/{communication}/log', [\App\Http\Controllers\CommunicationController::class, 'log']); // log de leitura (admin)
        Route::get('/communications/unread',         [\App\Http\Controllers\CommunicationController::class, 'unread']);    // popup de prévia
        Route::post('/communications/mark-read',     [\App\Http\Controllers\CommunicationController::class, 'markRead']);  // marca lido
        Route::post('/communications/ack',           [\App\Http\Controllers\CommunicationController::class, 'ack']);       // confirma recebimento (requires_ack)
        Route::get('/communications/meta',           [\App\Http\Controllers\CommunicationController::class, 'meta']);
        Route::get('/communications/customer-users', [\App\Http\Controllers\CommunicationController::class, 'customerUsers']);
        Route::post('/communications/preview',       [\App\Http\Controllers\CommunicationController::class, 'preview']);
        Route::post('/communications/send',          [\App\Http\Controllers\CommunicationController::class, 'send']);
        Route::get('/communications',                [\App\Http\Controllers\CommunicationController::class, 'index']);
        Route::get('/distribution-lists',            [\App\Http\Controllers\DistributionListController::class, 'index']);
        Route::post('/distribution-lists',           [\App\Http\Controllers\DistributionListController::class, 'store']);
        Route::delete('/distribution-lists/{distributionList}', [\App\Http\Controllers\DistributionListController::class, 'destroy']);
        // Grupos de distribuição (estruturados em blocos por cliente)
        Route::get('/communication-groups',                          [\App\Http\Controllers\CommunicationGroupController::class, 'index']);
        Route::post('/communication-groups',                         [\App\Http\Controllers\CommunicationGroupController::class, 'store']);
        Route::get('/communication-groups/{group}',                  [\App\Http\Controllers\CommunicationGroupController::class, 'show']);
        Route::put('/communication-groups/{group}',                  [\App\Http\Controllers\CommunicationGroupController::class, 'update']);
        Route::delete('/communication-groups/{group}',               [\App\Http\Controllers\CommunicationGroupController::class, 'destroy']);
        Route::get('/communication-groups/{group}/resolve',          [\App\Http\Controllers\CommunicationGroupController::class, 'resolve']);
        Route::post('/communication-groups/{group}/blocks',          [\App\Http\Controllers\CommunicationGroupController::class, 'addBlock']);
        Route::put('/communication-groups/{group}/blocks/{block}',   [\App\Http\Controllers\CommunicationGroupController::class, 'saveBlock']);
        Route::delete('/communication-groups/{group}/blocks/{block}', [\App\Http\Controllers\CommunicationGroupController::class, 'deleteBlock']);
        Route::post('/communication-groups/{group}/blocks/{block}/copy', [\App\Http\Controllers\CommunicationGroupController::class, 'copyBlock']);
        Route::get('/communication-templates',       [\App\Http\Controllers\CommunicationTemplateController::class, 'index']);
        Route::post('/communication-templates',      [\App\Http\Controllers\CommunicationTemplateController::class, 'store']);
        Route::delete('/communication-templates/{communicationTemplate}', [\App\Http\Controllers\CommunicationTemplateController::class, 'destroy']);

        // Rotinas de Equipe (task_groups)
        Route::get('/task-groups',                       [\App\Http\Controllers\TaskGroupController::class, 'index']);
        Route::post('/task-groups',                      [\App\Http\Controllers\TaskGroupController::class, 'store']);
        Route::put('/task-groups/{taskGroup}',           [\App\Http\Controllers\TaskGroupController::class, 'update']);
        Route::delete('/task-groups/{taskGroup}',        [\App\Http\Controllers\TaskGroupController::class, 'destroy']);
        Route::post('/task-groups/{taskGroup}/generate', [\App\Http\Controllers\TaskGroupController::class, 'generate']);
        Route::get('/task-groups/{taskGroup}/tracking',  [\App\Http\Controllers\TaskGroupController::class, 'tracking']);

        // Integração Microsoft 365 / Outlook (OAuth delegado por usuário)
        Route::get('/integrations/microsoft/status',      [\App\Http\Controllers\UserIntegrationController::class, 'status']);
        Route::post('/integrations/microsoft/connect',    [\App\Http\Controllers\UserIntegrationController::class, 'connect']);
        Route::post('/integrations/microsoft/disconnect', [\App\Http\Controllers\UserIntegrationController::class, 'disconnect']);
        Route::post('/integrations/microsoft/sync',       [\App\Http\Controllers\UserIntegrationController::class, 'sync']);

        // Aniversários — parabenização entre a equipe
        Route::get('/birthdays/today',            [\App\Http\Controllers\BirthdayController::class, 'today']);
        Route::post('/birthdays/{user}/message',  [\App\Http\Controllers\BirthdayController::class, 'sendMessage']);
        Route::get('/birthdays/{user}/messages',  [\App\Http\Controllers\BirthdayController::class, 'messages']);

        // ===== 🤝 CRM + Módulos + Plataforma de Documentos (promovido p/ homolog) =====

        // 🤝 CRM — Fase 1A (cadastros base). Empresa = customers (não duplica).
        Route::get('/crm/products',  [\App\Http\Controllers\CrmProductController::class, 'index']);
        Route::post('/crm/products', [\App\Http\Controllers\CrmProductController::class, 'store']);
        Route::put('/crm/products/{crmProduct}',    [\App\Http\Controllers\CrmProductController::class, 'update']);
        Route::delete('/crm/products/{crmProduct}', [\App\Http\Controllers\CrmProductController::class, 'destroy']);
        Route::get('/crm/tags',  [\App\Http\Controllers\CustomerCrmController::class, 'tagsIndex']);
        Route::post('/crm/tags', [\App\Http\Controllers\CustomerCrmController::class, 'tagsStore']);
        Route::put('/crm/tags/{tag}', [\App\Http\Controllers\CustomerCrmController::class, 'tagsUpdate']);
        Route::delete('/crm/tags/{tag}', [\App\Http\Controllers\CustomerCrmController::class, 'tagsDestroy']);
        Route::get('/customers/{customer}/crm', [\App\Http\Controllers\CustomerCrmController::class, 'show']);
        // Solicitação de código-fonte (Fase 0) — fontes Git autorizadas do cliente (READ-ONLY)
        Route::get('/customers/{customer}/source-repos', [\App\Http\Controllers\ClientSourceRepoController::class, 'index']);
        Route::post('/customers/{customer}/source-repos', [\App\Http\Controllers\ClientSourceRepoController::class, 'store']);
        Route::get('/source-repos/available', [\App\Http\Controllers\ClientSourceRepoController::class, 'available']);
        // Solicitação de código-fonte (Fase 1A) — buscas
        Route::get('/source-code/clients', [\App\Http\Controllers\SourceCodeController::class, 'clients']);
        Route::get('/source-code/config', [\App\Http\Controllers\SourceCodeController::class, 'config']);
        Route::put('/source-code/config', [\App\Http\Controllers\SourceCodeController::class, 'saveConfig']);
        Route::get('/source-code/search', [\App\Http\Controllers\SourceCodeController::class, 'search']);
        Route::get('/source-code/tickets', [\App\Http\Controllers\SourceCodeController::class, 'tickets']);
        // Fase 1C — criar solicitação + anexar por item + finalizar
        Route::post('/source-code/requests', [\App\Http\Controllers\SourceCodeRequestController::class, 'store']);
        Route::post('/source-code/request-items/{sourceCodeRequestItem}/attach', [\App\Http\Controllers\SourceCodeRequestController::class, 'attachItem']);
        Route::post('/source-code/requests/{sourceCodeRequest}/finalize', [\App\Http\Controllers\SourceCodeRequestController::class, 'finalize']);
        Route::put('/source-repos/{sourceRepo}', [\App\Http\Controllers\ClientSourceRepoController::class, 'update']);
        Route::delete('/source-repos/{sourceRepo}', [\App\Http\Controllers\ClientSourceRepoController::class, 'destroy']);
        Route::post('/source-repos/{sourceRepo}/test', [\App\Http\Controllers\ClientSourceRepoController::class, 'test']);
        Route::post('/source-repos/{sourceRepo}/verify', [\App\Http\Controllers\ClientSourceRepoController::class, 'verify']);
        // Central de Fontes (C1) — catálogo de documentação de fonte (SOMENTE LEITURA).
        Route::middleware('permission.or.admin:source_docs.view')->group(function () {
            Route::get('/source-docs', [\App\Http\Controllers\SourceDocCatalogController::class, 'index']);
            // C2 — busca técnica (read-model)
            Route::get('/source-docs/search', [\App\Http\Controllers\SourceDocSearchController::class, 'search']);
            Route::get('/source-docs/search/suggest', [\App\Http\Controllers\SourceDocSearchController::class, 'suggest']);
            // C4b — análise de impacto (impacto no próprio escopo = source_docs.view; cross gateado no serviço)
            Route::get('/source-docs/impact', [\App\Http\Controllers\SourceDocImpactController::class, 'impact']);
            Route::get('/source-docs/{sourceDoc}/entities', [\App\Http\Controllers\SourceDocSearchController::class, 'entities'])->whereNumber('sourceDoc');
            Route::get('/source-docs/{sourceDoc}', [\App\Http\Controllers\SourceDocCatalogController::class, 'show'])->whereNumber('sourceDoc');
            Route::get('/source-docs/{sourceDoc}/documentation', [\App\Http\Controllers\SourceDocCatalogController::class, 'documentation'])->whereNumber('sourceDoc');
            Route::get('/source-docs/{sourceDoc}/versions', [\App\Http\Controllers\SourceDocCatalogController::class, 'versions'])->whereNumber('sourceDoc');
            // C3 — estado do reprocess é leitura (source_docs.view)
            Route::get('/source-docs/{sourceDoc}/execution', [\App\Http\Controllers\SourceDocActionController::class, 'execution'])->whereNumber('sourceDoc');
            // C3.5 — cobertura do acervo (leitura)
            Route::get('/source-docs/coverage', [\App\Http\Controllers\SourceDocCoverageController::class, 'coverage']);
            // F2 — Acervo: árvore lazy Empresa → Repositório → Diretório Git → Fonte (leitura, escopado).
            Route::get('/source-docs/tree/customers', [\App\Http\Controllers\SourceDocTreeController::class, 'customers']);
            Route::get('/source-docs/tree/customers/{customer}/repos', [\App\Http\Controllers\SourceDocTreeController::class, 'repos'])->whereNumber('customer');
            Route::get('/source-docs/tree/nodes', [\App\Http\Controllers\SourceDocTreeController::class, 'nodes']);
            // F4 — inteligência agregada do acervo por Empresa/Repo/Diretório (patrimônio já produzido; IA=US$0).
            Route::get('/source-docs/tree/knowledge', [\App\Http\Controllers\SourceDocTreeController::class, 'knowledge']);
        });
        // C3.5 — disparar inventário (Admin/ops, gate estrito)
        Route::middleware('permission:source_docs.inventory')->group(function () {
            Route::post('/source-docs/inventory', [\App\Http\Controllers\SourceDocCoverageController::class, 'inventory']);
        });
        // Campanha de Documentação Semântica Inicial (Admin) — baseline governado, interno ERPSERV.
        Route::middleware('permission:source_docs.semantic_campaign')->group(function () {
            Route::get('/source-docs/campaigns', [\App\Http\Controllers\SourceDocCampaignController::class, 'index']);
            Route::post('/source-docs/campaigns', [\App\Http\Controllers\SourceDocCampaignController::class, 'store']);
            Route::get('/source-docs/campaigns/{id}', [\App\Http\Controllers\SourceDocCampaignController::class, 'show'])->whereNumber('id');
            Route::post('/source-docs/campaigns/{id}/start', [\App\Http\Controllers\SourceDocCampaignController::class, 'start'])->whereNumber('id');
            Route::post('/source-docs/campaigns/{id}/pause', [\App\Http\Controllers\SourceDocCampaignController::class, 'pause'])->whereNumber('id');
            Route::post('/source-docs/campaigns/{id}/resume', [\App\Http\Controllers\SourceDocCampaignController::class, 'resume'])->whereNumber('id');
            Route::post('/source-docs/campaigns/{id}/cancel', [\App\Http\Controllers\SourceDocCampaignController::class, 'cancel'])->whereNumber('id');
        });
        // Frente A — Governança de custo de IA (Admin/interno ERPSERV; sem escopo de cliente).
        Route::middleware('permission:source_docs.cost_settings.view')->group(function () {
            Route::get('/source-docs/cost-settings', [\App\Http\Controllers\SourceDocCostSettingsController::class, 'index']);
        });
        Route::middleware('permission:source_docs.cost_settings.manage')->group(function () {
            Route::put('/source-docs/cost-settings', [\App\Http\Controllers\SourceDocCostSettingsController::class, 'update']);
        });
        Route::middleware('permission:source_docs.cost_approval.view')->group(function () {
            Route::get('/source-docs/cost-approvals', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'index']);
            Route::get('/source-docs/cost-approvals/{id}', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'show'])->whereNumber('id');
        });
        Route::middleware('permission:source_docs.cost_approval.decide')->group(function () {
            Route::post('/source-docs/cost-approvals/{id}/approve-step', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'approveStep'])->whereNumber('id');
            Route::post('/source-docs/cost-approvals/{id}/approve-limit', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'approveLimit'])->whereNumber('id');
            Route::post('/source-docs/cost-approvals/{id}/close-partial', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'closePartial'])->whereNumber('id');
            Route::post('/source-docs/cost-approvals/{id}/reject', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'reject'])->whereNumber('id');
        });
        // C3 — ações operacionais: uma permissão por ação (nada hardcoded no controller).
        Route::middleware('permission.or.admin:source_docs.validate')->group(function () {
            Route::post('/source-docs/{sourceDoc}/validate', [\App\Http\Controllers\SourceDocActionController::class, 'validate'])->whereNumber('sourceDoc');
        });
        // reprocess = gate ESTRITO (não auto-passa Coordenador; MVP = só Admin, data-driven).
        Route::middleware('permission:source_docs.reprocess')->group(function () {
            Route::get('/source-docs/{sourceDoc}/reprocess/plan', [\App\Http\Controllers\SourceDocActionController::class, 'reprocessPlan'])->whereNumber('sourceDoc');
            Route::post('/source-docs/{sourceDoc}/reprocess', [\App\Http\Controllers\SourceDocActionController::class, 'reprocess'])->whereNumber('sourceDoc');
            Route::post('/source-docs/{sourceDoc}/topup', [\App\Http\Controllers\SourceDocActionController::class, 'topup'])->whereNumber('sourceDoc');
        });
        Route::middleware('permission.or.admin:source_docs.download')->group(function () {
            Route::get('/source-docs/{sourceDoc}/render', [\App\Http\Controllers\SourceDocActionController::class, 'render'])->whereNumber('sourceDoc');
        });
        Route::middleware('permission.or.admin:source_docs.view_git')->group(function () {
            Route::get('/source-docs/{sourceDoc}/git-url', [\App\Http\Controllers\SourceDocActionController::class, 'gitUrl'])->whereNumber('sourceDoc');
            // F3 — código da versão atual (somente leitura), para a aba Código do Acervo.
            Route::get('/source-docs/{sourceDoc}/source', [\App\Http\Controllers\SourceDocActionController::class, 'source'])->whereNumber('sourceDoc');
        });
        Route::middleware('permission.or.admin:source_docs.view_diff')->group(function () {
            Route::get('/source-docs/{sourceDoc}/compare', [\App\Http\Controllers\SourceDocActionController::class, 'compare'])->whereNumber('sourceDoc');
        });
        Route::put('/customers/{customer}/crm', [\App\Http\Controllers\CustomerCrmController::class, 'update']);

        // 🤝 CRM — Fase 1B (Pipeline & Oportunidades)
        Route::get('/crm/pipelines', [\App\Http\Controllers\CrmPipelineController::class, 'index']);
        // CRM configurável (Fase 1) — gestão de pipelines/etapas
        Route::get('/crm/pipelines/manage', [\App\Http\Controllers\CrmPipelineController::class, 'manageIndex']);
        Route::post('/crm/pipelines', [\App\Http\Controllers\CrmPipelineController::class, 'storePipeline']);
        Route::patch('/crm/pipelines/reorder', [\App\Http\Controllers\CrmPipelineController::class, 'reorderPipelines']);
        Route::put('/crm/pipelines/{pipeline}', [\App\Http\Controllers\CrmPipelineController::class, 'updatePipeline']);
        Route::post('/crm/pipelines/{pipeline}/duplicate', [\App\Http\Controllers\CrmPipelineController::class, 'duplicatePipeline']);
        Route::delete('/crm/pipelines/{pipeline}', [\App\Http\Controllers\CrmPipelineController::class, 'destroyPipeline']);
        Route::get('/crm/pipeline-events', [\App\Http\Controllers\CrmPipelineController::class, 'events']);
        Route::post('/crm/pipelines/{pipeline}/stages', [\App\Http\Controllers\CrmPipelineController::class, 'storeStage']);
        Route::patch('/crm/pipelines/{pipeline}/stages/reorder', [\App\Http\Controllers\CrmPipelineController::class, 'reorderStages']);
        Route::put('/crm/pipeline-stages/{stage}', [\App\Http\Controllers\CrmPipelineController::class, 'updateStage']);
        Route::delete('/crm/pipeline-stages/{stage}', [\App\Http\Controllers\CrmPipelineController::class, 'destroyStage']);
        // Automações de etapa (Fase 3)
        Route::get('/crm/pipeline-stages/{stage}/automations', [\App\Http\Controllers\CrmStageAutomationController::class, 'index']);
        Route::post('/crm/pipeline-stages/{stage}/automations', [\App\Http\Controllers\CrmStageAutomationController::class, 'store']);
        Route::put('/crm/stage-automations/{automation}', [\App\Http\Controllers\CrmStageAutomationController::class, 'update']);
        Route::delete('/crm/stage-automations/{automation}', [\App\Http\Controllers\CrmStageAutomationController::class, 'destroy']);
        Route::get('/crm/users', [\App\Http\Controllers\CrmOpportunityController::class, 'crmUsers']);
        Route::get('/crm/responsaveis', [\App\Http\Controllers\CrmResponsavelController::class, 'index']);
        // Motor de Políticas de Acesso (genérico; CRM = Política Comercial)
        Route::get('/policies/{module}/catalog', [\App\Http\Controllers\PolicyController::class, 'catalog']);
        Route::get('/policies/{module}/roles', [\App\Http\Controllers\PolicyController::class, 'roles']);
        Route::post('/policies/{module}/roles', [\App\Http\Controllers\PolicyController::class, 'storeRole']);
        Route::put('/policies/{module}/roles/{role}', [\App\Http\Controllers\PolicyController::class, 'updateRole']);
        Route::delete('/policies/{module}/roles/{role}', [\App\Http\Controllers\PolicyController::class, 'destroyRole']);
        Route::get('/policies/{module}/users/{user}', [\App\Http\Controllers\PolicyController::class, 'userShow']);
        Route::put('/policies/{module}/users/{user}/assignment', [\App\Http\Controllers\PolicyController::class, 'setAssignment']);
        Route::put('/policies/{module}/users/{user}/overrides', [\App\Http\Controllers\PolicyController::class, 'setOverrides']);
        Route::get('/me/policies', [\App\Http\Controllers\PolicyController::class, 'me']);
        // Financeiro CRM (Política Comercial): Metas, Comissões, Rentabilidade
        Route::get('/crm/metas/cockpit', [\App\Http\Controllers\CrmFinanceController::class, 'cockpit']);
        Route::post('/crm/metas/duplicate', [\App\Http\Controllers\CrmFinanceController::class, 'duplicateMetas']);
        Route::get('/crm/metas/atual', [\App\Http\Controllers\CrmFinanceController::class, 'metaAtual']);
        Route::get('/crm/metas/historico', [\App\Http\Controllers\CrmFinanceController::class, 'metasHistorico']);
        Route::post('/crm/metas/importar', [\App\Http\Controllers\CrmFinanceController::class, 'importarMetas']);
        Route::get('/crm/metas', [\App\Http\Controllers\CrmFinanceController::class, 'metas']);
        Route::put('/crm/metas', [\App\Http\Controllers\CrmFinanceController::class, 'setMeta']);
        Route::get('/crm/comissoes/cockpit', [\App\Http\Controllers\CrmFinanceController::class, 'comissoesCockpit']);
        Route::post('/crm/comissoes/apurar', [\App\Http\Controllers\CrmFinanceController::class, 'apurar']);
        Route::get('/crm/comissoes/lancamentos', [\App\Http\Controllers\CrmFinanceController::class, 'lancamentos']);
        Route::post('/crm/comissoes/lancamentos/{commission}/status', [\App\Http\Controllers\CrmFinanceController::class, 'commissionStatus']);
        // Políticas de Comissão (regras condicionais de %) + simulador
        Route::get('/crm/comissoes/politicas', [\App\Http\Controllers\CrmCommissionPolicyController::class, 'index']);
        Route::post('/crm/comissoes/politicas', [\App\Http\Controllers\CrmCommissionPolicyController::class, 'store']);
        // Literais ANTES do {policy} para não colidir com o wildcard
        Route::put('/crm/comissoes/politicas/settings', [\App\Http\Controllers\CrmCommissionPolicyController::class, 'setSettings']);
        Route::put('/crm/comissoes/politicas/excecao', [\App\Http\Controllers\CrmCommissionPolicyController::class, 'setException']);
        Route::delete('/crm/comissoes/politicas/excecao/{user}', [\App\Http\Controllers\CrmCommissionPolicyController::class, 'deleteException']);
        Route::get('/crm/comissoes/politicas/historico', [\App\Http\Controllers\CrmCommissionPolicyController::class, 'rateHistory']);
        Route::put('/crm/comissoes/politicas/{policy}', [\App\Http\Controllers\CrmCommissionPolicyController::class, 'update']);
        Route::delete('/crm/comissoes/politicas/{policy}', [\App\Http\Controllers\CrmCommissionPolicyController::class, 'destroy']);
        Route::post('/crm/comissoes/simular', [\App\Http\Controllers\CrmCommissionPolicyController::class, 'simular']);
        // Equipes de Vendas (materializa o escopo "Equipe")
        Route::get('/crm/sales-teams', [\App\Http\Controllers\CrmSalesTeamController::class, 'index']);
        Route::post('/crm/sales-teams', [\App\Http\Controllers\CrmSalesTeamController::class, 'store']);
        Route::put('/crm/sales-teams/{team}', [\App\Http\Controllers\CrmSalesTeamController::class, 'update']);
        Route::delete('/crm/sales-teams/{team}', [\App\Http\Controllers\CrmSalesTeamController::class, 'destroy']);
        Route::get('/crm/comissoes', [\App\Http\Controllers\CrmFinanceController::class, 'comissoes']);
        Route::put('/crm/comissoes/rate', [\App\Http\Controllers\CrmFinanceController::class, 'setRate']);
        Route::get('/crm/rentabilidade', [\App\Http\Controllers\CrmFinanceController::class, 'rentabilidade']);
        Route::patch('/crm/rentabilidade/{opp}/custo', [\App\Http\Controllers\CrmFinanceController::class, 'setCusto']);
        Route::put('/crm/responsaveis/{user}', [\App\Http\Controllers\CrmResponsavelController::class, 'update']);
        Route::get('/crm/opportunities/kanban', [\App\Http\Controllers\CrmOpportunityController::class, 'kanban']);
        Route::get('/crm/opportunities/export', [\App\Http\Controllers\CrmOpportunityController::class, 'export']);
        Route::get('/crm/opportunities',  [\App\Http\Controllers\CrmOpportunityController::class, 'index']);
        Route::post('/crm/opportunities', [\App\Http\Controllers\CrmOpportunityController::class, 'store']);
        Route::get('/crm/opportunities/{opportunity}',  [\App\Http\Controllers\CrmOpportunityController::class, 'show']);
        Route::put('/crm/opportunities/{opportunity}',  [\App\Http\Controllers\CrmOpportunityController::class, 'update']);
        Route::patch('/crm/opportunities/{opportunity}/stage', [\App\Http\Controllers\CrmOpportunityController::class, 'moveStage']);
        Route::patch('/crm/opportunities/{opportunity}/status', [\App\Http\Controllers\CrmOpportunityController::class, 'mudarStatus']);
        // Produtos vinculados (Item 3)
        Route::post('/crm/opportunities/{opportunity}/products', [\App\Http\Controllers\CrmOpportunityController::class, 'addProduct']);
        Route::put('/crm/opportunities/{opportunity}/products/{product}', [\App\Http\Controllers\CrmOpportunityController::class, 'updateProduct']);
        Route::delete('/crm/opportunities/{opportunity}/products/{product}', [\App\Http\Controllers\CrmOpportunityController::class, 'removeProduct']);
        Route::put('/crm/opportunities/{opportunity}/qualificacao', [\App\Http\Controllers\CrmOpportunityController::class, 'qualificar']);
        Route::post('/crm/opportunities/{opportunity}/bloqueio', [\App\Http\Controllers\CrmOpportunityController::class, 'registrarBloqueio']);
        // Anexos da oportunidade
        Route::get('/crm/opportunities/{opportunity}/attachments', [\App\Http\Controllers\CrmOpportunityController::class, 'attachments']);
        Route::post('/crm/opportunities/{opportunity}/attachments', [\App\Http\Controllers\CrmOpportunityController::class, 'uploadAttachment']);
        Route::get('/crm/opportunities/{opportunity}/attachments/{attachment}/download', [\App\Http\Controllers\CrmOpportunityController::class, 'downloadAttachment']);
        Route::delete('/crm/opportunities/{opportunity}/attachments/{attachment}', [\App\Http\Controllers\CrmOpportunityController::class, 'deleteAttachment']);
        Route::get('/crm/tasks',  [\App\Http\Controllers\CrmTaskController::class, 'index']);
        Route::post('/crm/tasks', [\App\Http\Controllers\CrmTaskController::class, 'store']);
        Route::patch('/crm/tasks/{crmTask}/complete', [\App\Http\Controllers\CrmTaskController::class, 'complete']);
        Route::delete('/crm/tasks/{crmTask}', [\App\Http\Controllers\CrmTaskController::class, 'destroy']);

        // 🤝 CRM — Fase 1C (Propostas) + Editor (Fase 1.4)
        Route::get('/crm/proposals',  [\App\Http\Controllers\CrmProposalController::class, 'index']);
        Route::post('/crm/proposals', [\App\Http\Controllers\CrmProposalController::class, 'store']);
        // específicas ANTES do binding {crmProposal} p/ não serem capturadas
        Route::get('/crm/proposals/artwork', [\App\Http\Controllers\CrmProposalController::class, 'artwork']);
        Route::post('/crm/proposals/preview', [\App\Http\Controllers\CrmProposalController::class, 'preview']);
        Route::get('/crm/proposals/logo/{attachment}', [\App\Http\Controllers\CrmProposalController::class, 'logoServe']);
        Route::get('/crm/proposals/escopo-image/{attachment}', [\App\Http\Controllers\CrmProposalController::class, 'escopoImageServe']);
        Route::get('/crm/proposal-config/contratada', [\App\Http\Controllers\CrmProposalController::class, 'contratadaGet']);
        Route::put('/crm/proposal-config/contratada', [\App\Http\Controllers\CrmProposalController::class, 'contratadaUpdate']);
        Route::get('/crm/proposals/{crmProposal}', [\App\Http\Controllers\CrmProposalController::class, 'show']);
        Route::put('/crm/proposals/{crmProposal}',    [\App\Http\Controllers\CrmProposalController::class, 'update']);
        Route::put('/crm/proposals/{crmProposal}/editar', [\App\Http\Controllers\CrmProposalController::class, 'editar']);
        Route::post('/crm/proposals/{crmProposal}/gerar', [\App\Http\Controllers\CrmProposalController::class, 'gerar']);
        Route::post('/crm/proposals/{crmProposal}/logo', [\App\Http\Controllers\CrmProposalController::class, 'logo']);
        Route::post('/crm/proposals/{crmProposal}/escopo-image', [\App\Http\Controllers\CrmProposalController::class, 'escopoImage']);
        Route::delete('/crm/proposals/{crmProposal}', [\App\Http\Controllers\CrmProposalController::class, 'destroy']);

        // 🤝 CRM — Fase 1D (Conversão comercial → contrato)
        Route::post('/crm/opportunities/{opportunity}/convert', [\App\Http\Controllers\CrmConversionController::class, 'convert']);

        // 🤝 CRM — Fase 1E (Dashboards + Timeline da empresa)
        Route::get('/crm/dashboard/summary', [\App\Http\Controllers\CrmDashboardController::class, 'summary']);
        Route::get('/crm/dashboard/leads', [\App\Http\Controllers\CrmDashboardController::class, 'leads']);
        Route::get('/crm/dashboard/funil', [\App\Http\Controllers\CrmDashboardController::class, 'funil']);
        Route::get('/crm/dashboard/renovacoes', [\App\Http\Controllers\CrmDashboardController::class, 'renovacoes']);
        Route::get('/crm/dashboard/origem', [\App\Http\Controllers\CrmDashboardController::class, 'origem']);
        Route::get('/crm/dashboard/relacionamento', [\App\Http\Controllers\CrmDashboardController::class, 'relacionamento']);
        Route::get('/crm/dashboard/risco-renovacao', [\App\Http\Controllers\CrmDashboardController::class, 'riscoRenovacao']);
        Route::get('/crm/carteira', [\App\Http\Controllers\CrmCarteiraController::class, 'index']);
        Route::get('/crm/search', [\App\Http\Controllers\CrmSearchController::class, 'index']);
        // Plataforma de Documentos (Fase 0.7) — download do PDF congelado por versão.
        Route::get('/documents/{document}/download', [\App\Http\Controllers\DocumentController::class, 'download']);
        // Saneamento cadastral (Item 2) — somente leitura.
        Route::get('/contracts/data-quality/vencimentos', [\App\Http\Controllers\ContractDataQualityController::class, 'vencimentos']);
        Route::get('/customers/{customer}/crm/timeline', [\App\Http\Controllers\CustomerCrmController::class, 'timeline']);
        // Visão 360° da Empresa — Fase A (Ficha da Empresa, carregamento por seções)
        Route::get('/customers/{customer}/360', [\App\Http\Controllers\Customer360Controller::class, 'show']);
        // Saúde da Conta (Roadmap Fase 1)
        Route::get('/crm/saude/painel', [\App\Http\Controllers\CrmSaudeController::class, 'painel']);
        Route::get('/customers/{customer}/saude/historico', [\App\Http\Controllers\CrmSaudeController::class, 'historico']);

        // 🤝 CRM — Camada de Leads (captação + qualificação; empresa única)
        Route::get('/crm/leads',  [\App\Http\Controllers\CrmLeadController::class, 'index']);
        Route::post('/crm/leads', [\App\Http\Controllers\CrmLeadController::class, 'store']);
        Route::put('/crm/leads/{customer}', [\App\Http\Controllers\CrmLeadController::class, 'update']);
        Route::patch('/crm/leads/{customer}/stage', [\App\Http\Controllers\CrmLeadController::class, 'moveStage']);
        Route::post('/crm/leads/{customer}/convert-prospect', [\App\Http\Controllers\CrmLeadController::class, 'convertToProspect']);
        Route::get('/crm/lead-sources',  [\App\Http\Controllers\CrmLeadController::class, 'sourcesIndex']);
        Route::post('/crm/lead-sources', [\App\Http\Controllers\CrmLeadController::class, 'sourcesStore']);
        Route::put('/crm/lead-sources/{source}', [\App\Http\Controllers\CrmLeadController::class, 'sourcesUpdate']);
        // Motivos de perda (Item 2)
        Route::get('/crm/loss-reasons',  [\App\Http\Controllers\CrmLossReasonController::class, 'index']);
        Route::post('/crm/loss-reasons', [\App\Http\Controllers\CrmLossReasonController::class, 'store']);
        Route::put('/crm/loss-reasons/{lossReason}', [\App\Http\Controllers\CrmLossReasonController::class, 'update']);
        // CRM — Campanhas comerciais
        Route::get('/crm/campaigns',  [\App\Http\Controllers\CrmCampaignController::class, 'index']);
        Route::post('/crm/campaigns', [\App\Http\Controllers\CrmCampaignController::class, 'store']);
        Route::put('/crm/campaigns/{campaign}', [\App\Http\Controllers\CrmCampaignController::class, 'update']);
        Route::delete('/crm/campaigns/{campaign}', [\App\Http\Controllers\CrmCampaignController::class, 'destroy']);
        // CRM — Motivos de descarte (funil de prospecção) + repescagem
        Route::get('/crm/discard-reasons',  [\App\Http\Controllers\CrmDiscardReasonController::class, 'index']);
        Route::post('/crm/discard-reasons', [\App\Http\Controllers\CrmDiscardReasonController::class, 'store']);
        Route::put('/crm/discard-reasons/{discardReason}', [\App\Http\Controllers\CrmDiscardReasonController::class, 'update']);
        Route::delete('/crm/discard-reasons/{discardReason}', [\App\Http\Controllers\CrmDiscardReasonController::class, 'destroy']);
        Route::get('/crm/segments',  [\App\Http\Controllers\CrmSegmentController::class, 'index']);
        Route::post('/crm/segments', [\App\Http\Controllers\CrmSegmentController::class, 'store']);
        Route::put('/crm/segments/{segment}', [\App\Http\Controllers\CrmSegmentController::class, 'update']);
        Route::delete('/crm/segments/{segment}', [\App\Http\Controllers\CrmSegmentController::class, 'destroy']);

        // Tipos de contato (follow-up) — cadastro
        Route::get('/crm/contact-types',  [\App\Http\Controllers\CrmContactTypeController::class, 'index']);
        Route::post('/crm/contact-types', [\App\Http\Controllers\CrmContactTypeController::class, 'store']);
        Route::put('/crm/contact-types/{crmContactType}',    [\App\Http\Controllers\CrmContactTypeController::class, 'update']);
        Route::delete('/crm/contact-types/{crmContactType}', [\App\Http\Controllers\CrmContactTypeController::class, 'destroy']);

        // Dados do usuário
        Route::get('/user', [AuthController::class, 'user'])->name('user.profile');
        Route::put('/user/profile', [AuthController::class, 'updateProfile'])->name('user.update');
        Route::put('/user/theme-preference', [AuthController::class, 'updateThemePreference'])->name('user.theme-preference');

        // Cards atribuídos ao usuário corrente (visão consultor — Bloco F)
        Route::get('/me/cards', [MeController::class, 'cards'])->middleware('block.cliente')->name('me.cards');
        // Comentários onde o usuário foi mencionado (refactor 2026-05-15).
        // Cliente recebe mentions de req chat — controller já filtra por isCliente.
        // Customer do próprio usuário (substitui /customers/{id} pro header do cliente,
        // que não tem permissão customers.view).

        // Autenticação
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout.all');
        Route::get('/auth/verify-token', [AuthController::class, 'verifyToken'])->name('auth.verify');
        Route::get('/auth/permissions', [AuthController::class, 'getPermissions'])->name('auth.permissions');

        // === DASHBOARDS ===
        // Dashboard de Banco de Horas Fixo - Protegido por permissão dashboards.view
        Route::middleware('permission.or.admin:dashboards.view')->group(function () {
            Route::get('/dashboards/bank-hours-fixed', [BankHoursFixedController::class, 'bankHoursFixed'])
                ->name('dashboards.bank-hours-fixed');
            Route::get('/dashboards/bank-hours-fixed/projects', [BankHoursFixedController::class, 'bankHoursFixedProjects'])
                ->name('dashboards.bank-hours-fixed.projects');
            Route::get('/dashboards/bank-hours-fixed/projects/{projectId}/tickets', [BankHoursFixedController::class, 'bankHoursFixedProjectTickets'])
                ->name('dashboards.bank-hours-fixed.projects.tickets');
            // Listas inline e agrupamentos dentro do dashboard
            Route::get('/dashboards/bank-hours-fixed/category-timesheets', [BankHoursFixedController::class, 'categoryTimesheetsModal'])
                ->name('dashboards.bank-hours-fixed.category-timesheets');
            Route::get('/dashboards/bank-hours-fixed/category-ticket-summary', [BankHoursFixedController::class, 'categoryTicketSummary'])
                ->name('dashboards.bank-hours-fixed.category-ticket-summary');
            Route::get('/dashboards/bank-hours-fixed/project-timesheets', [BankHoursFixedController::class, 'projectTimesheetsModal'])
                ->name('dashboards.bank-hours-fixed.project-timesheets');
            Route::get('/dashboards/bank-hours-fixed/project-timesheets/pdf', [BankHoursFixedController::class, 'projectTimesheetsPdf'])
                ->name('dashboards.bank-hours-fixed.project-timesheets.pdf');
            Route::get('/dashboards/bank-hours-fixed/project-ticket-summary', [BankHoursFixedController::class, 'projectTicketSummary'])
                ->name('dashboards.bank-hours-fixed.project-ticket-summary');
            Route::get('/dashboards/bank-hours-fixed/expenses', [BankHoursFixedController::class, 'expensesModal'])
                ->name('dashboards.bank-hours-fixed.expenses');
            Route::get('/dashboards/bank-hours-fixed/maintenance/tickets', [BankHoursFixedController::class, 'bankHoursFixedMaintenanceTickets'])
                ->name('dashboards.bank-hours-fixed.maintenance.tickets');
            Route::get('/dashboards/bank-hours-fixed/maintenance/tickets/{ticketId}/timesheets', [BankHoursFixedController::class, 'bankHoursFixedMaintenanceTicketTimesheets'])
                ->name('dashboards.bank-hours-fixed.maintenance.tickets.timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/hours-by-requester', [BankHoursFixedController::class, 'bankHoursFixedHoursByRequester'])
                ->name('dashboards.bank-hours-fixed.indicators.hours-by-requester');
            Route::get('/dashboards/bank-hours-fixed/indicators/requester-timesheets', [BankHoursFixedController::class, 'bankHoursFixedRequesterTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.requester-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/hours-by-service', [BankHoursFixedController::class, 'bankHoursFixedHoursByService'])
                ->name('dashboards.bank-hours-fixed.indicators.hours-by-service');
            Route::get('/dashboards/bank-hours-fixed/indicators/service-timesheets', [BankHoursFixedController::class, 'bankHoursFixedServiceTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.service-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/tickets-by-urgency', [BankHoursFixedController::class, 'bankHoursFixedTicketsByUrgency'])
                ->name('dashboards.bank-hours-fixed.indicators.tickets-by-urgency');
            Route::get('/dashboards/bank-hours-fixed/indicators/urgency-timesheets', [BankHoursFixedController::class, 'bankHoursFixedUrgencyTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.urgency-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/tickets-by-status', [BankHoursFixedController::class, 'bankHoursFixedTicketsByStatus'])
                ->name('dashboards.bank-hours-fixed.indicators.tickets-by-status');
            Route::get('/dashboards/bank-hours-fixed/indicators/status-timesheets', [BankHoursFixedController::class, 'bankHoursFixedStatusTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.status-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/tickets-by-level', [BankHoursFixedController::class, 'bankHoursFixedTicketsByLevel'])
                ->name('dashboards.bank-hours-fixed.indicators.tickets-by-level');
            Route::get('/dashboards/bank-hours-fixed/indicators/level-timesheets', [BankHoursFixedController::class, 'bankHoursFixedLevelTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.level-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/tickets-by-category', [BankHoursFixedController::class, 'bankHoursFixedTicketsByCategory'])
                ->name('dashboards.bank-hours-fixed.indicators.tickets-by-category');
            Route::get('/dashboards/bank-hours-fixed/indicators/category-timesheets', [BankHoursFixedController::class, 'bankHoursFixedCategoryTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.category-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/tickets-above-8-hours', [BankHoursFixedController::class, 'bankHoursFixedTicketsAbove8Hours'])
                ->name('dashboards.bank-hours-fixed.indicators.tickets-above-8-hours');
            Route::get('/dashboards/bank-hours-fixed/indicators/ticket-timesheets', [BankHoursFixedController::class, 'bankHoursFixedTicketTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.ticket-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/monthly-tickets', [BankHoursFixedController::class, 'bankHoursFixedMonthlyTickets'])
                ->name('dashboards.bank-hours-fixed.indicators.monthly-tickets');
            Route::get('/dashboards/bank-hours-fixed/indicators/monthly-timesheets', [BankHoursFixedController::class, 'bankHoursFixedMonthlyTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.monthly-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/monthly-consumption', [BankHoursFixedController::class, 'bankHoursFixedMonthlyConsumption'])
                ->name('dashboards.bank-hours-fixed.indicators.monthly-consumption');
            Route::get('/dashboards/bank-hours-fixed/indicators/monthly-consumption-timesheets', [BankHoursFixedController::class, 'bankHoursFixedMonthlyConsumptionTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.monthly-consumption-timesheets');
        });

        // Dashboard de Banco de Horas Mensais - Protegido por permissão dashboards.view
        Route::middleware('permission.or.admin:dashboards.view')->group(function () {
            Route::get('/dashboards/bank-hours-monthly', [BankHoursMonthlyController::class, 'bankHoursMonthly'])
                ->name('dashboards.bank-hours-monthly');
            Route::get('/dashboards/bank-hours-monthly/projects', [BankHoursMonthlyController::class, 'bankHoursMonthlyProjects'])
                ->name('dashboards.bank-hours-monthly.projects');
            Route::get('/dashboards/bank-hours-monthly/projects/{projectId}/tickets', [BankHoursMonthlyController::class, 'bankHoursMonthlyProjectTickets'])
                ->name('dashboards.bank-hours-monthly.projects.tickets');
            Route::get('/dashboards/bank-hours-monthly/maintenance/tickets', [BankHoursMonthlyController::class, 'bankHoursMonthlyMaintenanceTickets'])
                ->name('dashboards.bank-hours-monthly.maintenance.tickets');
            Route::get('/dashboards/bank-hours-monthly/maintenance/tickets/{ticketId}/timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyMaintenanceTicketTimesheets'])
                ->name('dashboards.bank-hours-monthly.maintenance.tickets.timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/hours-by-requester', [BankHoursMonthlyController::class, 'bankHoursMonthlyHoursByRequester'])
                ->name('dashboards.bank-hours-monthly.indicators.hours-by-requester');
            Route::get('/dashboards/bank-hours-monthly/indicators/requester-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyRequesterTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.requester-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/hours-by-service', [BankHoursMonthlyController::class, 'bankHoursMonthlyHoursByService'])
                ->name('dashboards.bank-hours-monthly.indicators.hours-by-service');
            Route::get('/dashboards/bank-hours-monthly/indicators/service-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyServiceTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.service-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/tickets-by-status', [BankHoursMonthlyController::class, 'bankHoursMonthlyTicketsByStatus'])
                ->name('dashboards.bank-hours-monthly.indicators.tickets-by-status');
            Route::get('/dashboards/bank-hours-monthly/indicators/status-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyStatusTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.status-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/tickets-by-level', [BankHoursMonthlyController::class, 'bankHoursMonthlyTicketsByLevel'])
                ->name('dashboards.bank-hours-monthly.indicators.tickets-by-level');
            Route::get('/dashboards/bank-hours-monthly/indicators/level-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyLevelTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.level-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/tickets-by-category', [BankHoursMonthlyController::class, 'bankHoursMonthlyTicketsByCategory'])
                ->name('dashboards.bank-hours-monthly.indicators.tickets-by-category');
            Route::get('/dashboards/bank-hours-monthly/indicators/category-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyCategoryTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.category-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/tickets-above-8-hours', [BankHoursMonthlyController::class, 'bankHoursMonthlyTicketsAbove8Hours'])
                ->name('dashboards.bank-hours-monthly.indicators.tickets-above-8-hours');
            Route::get('/dashboards/bank-hours-monthly/indicators/ticket-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyTicketTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.ticket-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/monthly-tickets', [BankHoursMonthlyController::class, 'bankHoursMonthlyMonthlyTickets'])
                ->name('dashboards.bank-hours-monthly.indicators.monthly-tickets');
            Route::get('/dashboards/bank-hours-monthly/indicators/monthly-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyMonthlyTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.monthly-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/monthly-consumption', [BankHoursMonthlyController::class, 'bankHoursMonthlyMonthlyConsumption'])
                ->name('dashboards.bank-hours-monthly.indicators.monthly-consumption');
            Route::get('/dashboards/bank-hours-monthly/indicators/monthly-consumption-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyMonthlyConsumptionTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.monthly-consumption-timesheets');
        });

        // Dashboard de Sustentação On Demand - Protegido por permissão dashboards.view
        Route::middleware('permission.or.admin:dashboards.view')->group(function () {
            Route::get('/dashboards/on-demand', [OnDemandController::class, 'onDemand'])
                ->name('dashboards.on-demand');
            Route::get('/dashboards/on-demand/projects', [OnDemandController::class, 'onDemandProjects'])
                ->name('dashboards.on-demand.projects');
            Route::get('/dashboards/on-demand/projects/{projectId}/tickets', [OnDemandController::class, 'onDemandProjectTickets'])
                ->name('dashboards.on-demand.projects.tickets');
            Route::get('/dashboards/on-demand/maintenance/tickets', [OnDemandController::class, 'onDemandMaintenanceTickets'])
                ->name('dashboards.on-demand.maintenance.tickets');
            Route::get('/dashboards/on-demand/maintenance/tickets/{ticketId}/timesheets', [OnDemandController::class, 'onDemandMaintenanceTicketTimesheets'])
                ->name('dashboards.on-demand.maintenance.tickets.timesheets');
            Route::get('/dashboards/on-demand/indicators/hours-by-requester', [OnDemandController::class, 'onDemandHoursByRequester'])
                ->name('dashboards.on-demand.indicators.hours-by-requester');
            Route::get('/dashboards/on-demand/indicators/requester-timesheets', [OnDemandController::class, 'onDemandRequesterTimesheets'])
                ->name('dashboards.on-demand.indicators.requester-timesheets');
            Route::get('/dashboards/on-demand/indicators/hours-by-service', [OnDemandController::class, 'onDemandHoursByService'])
                ->name('dashboards.on-demand.indicators.hours-by-service');
            Route::get('/dashboards/on-demand/indicators/service-timesheets', [OnDemandController::class, 'onDemandServiceTimesheets'])
                ->name('dashboards.on-demand.indicators.service-timesheets');
            Route::get('/dashboards/on-demand/indicators/tickets-by-status', [OnDemandController::class, 'onDemandTicketsByStatus'])
                ->name('dashboards.on-demand.indicators.tickets-by-status');
            Route::get('/dashboards/on-demand/indicators/status-timesheets', [OnDemandController::class, 'onDemandStatusTimesheets'])
                ->name('dashboards.on-demand.indicators.status-timesheets');
            Route::get('/dashboards/on-demand/indicators/tickets-by-level', [OnDemandController::class, 'onDemandTicketsByLevel'])
                ->name('dashboards.on-demand.indicators.tickets-by-level');
            Route::get('/dashboards/on-demand/indicators/level-timesheets', [OnDemandController::class, 'onDemandLevelTimesheets'])
                ->name('dashboards.on-demand.indicators.level-timesheets');
            Route::get('/dashboards/on-demand/indicators/tickets-by-category', [OnDemandController::class, 'onDemandTicketsByCategory'])
                ->name('dashboards.on-demand.indicators.tickets-by-category');
            Route::get('/dashboards/on-demand/indicators/category-timesheets', [OnDemandController::class, 'onDemandCategoryTimesheets'])
                ->name('dashboards.on-demand.indicators.category-timesheets');
            Route::get('/dashboards/on-demand/indicators/tickets-above-8-hours', [OnDemandController::class, 'onDemandTicketsAbove8Hours'])
                ->name('dashboards.on-demand.indicators.tickets-above-8-hours');
            Route::get('/dashboards/on-demand/indicators/ticket-timesheets', [OnDemandController::class, 'onDemandTicketTimesheets'])
                ->name('dashboards.on-demand.indicators.ticket-timesheets');
            Route::get('/dashboards/on-demand/indicators/monthly-tickets', [OnDemandController::class, 'onDemandMonthlyTickets'])
                ->name('dashboards.on-demand.indicators.monthly-tickets');
            Route::get('/dashboards/on-demand/indicators/monthly-timesheets', [OnDemandController::class, 'onDemandMonthlyTimesheets'])
                ->name('dashboards.on-demand.indicators.monthly-timesheets');
            Route::get('/dashboards/on-demand/indicators/monthly-consumption', [OnDemandController::class, 'onDemandMonthlyConsumption'])
                ->name('dashboards.on-demand.indicators.monthly-consumption');
            Route::get('/dashboards/on-demand/indicators/monthly-consumption-timesheets', [OnDemandController::class, 'onDemandMonthlyConsumptionTimesheets'])
                ->name('dashboards.on-demand.indicators.monthly-consumption-timesheets');
        });

        // Dashboard de Projetos Fechados - Protegido por permissão dashboards.view
        Route::middleware('permission.or.admin:dashboards.view')->group(function () {
            Route::get('/dashboards/fechado', [FechadoController::class, 'fechado'])
                ->name('dashboards.fechado');
            Route::get('/dashboards/fechado/projects', [FechadoController::class, 'fechadoProjects'])
                ->name('dashboards.fechado.projects');
            Route::get('/dashboards/fechado/expenses', [FechadoController::class, 'fechadoExpenses'])
                ->name('dashboards.fechado.expenses');
        });

        // Alteração de senha
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])
            ->name('auth.change-password');
        Route::post('/auth/change-temporary-password', [AuthController::class, 'changeTemporaryPassword'])
            ->name('auth.change-temporary-password');

        // 🏆 EXECUTIVES - Gestão de executivos
        Route::get('/executives', [ExecutiveController::class, 'index'])->name('executives.index');
        Route::get('/executives/all', [ExecutiveController::class, 'all'])->name('executives.all');
        Route::get('/executives/comerciais', [ExecutiveController::class, 'commercial'])->name('executives.commercial');
        Route::middleware('permission.or.admin:users.update')->group(function () {
            Route::patch('/executives/{user}', [ExecutiveController::class, 'toggle'])->name('executives.toggle');
        });

        // 🏢 CLIENT PORTAL
        Route::get('/client/portal', [ClientPortalController::class, 'portal'])->name('client.portal');
        Route::get('/client/portal/customers', [ClientPortalController::class, 'customers'])->name('client.portal.customers');
        Route::get('/client/portal/summary', [ClientPortalController::class, 'summary'])->name('client.portal.summary');
        Route::get('/client/portal/projects/{projectId}/operational-summary', [ClientPortalController::class, 'operationalSummary'])
            ->name('client.portal.project-operational-summary');

        // 🤝 Cliente envolvido em atividade pontual (acesso contextual, ADR 0009 appendix)
        Route::get('/client/activities', [ClientActivityController::class, 'index'])->name('client.activities.index');
        Route::get('/client/activities/{delivery}', [ClientActivityController::class, 'show'])->name('client.activities.show');
        Route::get('/client/activities/{delivery}/timeline', [ClientActivityController::class, 'timeline'])->name('client.activities.timeline');
        Route::post('/client/activities/{delivery}/comments', [ClientActivityController::class, 'storeComment'])->name('client.activities.comments.store');
        Route::post('/client/activities/{delivery}/approve', [ClientActivityController::class, 'approve'])->name('client.activities.approve');
        Route::post('/client/activities/{delivery}/reject', [ClientActivityController::class, 'reject'])->name('client.activities.reject');
        // Visão do cliente sobre o projeto (em dias, sem horas/valores)
        Route::get('/client/projects/{project}/schedule', [ClientProjectController::class, 'schedule'])->name('client.projects.schedule');
        Route::get('/client/projects/{project}/follow-ups', [ClientProjectController::class, 'followUps'])->name('client.projects.followups');
        // Follow Up como unidade de comunicação do cliente (envolvido)
        Route::get('/client/follow-ups', [ClientFollowUpController::class, 'index'])->name('client.followups.index');
        Route::get('/client/follow-ups/{followUp}', [ClientFollowUpController::class, 'show'])->name('client.followups.show');
        Route::get('/client/follow-ups/{followUp}/timeline', [ClientFollowUpController::class, 'timeline'])->name('client.followups.timeline');
        Route::post('/client/follow-ups/{followUp}/comments', [ClientFollowUpController::class, 'storeComment'])->name('client.followups.comments');
        Route::post('/client/follow-ups/{followUp}/complete', [ClientFollowUpController::class, 'complete'])->name('client.followups.complete');
        Route::get('/client/follow-ups/{followUp}/activity-summary', [ClientFollowUpController::class, 'activitySummary'])->name('client.followups.activity-summary');

        // 👥 CUSTOMERS - Protegido por permissões específicas (Admins sempre têm acesso)
        Route::middleware('permission.or.admin:customers.view')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('/customers/user-linked', [CustomerController::class, 'getUserLinkedCustomers'])->name('customers.user-linked');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        });

        Route::middleware('permission.or.admin:customers.create')->group(function () {
            Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        });

        Route::middleware('permission.or.admin:customers.update')->group(function () {
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        });

        Route::middleware('permission.or.admin:customers.delete')->group(function () {
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
        });

        // 🔧 SERVICE TYPES - Tipos de Serviço
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/service-types', [ServiceTypeController::class, 'index'])->name('service-types.index');
        Route::get('/service-types/{id}', [ServiceTypeController::class, 'show'])->name('service-types.show');

        // Modelos de e-mail dos fechamentos (cadastro)
        Route::get('/fechamento-email-templates', [\App\Http\Controllers\FechamentoEmailTemplateController::class, 'index']);
        Route::post('/fechamento-email-templates', [\App\Http\Controllers\FechamentoEmailTemplateController::class, 'store']);
        Route::put('/fechamento-email-templates/{template}', [\App\Http\Controllers\FechamentoEmailTemplateController::class, 'update']);
        Route::delete('/fechamento-email-templates/{template}', [\App\Http\Controllers\FechamentoEmailTemplateController::class, 'destroy']);

        // ⚙️ CENTRAL DE WORKFLOWS — quem recebe cada e-mail (admin-only, guard no controller)
        Route::get('/workflows', [\App\Http\Controllers\WorkflowController::class, 'index'])->name('workflows.index');
        Route::put('/workflows/{key}', [\App\Http\Controllers\WorkflowController::class, 'update'])->name('workflows.update');
        Route::post('/workflows/{key}/test', [\App\Http\Controllers\WorkflowController::class, 'test'])->name('workflows.test');
        Route::post('/workflows/{key}/preview', [\App\Http\Controllers\WorkflowController::class, 'preview'])->name('workflows.preview');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:service_types.create')->group(function () {
            Route::post('/service-types', [ServiceTypeController::class, 'store'])->name('service-types.store');
        });

        Route::middleware('permission.or.admin:service_types.update')->group(function () {
            Route::put('/service-types/{id}', [ServiceTypeController::class, 'update'])->name('service-types.update');
        });

        Route::middleware('permission.or.admin:service_types.delete')->group(function () {
            Route::delete('/service-types/{id}', [ServiceTypeController::class, 'destroy'])->name('service-types.destroy');
        });

        // 📋 CONTRACT TYPES - Tipos de Contrato
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/contract-types', [ContractTypeController::class, 'index'])->name('contract-types.index');
        Route::get('/contract-types/{id}', [ContractTypeController::class, 'show'])->name('contract-types.show');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:contract_types.create')->group(function () {
            Route::post('/contract-types', [ContractTypeController::class, 'store'])->name('contract-types.store');
        });

        Route::middleware('permission.or.admin:contract_types.update')->group(function () {
            Route::put('/contract-types/{id}', [ContractTypeController::class, 'update'])->name('contract-types.update');
        });

        Route::middleware('permission.or.admin:contract_types.delete')->group(function () {
            Route::delete('/contract-types/{id}', [ContractTypeController::class, 'destroy'])->name('contract-types.destroy');
        });

        // 📋 PROJECT STATUSES - Status de Projetos
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/project-statuses', [ProjectStatusController::class, 'index'])->name('project-statuses.index');
        Route::get('/project-statuses/{id}', [ProjectStatusController::class, 'show'])->name('project-statuses.show');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:project_statuses.create')->group(function () {
            Route::post('/project-statuses', [ProjectStatusController::class, 'store'])->name('project-statuses.store');
        });

        Route::middleware('permission.or.admin:project_statuses.update')->group(function () {
            Route::put('/project-statuses/{id}', [ProjectStatusController::class, 'update'])->name('project-statuses.update');
        });

        Route::middleware('permission.or.admin:project_statuses.delete')->group(function () {
            Route::delete('/project-statuses/{id}', [ProjectStatusController::class, 'destroy'])->name('project-statuses.destroy');
        });

        // 🏗️ PROJECTS - Protegido por permissões específicas (Admins sempre têm acesso)

        // Enum values - endpoint público dentro da autenticação
        Route::get('/projects/enum-values', [ProjectController::class, 'enumValues'])->name('projects.enum-values');
        Route::get('/projects/next-code', [ProjectController::class, 'nextCode'])->name('projects.next-code');

        // Projetos do próprio usuário (sem permissão especial — filtra automaticamente pelo consultor logado)
        Route::get('/my-projects', [ProjectController::class, 'myProjects'])->name('projects.my');

        Route::middleware('permission.or.admin:projects.view')->group(function () {
            Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('/projects/ic-summary', [ProjectController::class, 'icSummary'])->name('projects.ic-summary');
            Route::get('/projects/ic-analytics', [ProjectController::class, 'icAnalytics'])->name('projects.ic-analytics');
            Route::get('/projects/hours-per-consultant', [ProjectController::class, 'hoursPerConsultant'])->name('projects.hours-per-consultant');
            Route::get('/projects/movidesk-integration-conflict', [ProjectController::class, 'movideskIntegrationConflict'])->name('projects.movidesk-conflict');
            Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::get('/projects/{project}/change-history', [ProjectController::class, 'changeHistory'])->name('projects.change-history');
            Route::get('/projects/{project}/sold-hours-history', [ProjectController::class, 'soldHoursHistoryIndex'])->name('projects.sold-hours-history.index');
            Route::get('/projects/{project}/contract-request', [ProjectController::class, 'contractRequest'])->name('projects.contract-request');
            Route::get('/projects/{project}/monthly-statement', [ProjectController::class, 'monthlyStatement'])->name('projects.monthly-statement');
        });

        Route::middleware('permission.or.admin:projects.view_costs')->group(function () {
            Route::get('/projects/{project}/cost-summary', [ProjectController::class, 'costSummary'])->name('projects.cost-summary');
        });

        Route::middleware('permission.or.admin:projects.view')->group(function () {
            Route::get('/projects/{project}/available-hours', [ProjectController::class, 'availableHours'])->name('projects.available-hours');
            // Projetos reais escolhidos por consultor no investimento.
            Route::get('/projects/{project}/real-project-assignments', [ProjectController::class, 'realProjectAssignments'])->name('projects.real-project-assignments');
            Route::get('/projects/{project}/real-project-options', [ProjectController::class, 'realProjectOptions'])->name('projects.real-project-options');
        });

        // Alocação de investimento (consultores + projetos reais): escopo assign_consultants
        // para que coordenadores também aloquem, sem projects.update global.
        Route::middleware('permission.or.admin:projects.assign_consultants')->group(function () {
            Route::patch('/projects/{project}/investment-allocation', [ProjectController::class, 'updateInvestmentAllocation'])->name('projects.investment-allocation');
        });

        Route::middleware('permission.or.admin:projects.create')->group(function () {
            Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::post('/investimento-interno/projects', [ProjectController::class, 'storeInternalProject'])->name('projects.store-internal');
        });

        Route::middleware('permission.or.admin:projects.update')->group(function () {
            Route::patch('/projects/{project}/status', [ProjectController::class, 'updateStatus'])->name('projects.update-status');
            Route::get('/projects/{project}/saving', [ProjectController::class, 'saving'])->name('projects.saving');
            Route::post('/projects/{project}/send-saving', [ProjectController::class, 'sendSaving'])->name('projects.send-saving');
            Route::patch('/projects/{project}/delivery', [ProjectController::class, 'updateDelivery'])->name('projects.update-delivery');
            Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
            Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.patch');
            Route::put('/projects/{project}/sold-hours-history/{history}', [ProjectController::class, 'updateSoldHoursHistory'])->name('projects.sold-hours-history.update');
            Route::delete('/projects/{project}/sold-hours-history/{history}', [ProjectController::class, 'destroySoldHoursHistory'])->name('projects.sold-hours-history.destroy');
            Route::put('/projects/{project}/monthly-consumption', [ProjectController::class, 'updateMonthlyConsumption'])->name('projects.monthly-consumption');
            Route::put('/projects/{project}/change-history/{log}', [ProjectController::class, 'updateChangeHistory'])->name('projects.change-history.update');
            Route::delete('/projects/{project}/change-history/{log}', [ProjectController::class, 'destroyChangeHistory'])->name('projects.change-history.destroy');
            Route::post('/projects/{project}/detach-from-parent', [ProjectController::class, 'detachFromParent'])->name('projects.detach-from-parent');
            Route::post('/projects/{project}/attach-to-parent',  [ProjectController::class, 'attachToParent'])->name('projects.attach-to-parent');
        });

        Route::middleware('permission.or.admin:projects.delete')->group(function () {
            Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        });

        // 🧱 PROJECT STAGES + STAGE DELIVERIES + DELIVERY EVENTS
        // Etapas (frentes paralelas) e entregas (cards do kanban operacional).
        // Autorização granular via Policy do Project (read/update do Project).
        Route::middleware(['permission.or.admin:projects.view', 'block.cliente'])->group(function () {
            Route::get('/projects/{project}/stages', [ProjectStageController::class, 'index'])->name('stages.index');
            Route::get('/projects/{project}/deliveries', [ProjectStageController::class, 'projectDeliveries'])->name('projects.deliveries.flat');
            Route::get('/projects/{project}/delay-risk', [ProjectStageController::class, 'delayRisk'])->name('projects.delay-risk');
            Route::get('/projects/{project}/consolidated-team', [ProjectController::class, 'consolidatedTeam'])->name('projects.consolidated-team');
            Route::get('/projects/{project}/schedule', [ProjectController::class, 'schedule'])->name('projects.schedule');
            Route::get('/projects-portfolio', [\App\Http\Controllers\ProjectEvmController::class, 'portfolio'])->name('projects.portfolio');
            Route::get('/projects-portfolio/curve', [\App\Http\Controllers\ProjectEvmController::class, 'portfolioCurve'])->name('projects.portfolio.curve');
            Route::get('/projects/{project}/evm', [\App\Http\Controllers\ProjectEvmController::class, 'evm'])->name('projects.evm');
            Route::get('/projects/{project}/operational-metrics', [\App\Http\Controllers\ProjectEvmController::class, 'operational'])->name('projects.operational-metrics');
            Route::post('/projects/{project}/cronograma/recalc-preview', [ProjectController::class, 'recalcPreview'])->name('projects.cronograma.recalc-preview');

            // Modelos de cronograma (salvar/aplicar) + copiar de outro projeto
            Route::get('/cronograma-templates', [\App\Http\Controllers\CronogramaTemplateController::class, 'index'])->name('cronograma-templates.index');
            Route::post('/cronograma-templates', [\App\Http\Controllers\CronogramaTemplateController::class, 'storeFromProject'])->name('cronograma-templates.store');
            Route::delete('/cronograma-templates/{template}', [\App\Http\Controllers\CronogramaTemplateController::class, 'destroy'])->name('cronograma-templates.destroy');
            Route::post('/projects/{project}/cronograma/apply-template', [\App\Http\Controllers\CronogramaTemplateController::class, 'apply'])->name('projects.cronograma.apply-template');
            Route::post('/projects/{project}/cronograma/copy-from', [\App\Http\Controllers\CronogramaTemplateController::class, 'copyFromProject'])->name('projects.cronograma.copy-from');

            // ✅ FOLLOW UP (gestão de pendências e compromissos)
            Route::get('/follow-ups/summary', [\App\Http\Controllers\FollowUpController::class, 'summary'])->name('followups.summary');
            Route::get('/follow-ups', [\App\Http\Controllers\FollowUpController::class, 'index'])->name('followups.index');
            Route::post('/follow-ups', [\App\Http\Controllers\FollowUpController::class, 'store'])->name('followups.store');
            Route::get('/follow-ups/{followUp}', [\App\Http\Controllers\FollowUpController::class, 'show'])->name('followups.show');
            Route::put('/follow-ups/{followUp}', [\App\Http\Controllers\FollowUpController::class, 'update'])->name('followups.update');
            Route::patch('/follow-ups/{followUp}', [\App\Http\Controllers\FollowUpController::class, 'update'])->name('followups.patch');
            Route::delete('/follow-ups/{followUp}', [\App\Http\Controllers\FollowUpController::class, 'destroy'])->name('followups.destroy');
            Route::patch('/follow-ups/{followUp}/kanban-move', [\App\Http\Controllers\FollowUpController::class, 'kanbanMove'])->name('followups.kanban-move');
            Route::get('/follow-ups/{followUp}/activity', [\App\Http\Controllers\FollowUpController::class, 'timeline'])->name('followups.activity');
            Route::post('/follow-ups/{followUp}/comments', [\App\Http\Controllers\FollowUpController::class, 'storeComment'])->name('followups.comments.store');

            // Cadastro de categorias de Follow Up (gerenciável pelo coordenador via followups.manage)
            Route::get('/follow-up-categories', [\App\Http\Controllers\FollowUpCategoryController::class, 'index'])->name('follow-up-categories.index');
            Route::middleware('permission.or.admin:followups.manage')->group(function () {
                Route::post('/follow-up-categories', [\App\Http\Controllers\FollowUpCategoryController::class, 'store'])->name('follow-up-categories.store');
                Route::put('/follow-up-categories/{followUpCategory}', [\App\Http\Controllers\FollowUpCategoryController::class, 'update'])->name('follow-up-categories.update');
                Route::delete('/follow-up-categories/{followUpCategory}', [\App\Http\Controllers\FollowUpCategoryController::class, 'destroy'])->name('follow-up-categories.destroy');
            });
            Route::get('/stages/{stage}', [ProjectStageController::class, 'show'])->name('stages.show');
            Route::get('/stages/{stage}/activity', [ProjectStageController::class, 'activity'])->name('stages.activity');
            Route::get('/stages/{stage}/deliveries', [StageDeliveryController::class, 'index'])->name('deliveries.index');
            Route::get('/deliveries/{delivery}', [StageDeliveryController::class, 'show'])->name('deliveries.show');
            Route::get('/deliveries/{delivery}/events', [DeliveryEventController::class, 'index'])->name('deliveries.events');
            Route::get('/stages/{stage}/allocations', [StageAllocationController::class, 'index'])->name('stages.allocations.index');
            Route::get('/stages/{stage}/aportes', [StageHourAporteController::class, 'index'])->name('stages.aportes.index');
            // Comentário operacional (Pilar 3) — write permitido para qualquer um que possa ver (consultor alocado, coord, admin)
            Route::post('/stages/{stage}/comments', [ProjectStageController::class, 'storeComment'])->name('stages.comments.store');

            // Atividade como unidade de execução (Pilar A do refactor 2026-05-15)
            Route::get('/activities/{delivery}/activity', [StageDeliveryController::class, 'activity'])->name('activities.activity');
            Route::post('/activities/{delivery}/comments', [StageDeliveryController::class, 'storeComment'])->name('activities.comments.store');
            Route::get('/activities/{delivery}/aportes', [StageHourAporteController::class, 'indexForActivity'])->name('activities.aportes.index');
            Route::get('/activities/{delivery}/allocations', [StageAllocationController::class, 'indexForActivity'])->name('activities.allocations.index');

            // Arrastar/mover atividade no kanban: permitido a QUALQUER perfil interno
            // (consultor/coord/admin) que possa ver o projeto. Cliente bloqueado pelo grupo.
            Route::post('/deliveries/{delivery}/move', [StageDeliveryController::class, 'move'])->name('deliveries.move');
            // Solicitar aprovação do cliente (mover p/ "Aguardando cliente" + mensagem):
            // também liberado a qualquer interno (o coordenador/admin é quem aprova/reprova).
            Route::post('/deliveries/{delivery}/request-approval', [StageDeliveryController::class, 'requestApproval'])->name('deliveries.request-approval');
        });
        Route::middleware(['permission.or.admin:projects.update', 'block.cliente'])->group(function () {
            Route::post('/projects/{project}/baseline', [\App\Http\Controllers\ProjectEvmController::class, 'freeze'])->name('projects.baseline.freeze');
            Route::post('/projects-portfolio/freeze-missing', [\App\Http\Controllers\ProjectEvmController::class, 'freezeMissing'])->name('projects.portfolio.freeze-missing');
            Route::delete('/projects/{project}/baseline', [\App\Http\Controllers\ProjectEvmController::class, 'unfreeze'])->name('projects.baseline.unfreeze');
            Route::post('/projects/{project}/stages', [ProjectStageController::class, 'store'])->name('stages.store');
            Route::patch('/stages/{stage}', [ProjectStageController::class, 'update'])->name('stages.update');
            Route::delete('/stages/{stage}', [ProjectStageController::class, 'destroy'])->name('stages.destroy');
            Route::post('/projects/{project}/stages/reorder', [ProjectStageController::class, 'reorder'])->name('stages.reorder');

            Route::post('/stages/{stage}/deliveries', [StageDeliveryController::class, 'store'])->name('deliveries.store');
            Route::patch('/deliveries/{delivery}', [StageDeliveryController::class, 'update'])->name('deliveries.update');
            Route::delete('/deliveries/{delivery}', [StageDeliveryController::class, 'destroy'])->name('deliveries.destroy');
            Route::post('/deliveries/{delivery}/recalc-dependents', [StageDeliveryController::class, 'recalcDependents'])->name('deliveries.recalc-dependents');
            // Workflow de aprovação do cliente (interno — coordenador/admin)
            // Clientes com visão global do projeto (nível projeto)
            // Equipe do projeto (Visão Geral) — alimenta os seletores das atividades
            Route::get('/projects/{project}/team', [\App\Http\Controllers\ProjectTeamController::class, 'team'])->name('projects.team');
            Route::get('/projects/{project}/consultants', [\App\Http\Controllers\ProjectTeamController::class, 'consultants'])->name('projects.consultants.index');
            Route::get('/projects/{project}/consultants/available', [\App\Http\Controllers\ProjectTeamController::class, 'availableConsultants'])->name('projects.consultants.available');
            Route::post('/projects/{project}/consultants', [\App\Http\Controllers\ProjectTeamController::class, 'addConsultant'])->name('projects.consultants.add');
            Route::delete('/projects/{project}/consultants/{user}', [\App\Http\Controllers\ProjectTeamController::class, 'removeConsultant'])->name('projects.consultants.remove');
            Route::get('/projects/{project}/client-viewers', [ProjectClientViewerController::class, 'index'])->name('projects.client-viewers.index');
            Route::get('/projects/{project}/client-viewers/available', [ProjectClientViewerController::class, 'available'])->name('projects.client-viewers.available');
            Route::post('/projects/{project}/client-viewers', [ProjectClientViewerController::class, 'store'])->name('projects.client-viewers.store');
            Route::delete('/projects/{project}/client-viewers/{user}', [ProjectClientViewerController::class, 'destroy'])->name('projects.client-viewers.destroy');
            Route::post('/deliveries/{delivery}/approve', [StageDeliveryController::class, 'approve'])->name('deliveries.approve');
            Route::post('/deliveries/{delivery}/reject', [StageDeliveryController::class, 'reject'])->name('deliveries.reject');
            // Removido (Fase 4 — ADR 0007): POST /stages/{stage}/allocations.
            // Alocação é exclusivamente activity-level via POST /activities/{delivery}/allocations.
            Route::post('/stages/{stage}/aportes', [StageHourAporteController::class, 'store'])->name('stages.aportes.store');
            // Aporte no nível da atividade (Pilar C do refactor 2026-05-15)
            Route::post('/activities/{delivery}/aportes', [StageHourAporteController::class, 'storeForActivity'])->name('activities.aportes.store');
            Route::post('/activities/{delivery}/allocations', [StageAllocationController::class, 'storeForActivity'])->name('activities.allocations.store');
            Route::patch('/allocations/{allocation}', [StageAllocationController::class, 'update'])->name('allocations.update');
            Route::delete('/allocations/{allocation}', [StageAllocationController::class, 'destroy'])->name('allocations.destroy');
        });

        // 💰 HOUR CONTRIBUTIONS - Aportes de Horas (vinculados a projetos)
        Route::middleware('permission.or.admin:projects.view')->group(function () {
            Route::get('/projects/{project}/hour-contributions', [HourContributionController::class, 'index'])->name('hour-contributions.index');
            Route::get('/projects/{project}/hour-contributions/{contribution}/proposta', [HourContributionController::class, 'downloadProposta'])->name('hour-contributions.proposta.download');
        });

        Route::middleware('permission.or.admin:projects.update')->group(function () {
            Route::post('/projects/{project}/hour-contributions', [HourContributionController::class, 'store'])->name('hour-contributions.store');
            Route::put('/projects/{project}/hour-contributions/{contribution}', [HourContributionController::class, 'update'])->name('hour-contributions.update');
            Route::delete('/projects/{project}/hour-contributions/{contribution}', [HourContributionController::class, 'destroy'])->name('hour-contributions.destroy');
        });

        // Mover aporte no Kanban (transição comercial novo_contrato ↔ aporte) — admin,
        // coordenador (auto-pass do middleware) e administrativo (via contracts.manage).
        // Separado do grupo acima pra não permitir editar/deletar aporte ao administrativo.
        Route::middleware('permission.or.admin:projects.update,contracts.manage')->group(function () {
            Route::patch('/projects/{project}/hour-contributions/{contribution}/move', [HourContributionController::class, 'moveKanban'])->name('hour-contributions.move');
        });

        // ⏰ TIMESHEETS - Protegido por permissões específicas (Admins sempre têm acesso)

        // Rotas que qualquer usuário autenticado pode acessar (com lógica de permissão no controller)
        Route::get('/timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
        Route::get('/timesheets/export', [TimesheetController::class, 'export'])->name('timesheets.export');
        Route::put('/timesheets/bulk-extra-pct', [TimesheetController::class, 'bulkExtraPct'])->name('timesheets.bulk-extra-pct');
        Route::put('/timesheets/bulk-update-project-customer', [TimesheetController::class, 'bulkUpdateProjectCustomer'])->name('timesheets.bulk-update-project-customer');
        Route::post('/timesheets/reprocess-movidesk', [TimesheetController::class, 'reprocessMovidesk'])->name('timesheets.reprocess-movidesk');
        Route::get('/timesheets/summary-by-ticket', [TimesheetController::class, 'summaryByTicket'])->name('timesheets.summary-by-ticket');
        Route::get('/timesheets/atrasos', [TimesheetController::class, 'atrasos'])->name('timesheets.atrasos');

        // Saldo inicial de ticket (admin/coord) — soma no histórico do ticket
        Route::get   ('/ticket-initial-balances/lookup', [\App\Http\Controllers\TicketInitialBalanceController::class, 'lookup'])->name('ticket-initial-balances.lookup');
        Route::get   ('/ticket-initial-balances',        [\App\Http\Controllers\TicketInitialBalanceController::class, 'index'])->name('ticket-initial-balances.index');
        Route::get   ('/ticket-initial-balances/{id}',   [\App\Http\Controllers\TicketInitialBalanceController::class, 'show'])->name('ticket-initial-balances.show');
        Route::post  ('/ticket-initial-balances',        [\App\Http\Controllers\TicketInitialBalanceController::class, 'store'])->name('ticket-initial-balances.store');
        Route::put   ('/ticket-initial-balances/{id}',   [\App\Http\Controllers\TicketInitialBalanceController::class, 'update'])->name('ticket-initial-balances.update');
        Route::delete('/ticket-initial-balances/{id}',   [\App\Http\Controllers\TicketInitialBalanceController::class, 'destroy'])->name('ticket-initial-balances.destroy');

        Route::get('/timesheets/{timesheet}', [TimesheetController::class, 'show'])->name('timesheets.show');

        // Histórico de alterações de um apontamento específico (admin/coord)
        Route::middleware('permission.or.admin:hours.approve')->group(function () {
            Route::get('/timesheets/{id}/logs', [\App\Http\Controllers\TimesheetLogController::class, 'forTimesheet'])->name('timesheets.logs');
            Route::get('/timesheets/{id}/access', [TimesheetController::class, 'access'])->name('timesheets.access');
            Route::get('/timesheet-logs', [\App\Http\Controllers\TimesheetLogController::class, 'index'])->name('timesheet-logs.index');
        });

        // Qualquer usuário autenticado (exceto Cliente — verificado no controller) pode criar apontamentos
        Route::post('/timesheets', [TimesheetController::class, 'store'])->name('timesheets.store');

        // Atualização e exclusão verificadas no controller baseado na propriedade
        Route::put('/timesheets/{timesheet}', [TimesheetController::class, 'update'])->name('timesheets.update');
        Route::patch('/timesheets/{timesheet}', [TimesheetController::class, 'update'])->name('timesheets.patch');
        Route::delete('/timesheets/{timesheet}', [TimesheetController::class, 'destroy'])->name('timesheets.destroy');

        // Aprovação, liberação e rejeição
        Route::middleware('permission.or.admin:hours.approve')->group(function () {
            Route::post('/timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])->name('timesheets.approve');
            Route::post('/timesheets/{timesheet}/aprovar-atraso', [TimesheetController::class, 'aprovarAtraso'])->name('timesheets.aprovar-atraso');
            Route::post('/timesheets/{timesheet}/release', [TimesheetController::class, 'release'])->name('timesheets.release');
            Route::post('/timesheets/{timesheet}/reverse-release', [TimesheetController::class, 'reverseRelease'])->name('timesheets.reverse-release');
        });

        Route::middleware('permission.or.admin:hours.reject')->group(function () {
            Route::post('/timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])->name('timesheets.reject');
            Route::post('/timesheets/{timesheet}/request-adjustment', [TimesheetController::class, 'requestAdjustment'])->name('timesheets.request-adjustment');
            Route::post('/timesheets/{timesheet}/reverse-approval', [TimesheetController::class, 'reverseApproval'])->name('timesheets.reverse-approval');
            Route::post('/timesheets/{timesheet}/reverse-rejection', [TimesheetController::class, 'reverseRejection'])->name('timesheets.reverse-rejection');
        });

        // 💰 DESPESAS - Protegido por permissões específicas (Admins sempre têm acesso)
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/export', [ExpenseController::class, 'export'])->name('expenses.export');
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');

        // Qualquer usuário autenticado (exceto Cliente — verificado no controller) pode registrar despesas
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');

        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::patch('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.patch');
        Route::post('/expenses/{expense}/set-fechamento', [ExpenseController::class, 'setFechamento'])->name('expenses.set-fechamento');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

        Route::middleware('permission.or.admin:expenses.approve')->group(function () {
            Route::post('/expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve');
        });

        Route::middleware('permission.or.admin:expenses.reject')->group(function () {
            Route::post('/expenses/{expense}/reject', [ExpenseController::class, 'reject'])->name('expenses.reject');
            Route::post('/expenses/{expense}/request-adjustment', [ExpenseController::class, 'requestAdjustment'])->name('expenses.request-adjustment');
            Route::post('/expenses/{expense}/reverse-approval', [ExpenseController::class, 'reverseApproval'])->name('expenses.reverse-approval');
            Route::post('/expenses/{expense}/reverse-rejection', [ExpenseController::class, 'reverseRejection'])->name('expenses.reverse-rejection');
        });

        Route::post('/expenses/{expense}/upload-receipt', [ExpenseController::class, 'uploadReceipt'])->name('expenses.upload-receipt');
        Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'downloadReceipt'])->name('expenses.download-receipt');

        Route::post('/expenses/{expense}/set-paid', [ExpenseController::class, 'setPaid'])->name('expenses.set-paid');

        Route::get('/timesheets/{id}/attachment', [TimesheetController::class, 'downloadAttachment'])->name('timesheets.download-attachment');

        // 📝 CATEGORIAS DE DESPESAS
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
        Route::get('/expense-categories/tree', [ExpenseCategoryController::class, 'tree'])->name('expense-categories.tree');
        Route::get('/expense-categories/main', [ExpenseCategoryController::class, 'main'])->name('expense-categories.main');
        Route::get('/expense-categories/{id}', [ExpenseCategoryController::class, 'show'])->name('expense-categories.show');
        Route::get('/expense-categories/{parentId}/subcategories', [ExpenseCategoryController::class, 'subcategories'])->name('expense-categories.subcategories');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:expense_categories.create')->group(function () {
            Route::post('/expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        });

        Route::middleware('permission.or.admin:expense_categories.update')->group(function () {
            Route::put('/expense-categories/{id}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
        });

        Route::middleware('permission.or.admin:expense_categories.delete')->group(function () {
            Route::delete('/expense-categories/{id}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy');
        });

        // 📋 TIPOS DE DESPESAS
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/expense-types', [ExpenseTypeController::class, 'index'])->name('expense-types.index');
        Route::get('/expense-types/{id}', [ExpenseTypeController::class, 'show'])->name('expense-types.show');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:expense_types.create')->group(function () {
            Route::post('/expense-types', [ExpenseTypeController::class, 'store'])->name('expense-types.store');
        });

        Route::middleware('permission.or.admin:expense_types.update')->group(function () {
            Route::put('/expense-types/{id}', [ExpenseTypeController::class, 'update'])->name('expense-types.update');
        });

        Route::middleware('permission.or.admin:expense_types.delete')->group(function () {
            Route::delete('/expense-types/{id}', [ExpenseTypeController::class, 'destroy'])->name('expense-types.destroy');
        });

        // 💳 MÉTODOS DE PAGAMENTO
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::get('/payment-methods/{id}', [PaymentMethodController::class, 'show'])->name('payment-methods.show');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:payment_methods.create')->group(function () {
            Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
        });

        Route::middleware('permission.or.admin:payment_methods.update')->group(function () {
            Route::put('/payment-methods/{id}', [PaymentMethodController::class, 'update'])->name('payment-methods.update');
        });

        Route::middleware('permission.or.admin:payment_methods.delete')->group(function () {
            Route::delete('/payment-methods/{id}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');
        });

        // 👤 USUÁRIOS - Protegido por permissões específicas (Admins sempre têm acesso)
        // Buscar usuários que podem aprovar (para seleção em projetos)
        Route::get('/users/approvers', [UserController::class, 'getApprovers'])->name('users.approvers');

        // Buscar usuários para seleção em apontamentos (apenas administradores)
        Route::get('/users/for-timesheets', [UserController::class, 'getUsersForTimesheets'])->name('users.for-timesheets');

        // Perfil do usuário (sempre acessível para usuários autenticados)
        Route::get('/users/profile', [UserController::class, 'profile'])->name('users.profile');
        Route::put('/users/profile', [UserController::class, 'updateProfile'])->name('users.update-profile');
        Route::post('/signature/preview', [UserController::class, 'signaturePreview'])->name('signature.preview');
        Route::get('/signature/mine', [UserController::class, 'mySignature'])->name('signature.mine');
        Route::get('/profile-cargos', [\App\Http\Controllers\ProfileCargoController::class, 'index'])->name('profile-cargos.index');
        Route::put('/profile-cargos/{profile}', [\App\Http\Controllers\ProfileCargoController::class, 'update'])->name('profile-cargos.update');

        // Cadastro de Perfil → Módulos de navegação (Administrativo / Serviços)
        Route::get('/profile-modules', [\App\Http\Controllers\ProfileModuleController::class, 'index'])->name('profile-modules.index');
        Route::put('/profile-modules/{profile}', [\App\Http\Controllers\ProfileModuleController::class, 'update'])->name('profile-modules.update');

        // Configurador de navegação: módulos dinâmicos + associação de itens de menu
        Route::get('/nav-config',                  [\App\Http\Controllers\NavConfigController::class, 'index'])->name('nav-config.index');
        Route::get('/my-denied-actions',           [\App\Http\Controllers\NavConfigController::class, 'myDeniedActions'])->name('my-denied-actions');
        Route::put('/nav-screens',                 [\App\Http\Controllers\NavConfigController::class, 'saveScreens'])->name('nav-screens.save');
        Route::post('/nav-modules',                [\App\Http\Controllers\NavConfigController::class, 'store'])->name('nav-modules.store');
        Route::post('/nav-modules/reorder',        [\App\Http\Controllers\NavConfigController::class, 'reorder'])->name('nav-modules.reorder');
        Route::put('/nav-modules/{navModule}',     [\App\Http\Controllers\NavConfigController::class, 'update'])->name('nav-modules.update');
        Route::delete('/nav-modules/{navModule}',  [\App\Http\Controllers\NavConfigController::class, 'destroy'])->name('nav-modules.destroy');
        Route::post('/nav-screen-actions',         [\App\Http\Controllers\NavConfigController::class, 'addScreenAction'])->name('nav-screen-actions.add');
        Route::delete('/nav-screen-actions',       [\App\Http\Controllers\NavConfigController::class, 'deleteScreenAction'])->name('nav-screen-actions.delete');

        // Liberação de visualização do pipeline "Demandas e Projetos" (por usuário).
        Route::get('/pipeline-view-permissions',            [\App\Http\Controllers\PipelineViewPermissionController::class, 'index'])->name('pipeline-view-permissions.index');
        Route::post('/pipeline-view-permissions',           [\App\Http\Controllers\PipelineViewPermissionController::class, 'upsert'])->name('pipeline-view-permissions.upsert');
        Route::delete('/pipeline-view-permissions/{userId}',[\App\Http\Controllers\PipelineViewPermissionController::class, 'destroy'])->name('pipeline-view-permissions.destroy');

        // Upload de foto de perfil
        Route::post('/users/profile/photo', [UserController::class, 'uploadProfilePhoto'])->name('users.upload-photo');
        Route::delete('/users/profile/photo', [UserController::class, 'removeProfilePhoto'])->name('users.remove-photo');
        Route::post('/users/profile/reset-password', [UserController::class, 'selfResetPassword'])->name('users.profile.reset-password');

        // Gerenciamento completo de usuários (requer permissões específicas)
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        // IMPORTANTE: /users/capacity precisa vir ANTES de /users/{user} pra não cair
        // no implicit param routing como {user}=capacity.
        Route::middleware('block.cliente')->group(function () {
            Route::get('/users/capacity', [UserCapacityController::class, 'index'])->name('users.capacity.index');
            Route::get('/users/{user}/capacity', [UserCapacityController::class, 'show'])
                ->where('user', '[0-9]+')
                ->name('users.capacity.show');
        });
        Route::get('/users/{user}', [UserController::class, 'show'])
            ->where('user', '[0-9]+')
            ->name('users.show');

        Route::middleware(['permission.or.admin:users.create', 'screen.action:/users,create'])->group(function () {
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
        });

        // Editar OUTROS usuários (auto-edição de perfil é /users/profile, não passa por aqui).
        Route::middleware('screen.action:/users,edit')->group(function () {
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.patch');
        });

        // Atualização em massa do tipo de contrato (cooperado/clt/pj)
        Route::post('/users/bulk-contract-type', [UserController::class, 'bulkContractType'])->name('users.bulk-contract-type');

        Route::middleware(['permission.or.admin:users.delete', 'screen.action:/users,delete'])->group(function () {
            Route::delete('/users/{user}',    [UserController::class, 'destroy'])->name('users.destroy');
            Route::delete('/users',           [UserController::class, 'bulkDestroy'])->name('users.bulk-destroy');
        });

        Route::middleware(['permission.or.admin:users.reset_password', 'screen.action:/users,reset_password'])->group(function () {
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        });

        Route::middleware(['permission.or.admin:users.reset_password', 'screen.action:/users,resend_welcome'])->group(function () {
            Route::post('/users/{user}/resend-welcome', [UserController::class, 'resendWelcome'])->name('users.resend-welcome');
            Route::post('/users/resend-welcome-bulk',   [UserController::class, 'resendWelcomeBulk'])->name('users.resend-welcome-bulk');
            // Convite (fase 1b): ativa um pré-cadastro cliente (senha temp + enable + e-mail).
            Route::post('/users/{user}/invite',         [UserController::class, 'invite'])->name('users.invite');
        });

        // Histórico de alterações de valor hora
        Route::get('/users/{user}/hourly-rate-history', [UserController::class, 'getHourlyRateHistory'])->name('users.hourly-rate-history');

        // 📊 INDICADORES — Auster (admin only via check no controller; inclui projetos congelados)
        Route::get('/indicadores/auster/projects',      [AusterIndicatorsController::class, 'projects'])->name('indicadores.auster.projects');
        Route::get('/indicadores/auster/top-consumed',  [AusterIndicatorsController::class, 'topConsumed'])->name('indicadores.auster.top-consumed');

        // 🎯 APROVAÇÕES - Endpoints para gerenciar aprovações pendentes
        Route::middleware('permission.or.admin:timesheets.approve,expenses.approve')->group(function () {
            Route::get('/approvals/pending', [ApprovalController::class, 'getPendingApprovals'])->name('approvals.pending');
            Route::get('/approvals/timesheets', [ApprovalController::class, 'getPendingTimesheets'])->name('approvals.timesheets');
            Route::get('/approvals/expenses', [ApprovalController::class, 'getPendingExpenses'])->name('approvals.expenses');
            Route::post('/approvals/timesheets/bulk-approve', [ApprovalController::class, 'bulkApproveTimesheets'])->name('approvals.timesheets.bulk-approve');
            Route::post('/approvals/timesheets/bulk-reject', [ApprovalController::class, 'bulkRejectTimesheets'])->name('approvals.timesheets.bulk-reject');
            Route::post('/approvals/timesheets/bulk-request-adjustment', [ApprovalController::class, 'bulkRequestAdjustmentTimesheets'])->name('approvals.timesheets.bulk-request-adjustment');
            Route::post('/approvals/expenses/bulk-approve', [ApprovalController::class, 'bulkApproveExpenses'])->name('approvals.expenses.bulk-approve');
        });

        // 🔧 CAMPOS CUSTOMIZADOS - Campos customizados por contexto
        // Listar e visualizar campos (todos usuários autenticados)
        Route::get('/custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
        Route::get('/custom-fields/{customField}', [CustomFieldController::class, 'show'])->name('custom-fields.show');

        // Gerenciar campos customizados (apenas administradores)
        Route::post('/custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
        Route::put('/custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
        Route::delete('/custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');

        // Valores de campos customizados (contexto dinâmico: projects, timesheets, expenses, customers, opportunities, contacts, products, tasks, proposals)
        Route::get('/{context}/{entityId}/custom-field-values', [CustomFieldController::class, 'getValues'])
            ->name('custom-field-values.get')
            ->where('context', 'projects|timesheets|expenses|customers|opportunities|contacts|products|tasks|proposals');
        Route::post('/{context}/{entityId}/custom-field-values', [CustomFieldController::class, 'saveValues'])
            ->name('custom-field-values.save')
            ->where('context', 'projects|timesheets|expenses|customers|opportunities|contacts|products|tasks|proposals');

        // 👥 GRUPOS DE CONSULTORES - Protegido por permissões específicas (Admins sempre têm acesso)
        // Listar consultores disponíveis
        Route::middleware('permission.or.admin:consultant_groups.view')->group(function () {
            Route::get('/consultant-groups/available-consultants', [ConsultantGroupController::class, 'availableConsultants'])
                ->name('consultant-groups.available-consultants');
        });

        Route::middleware('permission.or.admin:consultant_groups.view')->group(function () {
            Route::get('/consultant-groups', [ConsultantGroupController::class, 'index'])->name('consultant-groups.index');
            Route::get('/consultant-groups/{consultant_group}', [ConsultantGroupController::class, 'show'])->name('consultant-groups.show');
        });

        Route::middleware('permission.or.admin:consultant_groups.create')->group(function () {
            Route::post('/consultant-groups', [ConsultantGroupController::class, 'store'])->name('consultant-groups.store');
        });

        Route::middleware('permission.or.admin:consultant_groups.update')->group(function () {
            Route::put('/consultant-groups/{consultant_group}', [ConsultantGroupController::class, 'update'])->name('consultant-groups.update');
        });

        Route::middleware('permission.or.admin:consultant_groups.delete')->group(function () {
            Route::delete('/consultant-groups/{consultant_group}', [ConsultantGroupController::class, 'destroy'])->name('consultant-groups.destroy');
        });

        // 🔐 GRUPOS DE PERMISSÕES
        Route::get('/permission-groups/available-permissions', [PermissionGroupController::class, 'availablePermissions'])
            ->name('permission-groups.available-permissions');
        Route::get('/permission-groups', [PermissionGroupController::class, 'index'])->name('permission-groups.index');
        Route::get('/permission-groups/{permissionGroup}', [PermissionGroupController::class, 'show'])->name('permission-groups.show');
        Route::get('/permission-groups/{permissionGroup}/users', [PermissionGroupController::class, 'users'])->name('permission-groups.users');
        Route::post('/permission-groups', [PermissionGroupController::class, 'store'])->name('permission-groups.store');
        Route::put('/permission-groups/{permissionGroup}', [PermissionGroupController::class, 'update'])->name('permission-groups.update');
        Route::post('/permission-groups/{permissionGroup}/users', [PermissionGroupController::class, 'addUser'])->name('permission-groups.add-user');
        Route::delete('/permission-groups/{permissionGroup}/users/{user}', [PermissionGroupController::class, 'removeUser'])->name('permission-groups.remove-user');
        Route::delete('/permission-groups/{permissionGroup}', [PermissionGroupController::class, 'destroy'])->name('permission-groups.destroy');

        // 🧭 Cadastro de Perfil → Módulos de navegação (Serviços / Administrativo) — admin
        Route::get('/profile-modules', [\App\Http\Controllers\ProfileModuleController::class, 'index'])->name('profile-modules.index');
        Route::put('/profile-modules/{profile}', [\App\Http\Controllers\ProfileModuleController::class, 'update'])->name('profile-modules.update');

        // 🤝 CRM — Fase 1A (cadastros base). Empresa = customers (não duplica).
        Route::get('/crm/products',  [\App\Http\Controllers\CrmProductController::class, 'index']);
        Route::post('/crm/products', [\App\Http\Controllers\CrmProductController::class, 'store']);
        Route::put('/crm/products/{crmProduct}',    [\App\Http\Controllers\CrmProductController::class, 'update']);
        Route::delete('/crm/products/{crmProduct}', [\App\Http\Controllers\CrmProductController::class, 'destroy']);
        Route::get('/crm/tags',  [\App\Http\Controllers\CustomerCrmController::class, 'tagsIndex']);
        Route::post('/crm/tags', [\App\Http\Controllers\CustomerCrmController::class, 'tagsStore']);
        Route::put('/crm/tags/{tag}', [\App\Http\Controllers\CustomerCrmController::class, 'tagsUpdate']);
        Route::delete('/crm/tags/{tag}', [\App\Http\Controllers\CustomerCrmController::class, 'tagsDestroy']);
        Route::get('/customers/{customer}/crm', [\App\Http\Controllers\CustomerCrmController::class, 'show']);
        // Solicitação de código-fonte (Fase 0) — fontes Git autorizadas do cliente (READ-ONLY)
        Route::get('/customers/{customer}/source-repos', [\App\Http\Controllers\ClientSourceRepoController::class, 'index']);
        Route::post('/customers/{customer}/source-repos', [\App\Http\Controllers\ClientSourceRepoController::class, 'store']);
        Route::get('/source-repos/available', [\App\Http\Controllers\ClientSourceRepoController::class, 'available']);
        // Solicitação de código-fonte (Fase 1A) — buscas
        Route::get('/source-code/clients', [\App\Http\Controllers\SourceCodeController::class, 'clients']);
        Route::get('/source-code/config', [\App\Http\Controllers\SourceCodeController::class, 'config']);
        Route::put('/source-code/config', [\App\Http\Controllers\SourceCodeController::class, 'saveConfig']);
        Route::get('/source-code/search', [\App\Http\Controllers\SourceCodeController::class, 'search']);
        Route::get('/source-code/tickets', [\App\Http\Controllers\SourceCodeController::class, 'tickets']);
        // Fase 1C — criar solicitação + anexar por item + finalizar
        Route::post('/source-code/requests', [\App\Http\Controllers\SourceCodeRequestController::class, 'store']);
        Route::post('/source-code/request-items/{sourceCodeRequestItem}/attach', [\App\Http\Controllers\SourceCodeRequestController::class, 'attachItem']);
        Route::post('/source-code/requests/{sourceCodeRequest}/finalize', [\App\Http\Controllers\SourceCodeRequestController::class, 'finalize']);
        Route::put('/source-repos/{sourceRepo}', [\App\Http\Controllers\ClientSourceRepoController::class, 'update']);
        Route::delete('/source-repos/{sourceRepo}', [\App\Http\Controllers\ClientSourceRepoController::class, 'destroy']);
        Route::post('/source-repos/{sourceRepo}/test', [\App\Http\Controllers\ClientSourceRepoController::class, 'test']);
        Route::post('/source-repos/{sourceRepo}/verify', [\App\Http\Controllers\ClientSourceRepoController::class, 'verify']);
        // Central de Fontes (C1) — catálogo de documentação de fonte (SOMENTE LEITURA).
        Route::middleware('permission.or.admin:source_docs.view')->group(function () {
            Route::get('/source-docs', [\App\Http\Controllers\SourceDocCatalogController::class, 'index']);
            // C2 — busca técnica (read-model)
            Route::get('/source-docs/search', [\App\Http\Controllers\SourceDocSearchController::class, 'search']);
            Route::get('/source-docs/search/suggest', [\App\Http\Controllers\SourceDocSearchController::class, 'suggest']);
            // C4b — análise de impacto (impacto no próprio escopo = source_docs.view; cross gateado no serviço)
            Route::get('/source-docs/impact', [\App\Http\Controllers\SourceDocImpactController::class, 'impact']);
            Route::get('/source-docs/{sourceDoc}/entities', [\App\Http\Controllers\SourceDocSearchController::class, 'entities'])->whereNumber('sourceDoc');
            Route::get('/source-docs/{sourceDoc}', [\App\Http\Controllers\SourceDocCatalogController::class, 'show'])->whereNumber('sourceDoc');
            Route::get('/source-docs/{sourceDoc}/documentation', [\App\Http\Controllers\SourceDocCatalogController::class, 'documentation'])->whereNumber('sourceDoc');
            Route::get('/source-docs/{sourceDoc}/versions', [\App\Http\Controllers\SourceDocCatalogController::class, 'versions'])->whereNumber('sourceDoc');
            // C3 — estado do reprocess é leitura (source_docs.view)
            Route::get('/source-docs/{sourceDoc}/execution', [\App\Http\Controllers\SourceDocActionController::class, 'execution'])->whereNumber('sourceDoc');
            // C3.5 — cobertura do acervo (leitura)
            Route::get('/source-docs/coverage', [\App\Http\Controllers\SourceDocCoverageController::class, 'coverage']);
            // F2 — Acervo: árvore lazy Empresa → Repositório → Diretório Git → Fonte (leitura, escopado).
            Route::get('/source-docs/tree/customers', [\App\Http\Controllers\SourceDocTreeController::class, 'customers']);
            Route::get('/source-docs/tree/customers/{customer}/repos', [\App\Http\Controllers\SourceDocTreeController::class, 'repos'])->whereNumber('customer');
            Route::get('/source-docs/tree/nodes', [\App\Http\Controllers\SourceDocTreeController::class, 'nodes']);
            // F4 — inteligência agregada do acervo por Empresa/Repo/Diretório (patrimônio já produzido; IA=US$0).
            Route::get('/source-docs/tree/knowledge', [\App\Http\Controllers\SourceDocTreeController::class, 'knowledge']);
        });
        // C3.5 — disparar inventário (Admin/ops, gate estrito)
        Route::middleware('permission:source_docs.inventory')->group(function () {
            Route::post('/source-docs/inventory', [\App\Http\Controllers\SourceDocCoverageController::class, 'inventory']);
        });
        // Campanha de Documentação Semântica Inicial (Admin) — baseline governado, interno ERPSERV.
        Route::middleware('permission:source_docs.semantic_campaign')->group(function () {
            Route::get('/source-docs/campaigns', [\App\Http\Controllers\SourceDocCampaignController::class, 'index']);
            Route::post('/source-docs/campaigns', [\App\Http\Controllers\SourceDocCampaignController::class, 'store']);
            Route::get('/source-docs/campaigns/{id}', [\App\Http\Controllers\SourceDocCampaignController::class, 'show'])->whereNumber('id');
            Route::post('/source-docs/campaigns/{id}/start', [\App\Http\Controllers\SourceDocCampaignController::class, 'start'])->whereNumber('id');
            Route::post('/source-docs/campaigns/{id}/pause', [\App\Http\Controllers\SourceDocCampaignController::class, 'pause'])->whereNumber('id');
            Route::post('/source-docs/campaigns/{id}/resume', [\App\Http\Controllers\SourceDocCampaignController::class, 'resume'])->whereNumber('id');
            Route::post('/source-docs/campaigns/{id}/cancel', [\App\Http\Controllers\SourceDocCampaignController::class, 'cancel'])->whereNumber('id');
        });
        // Frente A — Governança de custo de IA (Admin/interno ERPSERV; sem escopo de cliente).
        Route::middleware('permission:source_docs.cost_settings.view')->group(function () {
            Route::get('/source-docs/cost-settings', [\App\Http\Controllers\SourceDocCostSettingsController::class, 'index']);
        });
        Route::middleware('permission:source_docs.cost_settings.manage')->group(function () {
            Route::put('/source-docs/cost-settings', [\App\Http\Controllers\SourceDocCostSettingsController::class, 'update']);
        });
        Route::middleware('permission:source_docs.cost_approval.view')->group(function () {
            Route::get('/source-docs/cost-approvals', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'index']);
            Route::get('/source-docs/cost-approvals/{id}', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'show'])->whereNumber('id');
        });
        Route::middleware('permission:source_docs.cost_approval.decide')->group(function () {
            Route::post('/source-docs/cost-approvals/{id}/approve-step', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'approveStep'])->whereNumber('id');
            Route::post('/source-docs/cost-approvals/{id}/approve-limit', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'approveLimit'])->whereNumber('id');
            Route::post('/source-docs/cost-approvals/{id}/close-partial', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'closePartial'])->whereNumber('id');
            Route::post('/source-docs/cost-approvals/{id}/reject', [\App\Http\Controllers\SourceDocCostApprovalController::class, 'reject'])->whereNumber('id');
        });
        // C3 — ações operacionais: uma permissão por ação (nada hardcoded no controller).
        Route::middleware('permission.or.admin:source_docs.validate')->group(function () {
            Route::post('/source-docs/{sourceDoc}/validate', [\App\Http\Controllers\SourceDocActionController::class, 'validate'])->whereNumber('sourceDoc');
        });
        // reprocess = gate ESTRITO (não auto-passa Coordenador; MVP = só Admin, data-driven).
        Route::middleware('permission:source_docs.reprocess')->group(function () {
            Route::get('/source-docs/{sourceDoc}/reprocess/plan', [\App\Http\Controllers\SourceDocActionController::class, 'reprocessPlan'])->whereNumber('sourceDoc');
            Route::post('/source-docs/{sourceDoc}/reprocess', [\App\Http\Controllers\SourceDocActionController::class, 'reprocess'])->whereNumber('sourceDoc');
            Route::post('/source-docs/{sourceDoc}/topup', [\App\Http\Controllers\SourceDocActionController::class, 'topup'])->whereNumber('sourceDoc');
        });
        Route::middleware('permission.or.admin:source_docs.download')->group(function () {
            Route::get('/source-docs/{sourceDoc}/render', [\App\Http\Controllers\SourceDocActionController::class, 'render'])->whereNumber('sourceDoc');
        });
        Route::middleware('permission.or.admin:source_docs.view_git')->group(function () {
            Route::get('/source-docs/{sourceDoc}/git-url', [\App\Http\Controllers\SourceDocActionController::class, 'gitUrl'])->whereNumber('sourceDoc');
            // F3 — código da versão atual (somente leitura), para a aba Código do Acervo.
            Route::get('/source-docs/{sourceDoc}/source', [\App\Http\Controllers\SourceDocActionController::class, 'source'])->whereNumber('sourceDoc');
        });
        Route::middleware('permission.or.admin:source_docs.view_diff')->group(function () {
            Route::get('/source-docs/{sourceDoc}/compare', [\App\Http\Controllers\SourceDocActionController::class, 'compare'])->whereNumber('sourceDoc');
        });
        Route::put('/customers/{customer}/crm', [\App\Http\Controllers\CustomerCrmController::class, 'update']);

        // 🤝 CRM — Fase 1B (Pipeline & Oportunidades)
        Route::get('/crm/pipelines', [\App\Http\Controllers\CrmPipelineController::class, 'index']);
        // CRM configurável (Fase 1) — gestão de pipelines/etapas
        Route::get('/crm/pipelines/manage', [\App\Http\Controllers\CrmPipelineController::class, 'manageIndex']);
        Route::post('/crm/pipelines', [\App\Http\Controllers\CrmPipelineController::class, 'storePipeline']);
        Route::patch('/crm/pipelines/reorder', [\App\Http\Controllers\CrmPipelineController::class, 'reorderPipelines']);
        Route::put('/crm/pipelines/{pipeline}', [\App\Http\Controllers\CrmPipelineController::class, 'updatePipeline']);
        Route::post('/crm/pipelines/{pipeline}/duplicate', [\App\Http\Controllers\CrmPipelineController::class, 'duplicatePipeline']);
        Route::delete('/crm/pipelines/{pipeline}', [\App\Http\Controllers\CrmPipelineController::class, 'destroyPipeline']);
        Route::get('/crm/pipeline-events', [\App\Http\Controllers\CrmPipelineController::class, 'events']);
        Route::post('/crm/pipelines/{pipeline}/stages', [\App\Http\Controllers\CrmPipelineController::class, 'storeStage']);
        Route::patch('/crm/pipelines/{pipeline}/stages/reorder', [\App\Http\Controllers\CrmPipelineController::class, 'reorderStages']);
        Route::put('/crm/pipeline-stages/{stage}', [\App\Http\Controllers\CrmPipelineController::class, 'updateStage']);
        Route::delete('/crm/pipeline-stages/{stage}', [\App\Http\Controllers\CrmPipelineController::class, 'destroyStage']);
        // Automações de etapa (Fase 3)
        Route::get('/crm/pipeline-stages/{stage}/automations', [\App\Http\Controllers\CrmStageAutomationController::class, 'index']);
        Route::post('/crm/pipeline-stages/{stage}/automations', [\App\Http\Controllers\CrmStageAutomationController::class, 'store']);
        Route::put('/crm/stage-automations/{automation}', [\App\Http\Controllers\CrmStageAutomationController::class, 'update']);
        Route::delete('/crm/stage-automations/{automation}', [\App\Http\Controllers\CrmStageAutomationController::class, 'destroy']);
        Route::get('/crm/users', [\App\Http\Controllers\CrmOpportunityController::class, 'crmUsers']);
        Route::get('/crm/responsaveis', [\App\Http\Controllers\CrmResponsavelController::class, 'index']);
        Route::put('/crm/responsaveis/{user}', [\App\Http\Controllers\CrmResponsavelController::class, 'update']);
        Route::get('/crm/opportunities/kanban', [\App\Http\Controllers\CrmOpportunityController::class, 'kanban']);
        Route::get('/crm/opportunities/export', [\App\Http\Controllers\CrmOpportunityController::class, 'export']);
        Route::get('/crm/opportunities',  [\App\Http\Controllers\CrmOpportunityController::class, 'index']);
        Route::post('/crm/opportunities', [\App\Http\Controllers\CrmOpportunityController::class, 'store']);
        Route::get('/crm/opportunities/{opportunity}',  [\App\Http\Controllers\CrmOpportunityController::class, 'show']);
        Route::put('/crm/opportunities/{opportunity}',  [\App\Http\Controllers\CrmOpportunityController::class, 'update']);
        Route::patch('/crm/opportunities/{opportunity}/stage', [\App\Http\Controllers\CrmOpportunityController::class, 'moveStage']);
        Route::patch('/crm/opportunities/{opportunity}/status', [\App\Http\Controllers\CrmOpportunityController::class, 'mudarStatus']);
        // Produtos vinculados (Item 3)
        Route::post('/crm/opportunities/{opportunity}/products', [\App\Http\Controllers\CrmOpportunityController::class, 'addProduct']);
        Route::put('/crm/opportunities/{opportunity}/products/{product}', [\App\Http\Controllers\CrmOpportunityController::class, 'updateProduct']);
        Route::delete('/crm/opportunities/{opportunity}/products/{product}', [\App\Http\Controllers\CrmOpportunityController::class, 'removeProduct']);
        Route::put('/crm/opportunities/{opportunity}/qualificacao', [\App\Http\Controllers\CrmOpportunityController::class, 'qualificar']);
        Route::post('/crm/opportunities/{opportunity}/bloqueio', [\App\Http\Controllers\CrmOpportunityController::class, 'registrarBloqueio']);
        // Anexos da oportunidade
        Route::get('/crm/opportunities/{opportunity}/attachments', [\App\Http\Controllers\CrmOpportunityController::class, 'attachments']);
        Route::post('/crm/opportunities/{opportunity}/attachments', [\App\Http\Controllers\CrmOpportunityController::class, 'uploadAttachment']);
        Route::get('/crm/opportunities/{opportunity}/attachments/{attachment}/download', [\App\Http\Controllers\CrmOpportunityController::class, 'downloadAttachment']);
        Route::delete('/crm/opportunities/{opportunity}/attachments/{attachment}', [\App\Http\Controllers\CrmOpportunityController::class, 'deleteAttachment']);
        Route::get('/crm/tasks/agenda', [\App\Http\Controllers\CrmTaskController::class, 'agenda']);
        Route::get('/crm/tasks',  [\App\Http\Controllers\CrmTaskController::class, 'index']);
        Route::post('/crm/tasks', [\App\Http\Controllers\CrmTaskController::class, 'store']);
        Route::patch('/crm/tasks/{crmTask}/complete', [\App\Http\Controllers\CrmTaskController::class, 'complete']);
        Route::put('/crm/tasks/{crmTask}', [\App\Http\Controllers\CrmTaskController::class, 'update']);
        Route::delete('/crm/tasks/{crmTask}', [\App\Http\Controllers\CrmTaskController::class, 'destroy']);

        // 🤝 CRM — Fase 1C (Propostas) + Editor (Fase 1.4)
        Route::get('/crm/proposals',  [\App\Http\Controllers\CrmProposalController::class, 'index']);
        Route::post('/crm/proposals', [\App\Http\Controllers\CrmProposalController::class, 'store']);
        // Gestão operacional Proposal-Centric (kanban) — ANTES do binding {crmProposal}.
        Route::get('/crm/proposals/board', [\App\Http\Controllers\CrmProposalController::class, 'board']);
        // Handoff p/ Serviços: fila de propostas LIBERADAS aguardando contrato operacional.
        Route::get('/crm/proposals/handoff', [\App\Http\Controllers\CrmProposalController::class, 'handoff']);
        // P-E.1.2 §4: inteligência de conteúdo agregada.
        Route::get('/crm/proposals/analytics-conteudo', [\App\Http\Controllers\CrmProposalController::class, 'analyticsConteudo']);
        // específicas ANTES do binding {crmProposal} p/ não serem capturadas
        Route::get('/crm/proposals/artwork', [\App\Http\Controllers\CrmProposalController::class, 'artwork']);
        Route::post('/crm/proposals/preview', [\App\Http\Controllers\CrmProposalController::class, 'preview']);
        Route::post('/crm/simulador', [\App\Http\Controllers\CrmProposalController::class, 'simular']);
        Route::get('/crm/saved-filters',  [\App\Http\Controllers\CrmSavedFilterController::class, 'index']);
        Route::post('/crm/saved-filters', [\App\Http\Controllers\CrmSavedFilterController::class, 'store']);
        Route::delete('/crm/saved-filters/{crmSavedFilter}', [\App\Http\Controllers\CrmSavedFilterController::class, 'destroy']);
        Route::get('/crm/proposals/logo/{attachment}', [\App\Http\Controllers\CrmProposalController::class, 'logoServe']);
        Route::get('/crm/proposals/escopo-image/{attachment}', [\App\Http\Controllers\CrmProposalController::class, 'escopoImageServe']);
        Route::get('/crm/proposal-config/contratada', [\App\Http\Controllers\CrmProposalController::class, 'contratadaGet']);
        Route::put('/crm/proposal-config/contratada', [\App\Http\Controllers\CrmProposalController::class, 'contratadaUpdate']);
        Route::get('/crm/proposal-templates', [\App\Http\Controllers\CrmProposalController::class, 'templatesList']);
        Route::get('/crm/proposal-templates/{tipo}/preview', [\App\Http\Controllers\CrmProposalController::class, 'templatePreview']);
        Route::get('/crm/proposal-templates/{tipo}', [\App\Http\Controllers\CrmProposalController::class, 'templateGet']);
        Route::put('/crm/proposal-templates/{tipo}', [\App\Http\Controllers\CrmProposalController::class, 'templateSave']);
        Route::delete('/crm/proposal-templates/{tipo}', [\App\Http\Controllers\CrmProposalController::class, 'templateDelete']);
        Route::get('/crm/proposals/{crmProposal}', [\App\Http\Controllers\CrmProposalController::class, 'show']);
        Route::put('/crm/proposals/{crmProposal}',    [\App\Http\Controllers\CrmProposalController::class, 'update']);
        Route::get('/crm/proposals/{crmProposal}/codigo-check', [\App\Http\Controllers\CrmProposalController::class, 'codigoCheck']);
        Route::put('/crm/proposals/{crmProposal}/editar', [\App\Http\Controllers\CrmProposalController::class, 'editar']);
        Route::post('/crm/proposals/{crmProposal}/gerar', [\App\Http\Controllers\CrmProposalController::class, 'gerar']);
        Route::post('/crm/proposals/{crmProposal}/email-preview', [\App\Http\Controllers\CrmProposalController::class, 'emailPreview']);
        Route::post('/crm/proposals/{crmProposal}/enviar-email', [\App\Http\Controllers\CrmProposalController::class, 'enviarEmail']);
        // CRM comercial — liberação COMERCIAL (handoff p/ Serviços). SEM operacional.
        Route::get('/crm/proposals/{crmProposal}/liberacao',      [\App\Http\Controllers\CrmProposalController::class, 'liberacao']);
        Route::get('/crm/proposals/{crmProposal}/participantes',  [\App\Http\Controllers\CrmProposalController::class, 'participantes']);
        Route::post('/crm/proposals/{crmProposal}/participantes', [\App\Http\Controllers\CrmProposalController::class, 'adicionarParticipante']);
        Route::put('/crm/proposals/{crmProposal}/participantes/{part}', [\App\Http\Controllers\CrmProposalController::class, 'atualizarParticipante']);
        Route::post('/crm/proposals/{crmProposal}/participantes/{part}/assinar', [\App\Http\Controllers\CrmProposalController::class, 'assinarParticipante']);
        Route::post('/crm/proposals/{crmProposal}/participantes/{part}/clicksign', [\App\Http\Controllers\CrmProposalController::class, 'iniciarClicksignParticipante']);
        Route::get('/crm/proposals/{crmProposal}/participantes/{part}/comprovante', [\App\Http\Controllers\CrmProposalController::class, 'comprovanteAssinatura']);
        Route::get('/crm/signature-profile', [\App\Http\Controllers\CrmProposalController::class, 'signatureProfile']);
        Route::post('/crm/proposals/{crmProposal}/participantes/{part}/reenviar', [\App\Http\Controllers\CrmProposalController::class, 'reenviarConvite']);
        Route::delete('/crm/proposals/{crmProposal}/participantes/{part}', [\App\Http\Controllers\CrmProposalController::class, 'desativarParticipante']);
        // P-E.2.2 — caderno de participantes do cliente (memória entre propostas)
        Route::get('/crm/proposals/{crmProposal}/caderno-cliente', [\App\Http\Controllers\CrmProposalController::class, 'cadernoCliente']);
        Route::post('/crm/proposals/{crmProposal}/importar-caderno', [\App\Http\Controllers\CrmProposalController::class, 'importarCaderno']);
        Route::delete('/crm/customer-signers/{signer}', [\App\Http\Controllers\CrmProposalController::class, 'excluirCadernoCliente']);
        // P-C.2 — revisões por seção (gestão comercial)
        Route::get('/crm/proposals/{crmProposal}/threads',  [\App\Http\Controllers\CrmProposalController::class, 'threads']);
        Route::post('/crm/proposals/{crmProposal}/threads/{thread}/mensagens', [\App\Http\Controllers\CrmProposalController::class, 'comentarThread']);
        Route::post('/crm/proposals/{crmProposal}/threads/{thread}/resolver',  [\App\Http\Controllers\CrmProposalController::class, 'resolverThread']);
        Route::post('/crm/proposals/{crmProposal}/aprovacao-email-preview', [\App\Http\Controllers\CrmProposalController::class, 'aprovacaoEmailPreview']);
        Route::post('/crm/proposals/{crmProposal}/solicitar-aprovacao', [\App\Http\Controllers\CrmProposalController::class, 'solicitarAprovacao']);
        Route::post('/crm/proposals/{crmProposal}/solicitar-assinatura', [\App\Http\Controllers\CrmProposalController::class, 'solicitarAssinatura']);
        Route::post('/crm/proposals/{crmProposal}/assinatura-email-preview', [\App\Http\Controllers\CrmProposalController::class, 'assinaturaEmailPreview']);
        Route::post('/crm/proposals/{crmProposal}/enviar-assinatura', [\App\Http\Controllers\CrmProposalController::class, 'enviarAssinatura']);
        Route::get('/crm/proposals/{crmProposal}/assinatura', [\App\Http\Controllers\CrmProposalController::class, 'assinaturaStatus']);
        Route::post('/crm/proposals/{crmProposal}/reenviar-assinatura', [\App\Http\Controllers\CrmProposalController::class, 'reenviarAssinatura']);
        Route::post('/crm/proposals/{crmProposal}/cancelar-assinatura', [\App\Http\Controllers\CrmProposalController::class, 'cancelarAssinatura']);
        Route::post('/crm/proposals/{crmProposal}/sincronizar-assinatura', [\App\Http\Controllers\CrmProposalController::class, 'sincronizarAssinatura']);
        Route::post('/crm/proposals/{crmProposal}/liberar',       [\App\Http\Controllers\CrmProposalController::class, 'liberar']);
        Route::post('/crm/proposals/{crmProposal}/bloquear',      [\App\Http\Controllers\CrmProposalController::class, 'bloquear']);
        Route::post('/crm/proposals/{crmProposal}/desbloquear',   [\App\Http\Controllers\CrmProposalController::class, 'desbloquear']);
        Route::post('/crm/proposals/{crmProposal}/marcar-perda',  [\App\Http\Controllers\CrmProposalController::class, 'marcarPerda']);
        // ARQUIVADO (operação pertence a Serviços/Projetos — fora do CRM):
        // Route::post('/crm/proposals/{crmProposal}/checklist',     [\App\Http\Controllers\CrmProposalController::class, 'checklistMarcar']);
        // Route::post('/crm/proposals/{crmProposal}/gerar-projeto', [\App\Http\Controllers\CrmProposalController::class, 'gerarProjeto']);
        // ARQUIVADO/INATIVO (via opcional Contrato Individual — fora do fluxo padrão Proposal-Centric):
        Route::post('/crm/proposals/{crmProposal}/converter', [\App\Http\Controllers\CrmProposalController::class, 'converter']);
        Route::post('/crm/proposals/{crmProposal}/share', [\App\Http\Controllers\CrmProposalController::class, 'criarShare']);
        Route::get('/crm/proposals/{crmProposal}/engajamento', [\App\Http\Controllers\CrmProposalController::class, 'engajamento']);
        Route::get('/crm/proposals/{crmProposal}/analytics', [\App\Http\Controllers\CrmProposalController::class, 'analytics']);
        Route::post('/crm/proposals/{crmProposal}/diagnostico-feedback', [\App\Http\Controllers\CrmProposalController::class, 'diagnosticoFeedback']);
        Route::post('/crm/proposals/{crmProposal}/shares/{share}/revoke', [\App\Http\Controllers\CrmProposalController::class, 'revokeShare']);
        Route::post('/crm/proposals/{crmProposal}/logo', [\App\Http\Controllers\CrmProposalController::class, 'logo']);
        Route::post('/crm/proposals/{crmProposal}/escopo-image', [\App\Http\Controllers\CrmProposalController::class, 'escopoImage']);
        Route::delete('/crm/proposals/{crmProposal}', [\App\Http\Controllers\CrmProposalController::class, 'destroy']);

        // 🤝 CRM — Fase 1D (Conversão comercial → contrato)
        Route::post('/crm/opportunities/{opportunity}/convert', [\App\Http\Controllers\CrmConversionController::class, 'convert']);

        // 🤝 CRM — Fase 1E (Dashboards + Timeline da empresa)
        Route::get('/crm/dashboard/summary', [\App\Http\Controllers\CrmDashboardController::class, 'summary']);
        Route::get('/crm/dashboard/forecast', [\App\Http\Controllers\CrmDashboardController::class, 'forecast']);
        Route::post('/crm/dashboard/forecast/snapshot', [\App\Http\Controllers\CrmDashboardController::class, 'snapshot']);
        Route::get('/crm/sales-targets', [\App\Http\Controllers\CrmSalesTargetController::class, 'index']);
        Route::post('/crm/sales-targets', [\App\Http\Controllers\CrmSalesTargetController::class, 'upsert']);
        Route::get('/crm/dashboard/leads', [\App\Http\Controllers\CrmDashboardController::class, 'leads']);
        Route::get('/crm/dashboard/funil', [\App\Http\Controllers\CrmDashboardController::class, 'funil']);
        Route::get('/crm/dashboard/renovacoes', [\App\Http\Controllers\CrmDashboardController::class, 'renovacoes']);
        Route::get('/crm/dashboard/origem', [\App\Http\Controllers\CrmDashboardController::class, 'origem']);
        Route::get('/crm/dashboard/relacionamento', [\App\Http\Controllers\CrmDashboardController::class, 'relacionamento']);
        Route::get('/crm/dashboard/risco-renovacao', [\App\Http\Controllers\CrmDashboardController::class, 'riscoRenovacao']);
        Route::get('/crm/carteira', [\App\Http\Controllers\CrmCarteiraController::class, 'index']);
        Route::get('/crm/search', [\App\Http\Controllers\CrmSearchController::class, 'index']);
        // Plataforma de Documentos (Fase 0.7) — download do PDF congelado por versão.
        Route::get('/documents/{document}/download', [\App\Http\Controllers\DocumentController::class, 'download']);
        // Saneamento cadastral (Item 2) — somente leitura.
        Route::get('/contracts/data-quality/vencimentos', [\App\Http\Controllers\ContractDataQualityController::class, 'vencimentos']);
        Route::get('/customers/{customer}/crm/timeline', [\App\Http\Controllers\CustomerCrmController::class, 'timeline']);
        // Visão 360° da Empresa — Fase A (Ficha da Empresa, carregamento por seções)
        Route::get('/customers/{customer}/360', [\App\Http\Controllers\Customer360Controller::class, 'show']);
        // Saúde da Conta (Roadmap Fase 1)
        Route::get('/crm/saude/painel', [\App\Http\Controllers\CrmSaudeController::class, 'painel']);
        Route::get('/customers/{customer}/saude/historico', [\App\Http\Controllers\CrmSaudeController::class, 'historico']);

        // 🤝 CRM — Camada de Leads (captação + qualificação; empresa única)
        Route::get('/crm/leads',  [\App\Http\Controllers\CrmLeadController::class, 'index']);
        Route::post('/crm/leads', [\App\Http\Controllers\CrmLeadController::class, 'store']);
        Route::put('/crm/leads/{customer}', [\App\Http\Controllers\CrmLeadController::class, 'update']);
        Route::patch('/crm/leads/{customer}/stage', [\App\Http\Controllers\CrmLeadController::class, 'moveStage']);
        Route::post('/crm/leads/{customer}/convert-prospect', [\App\Http\Controllers\CrmLeadController::class, 'convertToProspect']);
        Route::get('/crm/leads/{customer}/followups', [\App\Http\Controllers\CrmLeadController::class, 'followups']);
        Route::post('/crm/leads/{customer}/followups', [\App\Http\Controllers\CrmLeadController::class, 'addFollowup']);
        Route::post('/crm/leads/{customer}/proxima-acao', [\App\Http\Controllers\CrmLeadController::class, 'setProximaAcao']);
        Route::get('/crm/leads/{customer}/health', [\App\Http\Controllers\CrmLeadController::class, 'health']);
        Route::get('/crm/lead-sources',  [\App\Http\Controllers\CrmLeadController::class, 'sourcesIndex']);
        Route::post('/crm/lead-sources', [\App\Http\Controllers\CrmLeadController::class, 'sourcesStore']);
        Route::put('/crm/lead-sources/{source}', [\App\Http\Controllers\CrmLeadController::class, 'sourcesUpdate']);
        // Motivos de perda (Item 2)
        Route::get('/crm/loss-reasons',  [\App\Http\Controllers\CrmLossReasonController::class, 'index']);
        Route::post('/crm/loss-reasons', [\App\Http\Controllers\CrmLossReasonController::class, 'store']);
        Route::put('/crm/loss-reasons/{lossReason}', [\App\Http\Controllers\CrmLossReasonController::class, 'update']);
        // CRM — Campanhas comerciais
        Route::get('/crm/campaigns',  [\App\Http\Controllers\CrmCampaignController::class, 'index']);
        Route::post('/crm/campaigns', [\App\Http\Controllers\CrmCampaignController::class, 'store']);
        Route::put('/crm/campaigns/{campaign}', [\App\Http\Controllers\CrmCampaignController::class, 'update']);
        Route::delete('/crm/campaigns/{campaign}', [\App\Http\Controllers\CrmCampaignController::class, 'destroy']);
        // CRM — Motivos de descarte (funil de prospecção) + repescagem
        Route::get('/crm/discard-reasons',  [\App\Http\Controllers\CrmDiscardReasonController::class, 'index']);
        Route::post('/crm/discard-reasons', [\App\Http\Controllers\CrmDiscardReasonController::class, 'store']);
        Route::put('/crm/discard-reasons/{discardReason}', [\App\Http\Controllers\CrmDiscardReasonController::class, 'update']);
        Route::delete('/crm/discard-reasons/{discardReason}', [\App\Http\Controllers\CrmDiscardReasonController::class, 'destroy']);
        Route::get('/crm/segments',  [\App\Http\Controllers\CrmSegmentController::class, 'index']);
        Route::post('/crm/segments', [\App\Http\Controllers\CrmSegmentController::class, 'store']);
        Route::put('/crm/segments/{segment}', [\App\Http\Controllers\CrmSegmentController::class, 'update']);
        Route::delete('/crm/segments/{segment}', [\App\Http\Controllers\CrmSegmentController::class, 'destroy']);

        // Tipos de contato (follow-up) — cadastro
        Route::get('/crm/contact-types',  [\App\Http\Controllers\CrmContactTypeController::class, 'index']);
        Route::post('/crm/contact-types', [\App\Http\Controllers\CrmContactTypeController::class, 'store']);
        Route::put('/crm/contact-types/{crmContactType}',    [\App\Http\Controllers\CrmContactTypeController::class, 'update']);
        Route::delete('/crm/contact-types/{crmContactType}', [\App\Http\Controllers\CrmContactTypeController::class, 'destroy']);

        // 🤝 PARCEIROS
        Route::get('/partner/report', [PartnerReportController::class, 'index'])->name('partner.report');

        Route::middleware('permission.or.admin:partners.view')->group(function () {
            Route::get('/partners', [PartnerController::class, 'index'])->name('partners.index');
            Route::get('/partners/{partner}', [PartnerController::class, 'show'])->name('partners.show');
        });

        Route::middleware('permission.or.admin:partners.create')->group(function () {
            Route::post('/partners', [PartnerController::class, 'store'])->name('partners.store');
        });

        Route::middleware('permission.or.admin:partners.update')->group(function () {
            Route::put('/partners/{partner}', [PartnerController::class, 'update'])->name('partners.update');
            Route::get('/partners/{partner}/hourly-rate-history', [PartnerController::class, 'getHourlyRateHistory'])->name('partners.hourly-rate-history');
        });

        Route::middleware('permission.or.admin:partners.delete')->group(function () {
            Route::delete('/partners/{partner}', [PartnerController::class, 'destroy'])->name('partners.destroy');
        });

        // 🏦 BANCO DE HORAS (CONSULTORES)
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/consultant-hour-bank/consultants', [ConsultantHourBankController::class, 'consultants']);
            Route::get('/consultant-hour-bank/{userId}/range', [ConsultantHourBankController::class, 'range']);
            Route::get('/consultant-hour-bank/{userId}/preview', [ConsultantHourBankController::class, 'preview']);
            Route::get('/consultant-hour-bank/{userId}/history', [ConsultantHourBankController::class, 'history']);
            Route::post('/consultant-hour-bank/{userId}/close', [ConsultantHourBankController::class, 'close']);
            Route::post('/consultant-hour-bank/{userId}/reopen', [ConsultantHourBankController::class, 'reopen']);
        });

        // 💰 FECHAMENTO ADMINISTRATIVO
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/fechamento',                                  [\App\Http\Controllers\FechamentoController::class, 'index']);
            Route::get('/fechamento/{yearMonth}/producao',             [\App\Http\Controllers\FechamentoController::class, 'producao']);
            Route::get('/fechamento/{yearMonth}/custo',                [\App\Http\Controllers\FechamentoController::class, 'custo']);
            Route::get('/fechamento/{yearMonth}/receita',              [\App\Http\Controllers\FechamentoController::class, 'receita']);
            Route::get('/fechamento/{yearMonth}/consolidado',          [\App\Http\Controllers\FechamentoController::class, 'consolidado']);
            Route::get('/fechamento/{yearMonth}/validar',              [\App\Http\Controllers\FechamentoController::class, 'validar']);
            Route::post('/fechamento/{yearMonth}/fechar',              [\App\Http\Controllers\FechamentoController::class, 'fechar']);
            Route::post('/fechamento/{yearMonth}/reabrir',             [\App\Http\Controllers\FechamentoController::class, 'reabrir']);
        });

        // 📋 FECHAMENTO POR CONTRATO
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/fechamento-contrato', [\App\Http\Controllers\FechamentoContratoController::class, 'index']);
            Route::post('/on-demand/invoiced', [\App\Http\Controllers\OnDemandInvoicedController::class, 'toggle']);
        });

        // 🧾 FECHAMENTO CLIENTE
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/fechamento-cliente',                                                      [\App\Http\Controllers\FechamentoClienteController::class, 'index']);
            Route::get('/fechamento-cliente/despesas-resumo',                                       [\App\Http\Controllers\FechamentoClienteController::class, 'despesasResumo']);
            Route::get('/fechamento-cliente/apontamentos-geral',                                    [\App\Http\Controllers\FechamentoClienteController::class, 'apontamentosGeral']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/contratos',                   [\App\Http\Controllers\FechamentoClienteController::class, 'contratos']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/por-tipo',                    [\App\Http\Controllers\FechamentoClienteController::class, 'porTipo']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/apontamentos',               [\App\Http\Controllers\FechamentoClienteController::class, 'apontamentos']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/report-html',                 [\App\Http\Controllers\FechamentoClienteController::class, 'reportHtml']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/despesas',                    [\App\Http\Controllers\FechamentoClienteController::class, 'despesas']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/pendencias',                  [\App\Http\Controllers\FechamentoClienteController::class, 'pendencias']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/pagamento',                   [\App\Http\Controllers\FechamentoClienteController::class, 'pagamento']);
            Route::post('/fechamento-cliente/{customerId}/{yearMonth}/enviar-email',               [\App\Http\Controllers\FechamentoClienteController::class, 'enviarEmail']);
            Route::post('/fechamento-cliente/{customerId}/{yearMonth}/limpar-envio',               [\App\Http\Controllers\FechamentoClienteController::class, 'limparEnvio']);
            Route::post('/fechamento-cliente/{customerId}/{yearMonth}/desconto',                   [\App\Http\Controllers\FechamentoClienteController::class, 'salvarDesconto']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/excel',                       [\App\Http\Controllers\FechamentoClienteController::class, 'excel']);
            Route::post('/fechamento-cliente/{customerId}/{yearMonth}/email-preview',              [\App\Http\Controllers\FechamentoClienteController::class, 'emailPreview']);
            Route::post('/fechamento-cliente/{customerId}/fechamento-email',                       [\App\Http\Controllers\FechamentoClienteController::class, 'saveFechamentoEmail']);
        });

        // ⏱️ FECHAMENTO DE HORAS EXCEDENTES (BH Mensal / BH Fixo)
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/fechamento-excedente',                                        [\App\Http\Controllers\FechamentoExcedenteController::class, 'index']);
            Route::get('/fechamento-excedente/{customerId}/{yearMonth}/report-html',    [\App\Http\Controllers\FechamentoExcedenteController::class, 'reportHtml']);
            Route::post('/fechamento-excedente/{customerId}/{yearMonth}/email',         [\App\Http\Controllers\FechamentoExcedenteController::class, 'enviarEmail']);
            Route::patch('/fechamento-excedente/{project}/flag',                        [\App\Http\Controllers\FechamentoExcedenteController::class, 'toggleFlag']);
            Route::post('/fechamento-excedente/{project}/{yearMonth}',                  [\App\Http\Controllers\FechamentoExcedenteController::class, 'salvar']);
        });

        // 🤝 FECHAMENTO PARCEIRO
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/fechamento-parceiro',                                                     [\App\Http\Controllers\FechamentoParceiroController::class, 'index']);
            Route::get('/fechamento-parceiro/{partnerId}/{yearMonth}/consultores',                 [\App\Http\Controllers\FechamentoParceiroController::class, 'consultores']);
            Route::get('/fechamento-parceiro/{partnerId}/{yearMonth}/despesas',                    [\App\Http\Controllers\FechamentoParceiroController::class, 'despesas']);
            Route::get('/fechamento-parceiro/{partnerId}/{yearMonth}/apontamentos',               [\App\Http\Controllers\FechamentoParceiroController::class, 'apontamentos']);
            Route::get('/fechamento-parceiro/{partnerId}/{yearMonth}/report-html',                [\App\Http\Controllers\FechamentoParceiroController::class, 'reportHtml']);
            Route::post('/fechamento-parceiro/{partnerId}/{yearMonth}/enviar-email',               [\App\Http\Controllers\FechamentoParceiroController::class, 'enviarEmail']);
            Route::post('/fechamento-parceiro/{partnerId}/{yearMonth}/limpar-envio',               [\App\Http\Controllers\FechamentoParceiroController::class, 'limparEnvio']);
            Route::get('/fechamento-parceiro/{partnerId}/{yearMonth}/excel',                       [\App\Http\Controllers\FechamentoParceiroController::class, 'excel']);
            Route::post('/fechamento-parceiro/{partnerId}/{yearMonth}/email-preview',              [\App\Http\Controllers\FechamentoParceiroController::class, 'emailPreview']);
            Route::post('/fechamento-parceiro/{partnerId}/fechamento-email',                       [\App\Http\Controllers\FechamentoParceiroController::class, 'saveFechamentoEmail']);
            // Ajustes do recebimento (desconto/adiantamento/adicional) do parceiro no mês.
            Route::post('/fechamento-parceiro/{partnerId}/{yearMonth}/ajustes',                     [\App\Http\Controllers\FechamentoParceiroController::class, 'salvarAjustes']);
        });

        // 👤 FECHAMENTO CONSULTOR
        Route::middleware('auth:sanctum')->group(function () {
            // 💰 Relatórios novos (pagamentos consultores+parceiros; rentabilidade consultor×projeto)
            Route::get('/relatorios/pagamentos/{yearMonth}',                             [\App\Http\Controllers\RelatorioPagamentoController::class, 'pagamentos']);
            Route::get('/relatorios/rentabilidade/clientes/{yearMonth}',                 [\App\Http\Controllers\RelatorioRentabilidadeController::class, 'clientes']);
            // Ajustes iniciais (custo/receita) por cliente × ano — antes do catch-all {yearMonth}.
            Route::get('/relatorios/rentabilidade/initials/{year}',                      [\App\Http\Controllers\RelatorioRentabilidadeController::class, 'initials']);
            Route::put('/relatorios/rentabilidade/initials',                             [\App\Http\Controllers\RelatorioRentabilidadeController::class, 'saveInitial']);
            // Drill-down: títulos do Keruak que compõem o "Valor Recebido" — antes do catch-all {yearMonth}.
            Route::get('/relatorios/rentabilidade/keruak-titulos',                       [\App\Http\Controllers\RelatorioRentabilidadeController::class, 'keruakTitulos']);
            Route::get('/relatorios/atividade-clientes',                                  [\App\Http\Controllers\RelatorioRentabilidadeController::class, 'atividadeClientes']);
            Route::get('/relatorios/atividade-clientes/config',                           [\App\Http\Controllers\RelatorioRentabilidadeController::class, 'statusClientesConfig']);
            Route::put('/relatorios/atividade-clientes/config',                           [\App\Http\Controllers\RelatorioRentabilidadeController::class, 'statusClientesConfigUpdate']);
            Route::get('/relatorios/rentabilidade/{yearMonth}',                          [\App\Http\Controllers\RelatorioRentabilidadeController::class, 'rentabilidade']);
            Route::get('/fechamento-consultor/{yearMonth}',                              [\App\Http\Controllers\FechamentoConsultorController::class, 'index']);
            Route::get('/fechamento-consultor/{yearMonth}/export-excel',                 [\App\Http\Controllers\FechamentoConsultorController::class, 'exportExcel']);

            // 📎 Notas fiscais PJ (NFS-e + Nota de débito) — consultor PJ avulso ou parceiro PJ.
            Route::get('/fechamento/notas/{type}/{id}/{yearMonth}',                 [\App\Http\Controllers\FechamentoNotaController::class, 'show']);
            Route::post('/fechamento/notas/{type}/{id}/{yearMonth}',                [\App\Http\Controllers\FechamentoNotaController::class, 'upload']);
            Route::get('/fechamento/notas/{type}/{id}/{yearMonth}/{tipo}/download',  [\App\Http\Controllers\FechamentoNotaController::class, 'download']);
            Route::post('/fechamento/notas/{type}/{id}/{yearMonth}/{tipo}/decisao',  [\App\Http\Controllers\FechamentoNotaController::class, 'decisao']);
            // Admin libera o envio de notas após o prazo (dia 15) para um notable+mês.
            Route::post('/fechamento/notas/{type}/{id}/{yearMonth}/liberar',         [\App\Http\Controllers\FechamentoNotaController::class, 'liberar']);

            // Folha Cooperativa (planilha de importação)
            Route::get('/fechamento-folha/{yearMonth}',          [\App\Http\Controllers\FolhaPagamentoController::class, 'grid']);
            Route::post('/fechamento-folha/{yearMonth}',         [\App\Http\Controllers\FolhaPagamentoController::class, 'save']);
            Route::get('/fechamento-folha/{yearMonth}/export',   [\App\Http\Controllers\FolhaPagamentoController::class, 'export']);
            Route::delete('/fechamento-folha/{yearMonth}/manual/{socioKey}', [\App\Http\Controllers\FolhaPagamentoController::class, 'deleteRow']);
            Route::post('/fechamento-folha/{yearMonth}/cancel',  [\App\Http\Controllers\FolhaPagamentoController::class, 'cancelRow']);
            Route::post('/fechamento-folha/{yearMonth}/import-bizify', [\App\Http\Controllers\FolhaPagamentoController::class, 'importBizify']);

            Route::get('/fechamento-consultor/{userId}/{yearMonth}/apontamentos',        [\App\Http\Controllers\FechamentoConsultorController::class, 'apontamentos']);
            Route::get('/fechamento-consultor/{userId}/{yearMonth}/report-html',         [\App\Http\Controllers\FechamentoConsultorController::class, 'reportHtml']);
            Route::get('/fechamento-consultor/{userId}/{yearMonth}/despesas',            [\App\Http\Controllers\FechamentoConsultorController::class, 'despesas']);
            Route::get('/fechamento-consultor/{userId}/{yearMonth}/banco-horas',         [\App\Http\Controllers\FechamentoConsultorController::class, 'bancoHoras']);
            Route::post('/fechamento-consultor/{userId}/{yearMonth}/enviar-email',       [\App\Http\Controllers\FechamentoConsultorController::class, 'enviarEmail']);
            Route::post('/fechamento-consultor/{userId}/{yearMonth}/limpar-envio',       [\App\Http\Controllers\FechamentoConsultorController::class, 'limparEnvio']);
            Route::get('/fechamento-consultor/{userId}/{yearMonth}/excel',               [\App\Http\Controllers\FechamentoConsultorController::class, 'excel']);
            // Ajustes do recebimento (desconto/adiantamento/adicional) do consultor no mês.
            Route::post('/fechamento-consultor/{userId}/{yearMonth}/ajustes',            [\App\Http\Controllers\FechamentoConsultorController::class, 'salvarAjustes']);

            // ── Rotina de Adiantamento (consultor/parceiro), parcelado por competência ──
            Route::get('/adiantamentos',                 [\App\Http\Controllers\AdiantamentoController::class, 'index']);
            Route::get('/adiantamentos/beneficiarios',   [\App\Http\Controllers\AdiantamentoController::class, 'beneficiarios']);
            Route::post('/adiantamentos',                [\App\Http\Controllers\AdiantamentoController::class, 'store']);
            Route::put('/adiantamentos/{id}',            [\App\Http\Controllers\AdiantamentoController::class, 'update']);
            Route::delete('/adiantamentos/{id}',         [\App\Http\Controllers\AdiantamentoController::class, 'destroy']);

            // ── Fechamento Diretoria (por diretor + competência, com status) ──
            Route::get('/fechamento-diretoria/diretores', [\App\Http\Controllers\FechamentoDiretoriaController::class, 'diretores']);
            Route::get('/fechamento-diretoria/usuarios',  [\App\Http\Controllers\FechamentoDiretoriaController::class, 'usuarios']);
            Route::post('/fechamento-diretoria/diretores', [\App\Http\Controllers\FechamentoDiretoriaController::class, 'definirDiretores']);
            Route::get('/fechamento-diretoria/folha/{userId}/{yearMonth}',       [\App\Http\Controllers\FechamentoDiretoriaController::class, 'folha']);
            Route::match(['get', 'post'], '/fechamento-diretoria/{userId}/{yearMonth}/report-html',  [\App\Http\Controllers\FechamentoDiretoriaController::class, 'reportHtml']);
            Route::get('/fechamento-diretoria/{userId}/{yearMonth}',             [\App\Http\Controllers\FechamentoDiretoriaController::class, 'show']);
            Route::post('/fechamento-diretoria/{userId}/{yearMonth}',            [\App\Http\Controllers\FechamentoDiretoriaController::class, 'salvar']);
            Route::post('/fechamento-diretoria/{userId}/{yearMonth}/finalizar',  [\App\Http\Controllers\FechamentoDiretoriaController::class, 'finalizar']);
            Route::post('/fechamento-diretoria/{userId}/{yearMonth}/reabrir',    [\App\Http\Controllers\FechamentoDiretoriaController::class, 'reabrir']);
            Route::post('/fechamento-diretoria/{userId}/{yearMonth}/email-preview', [\App\Http\Controllers\FechamentoDiretoriaController::class, 'emailPreview']);
            Route::post('/fechamento-diretoria/{userId}/{yearMonth}/enviar-email', [\App\Http\Controllers\FechamentoDiretoriaController::class, 'enviarEmail']);
            // Recebimento do próprio usuário (meu-painel / partner-dashboard).
            Route::get('/my-closing/{yearMonth}',                                        [\App\Http\Controllers\FechamentoConsultorController::class, 'myClosing']);
            Route::post('/fechamento-consultor/{userId}/{yearMonth}/email-preview',      [\App\Http\Controllers\FechamentoConsultorController::class, 'emailPreview']);
        });

        // 📅 FERIADOS
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/holidays', [HolidayController::class, 'index']);
            Route::post('/holidays/import', [HolidayController::class, 'importFromApi']);
            Route::post('/holidays', [HolidayController::class, 'store']);
            Route::put('/holidays/{holiday}', [HolidayController::class, 'update']);
            Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);
        });

        // 💬 MENSAGENS DE PROJETO
        Route::get('/messages/unread-count',    [ProjectMessageController::class, 'unreadCount'])->name('messages.unread-count');
        Route::get('/messages/unread-projects', [ProjectMessageController::class, 'unreadProjects'])->name('messages.unread-projects');
        Route::get('/messages/notifications',   [ProjectMessageController::class, 'notifications'])->name('messages.notifications');
        Route::get('/messages/mentionable-users', [ProjectMessageController::class, 'mentionableUsers'])->name('messages.mentionable-users');
        Route::get('/projects/{project}/messages',           [ProjectMessageController::class, 'index'])->name('project-messages.index');
        Route::post('/projects/{project}/messages',          [ProjectMessageController::class, 'store'])->name('project-messages.store');
        Route::patch('/projects/{project}/messages/{message}', [ProjectMessageController::class, 'update'])->name('project-messages.update');
        Route::post('/projects/{project}/messages/mark-read', [ProjectMessageController::class, 'markRead'])->name('project-messages.mark-read');
        // Participantes CONVIDADOS do Diário (libera usuário específico a ver/postar).
        Route::get('/projects/{project}/messages/participants',            [ProjectMessageController::class, 'participants'])->name('project-messages.participants');
        Route::get('/projects/{project}/messages/eligible-participants',    [ProjectMessageController::class, 'eligibleParticipants'])->name('project-messages.eligible-participants');
        Route::post('/projects/{project}/messages/participants',           [ProjectMessageController::class, 'addParticipant'])->name('project-messages.participants.add');
        Route::delete('/projects/{project}/messages/participants/{userId}',[ProjectMessageController::class, 'removeParticipant'])->name('project-messages.participants.remove');
        Route::get('/messages/{message}/attachments/{attachment}/download', [ProjectMessageController::class, 'downloadAttachment'])->name('project-messages.attachment-download');

        // 👤 CONTATOS DE CLIENTES
        Route::get('/customer-contacts',                           [CustomerContactController::class, 'index'])->name('customer-contacts.index');
        Route::post('/customer-contacts',                          [CustomerContactController::class, 'store'])->name('customer-contacts.store');
        Route::put('/customer-contacts/{customerContact}',         [CustomerContactController::class, 'update'])->name('customer-contacts.update');
        Route::delete('/customer-contacts/{customerContact}',      [CustomerContactController::class, 'destroy'])->name('customer-contacts.destroy');

        // 👤 CONTATOS DE PROJETOS
        Route::get('/projects/{project}/contacts',  [ProjectContactController::class, 'index'])->name('project-contacts.index');
        Route::put('/projects/{project}/contacts',  [ProjectContactController::class, 'sync'])->name('project-contacts.sync');

        // 📎 ANEXOS DE PROJETOS
        Route::get('/projects/{project}/attachments',                    [ProjectController::class, 'listAttachments'])->name('project-attachments.index');
        Route::post('/projects/{project}/attachments',                   [ProjectController::class, 'uploadAttachment'])->name('project-attachments.upload');
        Route::get('/projects/{project}/attachments/{attachment}',       [ProjectController::class, 'downloadAttachment'])->name('project-attachments.download');
        Route::delete('/projects/{project}/attachments/{attachment}',    [ProjectController::class, 'deleteAttachment'])->name('project-attachments.delete');
        Route::put('/projects/{project}/consultants/{userId}/manual-timesheet', [ProjectController::class, 'toggleConsultantManualTimesheet'])->name('projects.consultant-manual-timesheet');
        Route::get('/projects/{project}/open-periods',  [ProjectController::class, 'listOpenPeriods'])->name('projects.open-periods.index');
        Route::post('/projects/{project}/open-period',  [ProjectController::class, 'openPeriod'])->name('projects.open-periods.open');
        Route::post('/projects/{project}/close-periods',[ProjectController::class, 'closePeriods'])->name('projects.open-periods.close');
        // Visão global (Configurações): todos os períodos abertos + fechar em lote.
        Route::get('/projects-open-periods',            [ProjectController::class, 'allOpenPeriods'])->name('projects.open-periods.all');
        Route::post('/projects-open-periods/close-all', [ProjectController::class, 'closeAllOpenPeriods'])->name('projects.open-periods.close-all');
        Route::post('/projects-open-periods/{period}/close', [ProjectController::class, 'closeOnePeriod'])->name('projects.open-periods.close-one');

        // 📄 CONTRATOS
        Route::get('/contracts/kanban',                              [ContractController::class, 'kanban'])->name('contracts.kanban');
        Route::get('/contracts/coordinators',                        [ContractController::class, 'coordinators'])->name('contracts.coordinators');
        Route::patch('/contracts/{contract}/helpdesk-integration',   [ContractController::class, 'toggleHelpdeskIntegration'])->name('contracts.helpdesk-integration');
        Route::patch('/contracts/{contract}/kanban-move',            [ContractController::class, 'kanbanMove'])->name('contracts.kanban-move');
        Route::patch('/contracts/{contract}/sustentacao-move',       [ContractController::class, 'sustentacaoMove'])->name('contracts.sustentacao-move');
        Route::patch('/projects/{project}/kanban-move',              [ContractController::class, 'projectMove'])->name('projects.kanban-move');
        Route::get('/contracts/{contract}/kanban-logs',              [\App\Http\Controllers\KanbanLogController::class, 'contractLogs'])->name('contracts.kanban-logs');
        Route::get('/contracts/{contract}/events',                    [ContractController::class, 'events'])->name('contracts.events');
        Route::post('/contracts/{contract}/gerar-documento',          [ContractController::class, 'gerarDocumento'])->name('contracts.gerar-documento');
        Route::get('/contracts/{contract}/assinatura',                [ContractController::class, 'assinatura'])->name('contracts.assinatura');
        Route::post('/contracts/{contract}/assinatura/enviar',        [ContractController::class, 'enviarAssinatura'])->name('contracts.assinatura.enviar');
        Route::post('/contracts/{contract}/checklist',                [ContractController::class, 'checklistMarcar'])->name('contracts.checklist');
        Route::post('/contracts/{contract}/liberar',                  [ContractController::class, 'liberar'])->name('contracts.liberar');
        Route::post('/contracts/{contract}/bloquear',                 [ContractController::class, 'bloquear'])->name('contracts.bloquear');
        Route::post('/contracts/{contract}/desbloquear',              [ContractController::class, 'desbloquear'])->name('contracts.desbloquear');
        Route::get('/contracts/{contract}/snapshot',                  [ContractController::class, 'snapshot'])->name('contracts.snapshot');
        Route::post('/contracts/{contract}/snapshot/replay',          [ContractController::class, 'replay'])->name('contracts.snapshot.replay');
        Route::get('/contracts/consistency-report',                   [ContractController::class, 'consistencyReport'])->name('contracts.consistency-report');
        Route::get('/contracts/recorrentes',                          [ContractController::class, 'recorrentes'])->name('contracts.recorrentes');
        Route::post('/contracts/recorrentes/import',                  [ContractController::class, 'importAniversario'])->name('contracts.recorrentes-import');
        Route::patch('/contracts/{contract}/recorrente',              [ContractController::class, 'updateRecorrente'])->name('contracts.recorrente-update');
        // Reajuste sob demanda (índice IPCA/IGP-M via BCB → prévia → aplicação manual + histórico)
        Route::get('/economic-index',                                 [\App\Http\Controllers\EconomicIndexController::class, 'show'])->name('economic-index.show');
        Route::get('/contracts/{contract}/adjustment-preview',        [ContractController::class, 'adjustmentPreview'])->name('contracts.adjustment-preview');
        Route::post('/contracts/{contract}/apply-adjustment',         [ContractController::class, 'applyAdjustment'])->name('contracts.apply-adjustment');
        Route::post('/contracts/{contract}/renew-no-adjustment',      [ContractController::class, 'renewWithoutAdjustment'])->name('contracts.renew-no-adjustment');
        Route::post('/contracts/{contract}/notify-client-adjustment', [ContractController::class, 'notifyClientAdjustment'])->name('contracts.notify-client-adjustment');
        // Dashboard de reajustes (resumo/KPIs + lista priorizada + histórico)
        Route::get('/contracts/reajustes/summary',                    [ContractController::class, 'reajustesSummary'])->name('contracts.reajustes-summary');
        Route::get('/contracts/reajustes',                            [ContractController::class, 'reajustesList'])->name('contracts.reajustes-list');
        // Inclusão manual de reajuste (sem contrato) — só rastreio
        Route::post('/contracts/reajustes/manual',                    [ContractController::class, 'manualReajusteStore'])->name('contracts.reajustes-manual-store');
        Route::patch('/contracts/reajustes/manual/{manual}',          [ContractController::class, 'manualReajusteUpdate'])->name('contracts.reajustes-manual-update');
        Route::delete('/contracts/reajustes/manual/{manual}',         [ContractController::class, 'manualReajusteDestroy'])->name('contracts.reajustes-manual-destroy');
        // Fluxo de reajuste da inclusão manual (preview/aplicar/histórico/notificar/prévia e-mail)
        Route::get('/contracts/reajustes/manual/{manual}/adjustment-preview',        [ContractController::class, 'manualAdjustmentPreview'])->name('contracts.reajustes-manual-preview');
        Route::post('/contracts/reajustes/manual/{manual}/apply-adjustment',         [ContractController::class, 'manualApplyAdjustment'])->name('contracts.reajustes-manual-apply');
        Route::get('/contracts/reajustes/manual/{manual}/value-changes',             [ContractController::class, 'manualValueChanges'])->name('contracts.reajustes-manual-changes');
        Route::post('/contracts/reajustes/manual/{manual}/notify-client-adjustment', [ContractController::class, 'manualNotify'])->name('contracts.reajustes-manual-notify');
        Route::get('/contracts/reajustes/manual/{manual}/adjustment-email-preview',  [ContractController::class, 'manualAdjustmentEmailPreview'])->name('contracts.reajustes-manual-email-preview');
        Route::post('/contracts/reajustes/manual/{manual}/reverse-adjustment',        [ContractController::class, 'manualReverseAdjustment'])->name('contracts.reajustes-manual-reverse');
        Route::post('/contracts/reajustes/manual/{manual}/resend-adjustment',         [ContractController::class, 'manualResendAdjustment'])->name('contracts.reajustes-manual-resend');
        Route::get('/contracts/reajustes/manual/{manual}/aviso-preview',              [ContractController::class, 'manualAvisoPreview'])->name('contracts.reajustes-manual-aviso-preview');
        Route::post('/contracts/reajustes/manual/{manual}/aviso-send',                [ContractController::class, 'manualAvisoSend'])->name('contracts.reajustes-manual-aviso-send');
        // Prévia do e-mail de reajuste (contrato) + estorno + reenvio
        Route::get('/contracts/{contract}/adjustment-email-preview',  [ContractController::class, 'contractAdjustmentEmailPreview'])->name('contracts.adjustment-email-preview');
        Route::post('/contracts/{contract}/reverse-adjustment',       [ContractController::class, 'reverseAdjustment'])->name('contracts.reverse-adjustment');
        Route::post('/contracts/{contract}/resend-adjustment',        [ContractController::class, 'resendAdjustment'])->name('contracts.resend-adjustment');
        Route::get('/contracts/{contract}/aviso-preview',             [ContractController::class, 'contractAvisoPreview'])->name('contracts.aviso-preview');
        Route::post('/contracts/{contract}/aviso-send',               [ContractController::class, 'contractAvisoSend'])->name('contracts.aviso-send');
        Route::get('/contracts/{contract}/value-changes',             [ContractController::class, 'valueChanges'])->name('contracts.value-changes');
        Route::get('/projects/{project}/kanban-logs',                [\App\Http\Controllers\KanbanLogController::class, 'projectLogs'])->name('projects.kanban-logs');
        Route::get('/contract-requests/{contractRequest}/kanban-logs', [\App\Http\Controllers\KanbanLogController::class, 'requestLogs'])->name('contract-requests.kanban-logs');

        Route::prefix('contracts')->group(function () {
            Route::get('/',                                         [ContractController::class, 'index'])->name('contracts.index');
            Route::post('/',                                        [ContractController::class, 'store'])->name('contracts.store');
            // Aditivo (antes do /{contract} pra não casar 'aditivo' como id)
            Route::get('/aditivo/eligible-projects',               [ContractController::class, 'aditivoEligibleProjects'])->name('contracts.aditivo.eligible');
            Route::post('/aditivo',                                [ContractController::class, 'storeAditivo'])->name('contracts.aditivo.store');
            Route::put('/aditivo/{contract}',                      [ContractController::class, 'updateAditivo'])->name('contracts.aditivo.update');
            Route::get('/{contract}',                              [ContractController::class, 'show'])->name('contracts.show');
            Route::put('/{contract}',                              [ContractController::class, 'update'])->name('contracts.update');
            Route::delete('/{contract}',                           [ContractController::class, 'destroy'])->name('contracts.destroy');
            Route::patch('/{contract}/status',                     [ContractController::class, 'updateStatus'])->name('contracts.update-status');
            Route::post('/{contract}/generate-project',            [ContractController::class, 'generateProject'])->name('contracts.generate-project');
            Route::post('/{contract}/attachments',                 [ContractController::class, 'uploadAttachment'])->name('contracts.upload-attachment');
            Route::get('/{contract}/attachments/{attachment}',     [ContractController::class, 'downloadAttachment'])->name('contracts.download-attachment');
            Route::delete('/{contract}/attachments/{attachment}',  [ContractController::class, 'deleteAttachment'])->name('contracts.delete-attachment');
        });

        // 📋 REQUISIÇÕES DE CONTRATO (clientes enviam necessidades)
        Route::get('/contract-requests/options',              [\App\Http\Controllers\ContractRequestController::class, 'options'])->name('contract-requests.options');
        Route::post('/contract-requests/resolve-emails',      [\App\Http\Controllers\ContractRequestController::class, 'resolveEmails'])->name('contract-requests.resolve-emails');
        Route::get('/contract-requests',                      [\App\Http\Controllers\ContractRequestController::class, 'index'])->name('contract-requests.index');
        Route::post('/contract-requests',                     [\App\Http\Controllers\ContractRequestController::class, 'store'])->name('contract-requests.store');
        Route::post('/contract-requests/resolve-emails',      [\App\Http\Controllers\ContractRequestController::class, 'resolveEmails'])->name('contract-requests.resolve-emails');
        Route::get('/contract-requests/{contractRequest}',    [\App\Http\Controllers\ContractRequestController::class, 'show'])->name('contract-requests.show');
        Route::patch('/contract-requests/{contractRequest}/review', [\App\Http\Controllers\ContractRequestController::class, 'review'])->name('contract-requests.review');
        Route::patch('/contract-requests/{contractRequest}/kanban-move', [\App\Http\Controllers\ContractController::class, 'requestKanbanMove'])->name('contract-requests.kanban-move');
        Route::post('/contract-requests/{contractRequest}/plan-decision', [\App\Http\Controllers\ContractController::class, 'requestPlanDecision'])->name('contract-requests.plan-decision');
        Route::post('/contract-requests/{contractRequest}/finalize', [\App\Http\Controllers\ContractController::class, 'requestFinalize'])->name('contract-requests.finalize');
        Route::get('/contract-requests/{contractRequest}/messages',  [\App\Http\Controllers\ContractRequestMessageController::class, 'index'])->name('contract-request-messages.index');
        Route::post('/contract-requests/{contractRequest}/messages', [\App\Http\Controllers\ContractRequestMessageController::class, 'store'])->name('contract-request-messages.store');
        Route::get('/contract-requests/{contractRequest}/mentionable-users', [\App\Http\Controllers\ContractRequestMessageController::class, 'mentionableUsers'])->name('contract-request-messages.mentionable-users');
        Route::get('/req-messages/{message}/attachments/{attachment}/download', [\App\Http\Controllers\ContractRequestMessageController::class, 'downloadAttachment'])->name('contract-request-messages.attachment-download');

        // Comentários NATIVOS do projeto (canal cliente+equipe) — mesma tabela/endpoints de PROD.
        Route::get('/projects/{project}/comments',                   [\App\Http\Controllers\ProjectCommentController::class, 'index'])->name('project-comments.index');
        Route::post('/projects/{project}/comments',                  [\App\Http\Controllers\ProjectCommentController::class, 'store'])->name('project-comments.store');
        Route::get('/projects/{project}/comments/mentionable-users', [\App\Http\Controllers\ProjectCommentController::class, 'mentionableUsers'])->name('project-comments.mentionable-users');
        Route::get('/project-comments/{message}/attachments/{attachment}/download', [\App\Http\Controllers\ProjectCommentController::class, 'downloadAttachment'])->name('project-comments.attachment-download');

        // 💬 CHAT DE CONTRATOS
        Route::get('/contracts/{contract}/messages',              [\App\Http\Controllers\ContractMessageController::class, 'index'])->name('contract-messages.index');
        Route::post('/contracts/{contract}/messages',             [\App\Http\Controllers\ContractMessageController::class, 'store'])->name('contract-messages.store');
        Route::post('/contracts/{contract}/messages/mark-read',   [\App\Http\Controllers\ContractMessageController::class, 'markRead'])->name('contract-messages.mark-read');
        Route::get('/contracts/{contract}/mentionable-users',     [\App\Http\Controllers\ContractMessageController::class, 'mentionableUsers'])->name('contract-messages.mentionable-users');
        Route::get('/contract-messages/notifications',            [\App\Http\Controllers\ContractMessageController::class, 'notifications'])->name('contract-messages.notifications');
        Route::get('/contract-messages/unread-contracts',         [\App\Http\Controllers\ContractMessageController::class, 'unreadContracts'])->name('contract-messages.unread-contracts');
        Route::get('/contract-messages/{message}/attachments/{attachment}/download', [\App\Http\Controllers\ContractMessageController::class, 'downloadAttachment'])->name('contract-messages.attachment-download');

        // 🔔 SININHO DE MENÇÕES + CLIENTE (header) — Triagem
        Route::get('/me/mentions', [\App\Http\Controllers\MeController::class, 'mentions'])->name('me.mentions');
        Route::get('/me/customer', [\App\Http\Controllers\MeController::class, 'customer'])->name('me.customer');

        // 👥 ENVOLVIDOS DO CARD (chat + notificação por e-mail) — cardType: contract-requests | projects
        Route::get('/{cardType}/{cardId}/envolvidos',           [\App\Http\Controllers\CardEnvolvidoController::class, 'index'])
            ->where(['cardType' => 'contract-requests|projects', 'cardId' => '[0-9]+'])
            ->name('card-envolvidos.index');
        Route::post('/{cardType}/{cardId}/envolvidos',          [\App\Http\Controllers\CardEnvolvidoController::class, 'store'])
            ->where(['cardType' => 'contract-requests|projects', 'cardId' => '[0-9]+'])
            ->name('card-envolvidos.store');
        Route::delete('/{cardType}/{cardId}/envolvidos/{id}',   [\App\Http\Controllers\CardEnvolvidoController::class, 'destroy'])
            ->where(['cardType' => 'contract-requests|projects', 'cardId' => '[0-9]+', 'id' => '[0-9]+'])
            ->name('card-envolvidos.destroy');
        Route::get('/{cardType}/{cardId}/mention-candidates',   [\App\Http\Controllers\CardEnvolvidoController::class, 'mentionCandidates'])
            ->where(['cardType' => 'contract-requests|projects', 'cardId' => '[0-9]+'])
            ->name('card-envolvidos.mention-candidates');

        // 🛡️ PORTAL DE SUSTENTAÇÃO - Admins e coordenadores do tipo "sustentacao"
        Route::prefix('sustentacao')->group(function () {
            Route::get('/kpis',         [SustentacaoController::class, 'kpis'])->name('sustentacao.kpis');
            Route::get('/queue',        [SustentacaoController::class, 'queue'])->name('sustentacao.queue');
            Route::get('/sla',          [SustentacaoController::class, 'sla'])->name('sustentacao.sla');
            Route::get('/productivity', [SustentacaoController::class, 'productivity'])->name('sustentacao.productivity');
            Route::get('/financial',    [SustentacaoController::class, 'financial'])->name('sustentacao.financial');
            Route::get('/clients',      [SustentacaoController::class, 'clients'])->name('sustentacao.clients');
            Route::get('/distribution', [SustentacaoController::class, 'distribution'])->name('sustentacao.distribution');
            Route::get('/evolution',       [SustentacaoController::class, 'evolution'])->name('sustentacao.evolution');
            Route::get('/context-stats',       [SustentacaoController::class, 'contextStats'])->name('sustentacao.context-stats');
            Route::get('/filter-options',      [SustentacaoController::class, 'filterOptions'])->name('sustentacao.filter-options');
            Route::get('/executive',           [SustentacaoController::class, 'executive'])->name('sustentacao.executive');
            Route::get('/debug-clientes',      [SustentacaoController::class, 'debugClientes'])->name('sustentacao.debug-clientes');
            Route::get('/debug-responsaveis',  [SustentacaoController::class, 'debugResponsaveis'])->name('sustentacao.debug-responsaveis');
            Route::get('/debug-project-map',   [SustentacaoController::class, 'debugProjectMap'])->name('sustentacao.debug-project-map');
            Route::post('/sync-orgs',          [SustentacaoController::class, 'syncOrgs'])->name('sustentacao.sync-orgs');
            Route::post('/sync-agents',        [SustentacaoController::class, 'syncAgents'])->name('sustentacao.sync-agents');
            // Rotinas embarcadas no portal — filtradas por service_type Sustentação
            Route::get('/timesheets',          [SustentacaoController::class, 'timesheets'])->name('sustentacao.timesheets');
            Route::get('/expenses',            [SustentacaoController::class, 'expenses'])->name('sustentacao.expenses');
            Route::get('/approvals',           [SustentacaoController::class, 'approvals'])->name('sustentacao.approvals');
        });

        // ⚙️ CONFIGURAÇÕES DO SISTEMA - Protegido por permissões específicas (Admins sempre têm acesso)
        Route::middleware('permission.or.admin:system_settings.view')->group(function () {
            Route::get('/system-settings', [SystemSettingController::class, 'index'])->name('system-settings.index');
            Route::get('/system-settings/{key}', [SystemSettingController::class, 'show'])->name('system-settings.show');
        });

        Route::middleware('permission.or.admin:system_settings.update')->group(function () {
            Route::put('/system-settings', [SystemSettingController::class, 'update'])->name('system-settings.update');
        });

        // 🔗 MOVIDESK ADMIN - Sync manual e status da integração (somente admins)
        Route::middleware('permission.or.admin:system_settings.view')->group(function () {
            Route::get('/movidesk/status', [\App\Http\Controllers\MovideskAdminController::class, 'status'])->name('movidesk.status');
            Route::get('/movidesk/problem-tickets', [\App\Http\Controllers\MovideskAdminController::class, 'problemTickets'])->name('movidesk.problem_tickets.index');
        });

        Route::middleware('permission.or.admin:system_settings.update')->group(function () {
            Route::post("/movidesk/sync", [\App\Http\Controllers\MovideskAdminController::class, "sync"])->name("movidesk.sync");
            Route::post("/movidesk/history-import", [\App\Http\Controllers\MovideskAdminController::class, "historyImport"])->name("movidesk.history_import");

            // Diagnóstico e vinculação manual de orgs Movidesk — antes públicas, agora protegidas
            Route::get('/movidesk/debug',             [\App\Http\Controllers\MovideskAdminController::class, 'debug'])->name('movidesk.debug');
            Route::get('/movidesk/debug-orgs',        [\App\Http\Controllers\MovideskAdminController::class, 'debugOrgs'])->name('movidesk.debug.orgs');
            Route::post('/movidesk/link-org',         [\App\Http\Controllers\MovideskAdminController::class, 'linkOrg'])->name('movidesk.link.org');
            Route::post('/movidesk/link-org-project', [\App\Http\Controllers\MovideskAdminController::class, 'linkOrgProject'])->name('movidesk.link.org.project');

            // Slow-lane: tickets que travaram no fetchTicket principal
            Route::post('/movidesk/problem-tickets/{id}/retry', [\App\Http\Controllers\MovideskAdminController::class, 'problemTicketRetry'])->name('movidesk.problem_tickets.retry');
            Route::delete('/movidesk/problem-tickets/{id}',     [\App\Http\Controllers\MovideskAdminController::class, 'problemTicketDrop'])->name('movidesk.problem_tickets.drop');
        });

        // 🧠 MATRIZ DE CONHECIMENTO — Skills + Consultant Skills
        // Auth simples (sanctum); permissões granulares ficam pra evolução conforme o módulo amadurece.
        Route::get('/skills',                          [SkillController::class, 'index'])->name('skills.index');
        Route::post('/skills',                         [SkillController::class, 'store'])->name('skills.store');
        Route::get('/skills/{id}/holders',             [SkillController::class, 'holders'])->name('skills.holders');
        Route::get('/consultants/{id}/skills',         [ConsultantSkillController::class, 'indexByConsultant'])->name('consultants.skills.index');
        Route::post('/consultants/{id}/skills',        [ConsultantSkillController::class, 'storeForConsultant'])->name('consultants.skills.store');
        Route::put('/consultant-skills/{id}',          [ConsultantSkillController::class, 'update'])->name('consultant-skills.update');
        Route::get('/consultants/{id}/profile',        [ConsultantSkillController::class, 'showProfile'])->name('consultants.profile.show');
        Route::patch('/consultants/{id}/profile',      [ConsultantSkillController::class, 'updateProfile'])->name('consultants.profile.update');

        // 🧠 BANCO DE COMPETÊNCIAS — Pesquisas (motor + Form Interno). Uma matriz única, versionada.
        // Gestão: criar pesquisa, enviar convites, acompanhar (admin/administrativo).
        Route::middleware('permission.or.admin:competencias.manage')->group(function () {
            Route::get('/competencias/meta',                    [SkillSurveyController::class, 'meta']);
            Route::get('/competencias/surveys',                 [SkillSurveyController::class, 'index']);
            Route::post('/competencias/surveys',                [SkillSurveyController::class, 'store']);
            Route::get('/competencias/surveys/{id}',            [SkillSurveyController::class, 'show'])->whereNumber('id');
            Route::put('/competencias/surveys/{id}',            [SkillSurveyController::class, 'update'])->whereNumber('id');
            Route::post('/competencias/surveys/{id}/invites',   [SkillSurveyController::class, 'storeInvites'])->whereNumber('id');
            Route::get('/competencias/surveys/{id}/invites',    [SkillSurveyController::class, 'invites'])->whereNumber('id');
            Route::post('/competencias/invites/{id}/reminder',  [SkillSurveyController::class, 'reminder'])->whereNumber('id');
            // Matriz — escrita (competências + publicar versão)
            Route::post('/competencias/matriz/skills',          [SkillMatrixVersionController::class, 'storeSkill']);
            Route::put('/competencias/matriz/skills/{id}',      [SkillMatrixVersionController::class, 'updateSkill'])->whereNumber('id');
            Route::delete('/competencias/matriz/skills/{id}',   [SkillMatrixVersionController::class, 'destroySkill'])->whereNumber('id');
            Route::post('/competencias/matriz/versions/publish',[SkillMatrixVersionController::class, 'publish']);
            Route::delete('/competencias/matriz/versions/{id}', [SkillMatrixVersionController::class, 'destroyVersion'])->whereNumber('id');
            Route::put('/competencias/profissionais/classification/bulk', [SkillProfileController::class, 'bulkClassification']);
            Route::put('/competencias/profissionais/{id}/classification', [SkillProfileController::class, 'updateClassification'])->whereNumber('id');
            Route::put('/competencias/profissionais/{id}/valor',          [SkillProfileController::class, 'updateValor'])->whereNumber('id');
            Route::delete('/competencias/profissionais/bulk',  [SkillProfileController::class, 'bulkDestroy']);
            Route::delete('/competencias/profissionais/{id}',  [SkillProfileController::class, 'destroy'])->whereNumber('id');
            // Kanban de Contratação/Onboarding
            Route::get('/competencias/contratacao',                 [SkillHireController::class, 'index']);
            Route::post('/competencias/contratacao/hire',           [SkillHireController::class, 'hire']);
            Route::get('/competencias/contratacao/{id}',            [SkillHireController::class, 'show'])->whereNumber('id');
            Route::put('/competencias/contratacao/{id}',            [SkillHireController::class, 'update'])->whereNumber('id');
            Route::post('/competencias/contratacao/{id}/move',      [SkillHireController::class, 'move'])->whereNumber('id');
            Route::post('/competencias/contratacao/{id}/complete',  [SkillHireController::class, 'complete'])->whereNumber('id');
            Route::put('/competencias/matriz/categories',       [SkillMatrixVersionController::class, 'renameCategory']);
            Route::delete('/competencias/matriz/categories/{name}', [SkillMatrixVersionController::class, 'destroyCategory'])->where('name', '.*');
            // Configuração de Formulários (campos cadastrais por tipo)
            Route::get('/competencias/form-configs',            [SkillFormConfigController::class, 'index']);
            Route::put('/competencias/form-configs/{type}',     [SkillFormConfigController::class, 'update']);
            Route::post('/competencias/form-configs/{type}/reset', [SkillFormConfigController::class, 'reset']);
        });
        // Leitura de indicadores / perfis / matriz (admin/administrativo/coordenador)
        Route::middleware('permission.or.admin:competencias.view')->group(function () {
            Route::get('/competencias/dashboard',                 [SkillDashboardController::class, 'summary']);
            Route::get('/competencias/profissionais',             [SkillProfileController::class, 'index']);
            Route::get('/competencias/profissionais/{id}',        [SkillProfileController::class, 'show'])->whereNumber('id');
            Route::get('/competencias/matriz/versions',           [SkillMatrixVersionController::class, 'versions']);
            Route::get('/competencias/matriz/skills',             [SkillMatrixVersionController::class, 'skills']);
        });
        // Responder a própria pesquisa (colaborador logado). Posse verificada na controller.
        Route::middleware('permission.or.admin:competencias.respond')->group(function () {
            Route::get('/competencias/minhas-pesquisas',                      [SkillSubmissionController::class, 'mine']);
            Route::get('/competencias/meu-historico',                         [SkillSubmissionController::class, 'history']);
            Route::get('/competencias/surveys/{surveyId}/responder',          [SkillSubmissionController::class, 'open'])->whereNumber('surveyId');
            Route::patch('/competencias/submissions/{submissionId}/autosave', [SkillSubmissionController::class, 'autosave'])->whereNumber('submissionId');
            Route::get('/competencias/submissions/{submissionId}/review',     [SkillSubmissionController::class, 'review'])->whereNumber('submissionId');
            Route::post('/competencias/submissions/{submissionId}/submit',    [SkillSubmissionController::class, 'submit'])->whereNumber('submissionId');
        });

        // 📋 KANBAN DE CANDIDATOS
        Route::get('/candidates',                      [CandidateController::class, 'index'])->name('candidates.index');
        Route::get('/candidates/triage-queue',         [CandidateController::class, 'triageQueue'])->name('candidates.triage-queue');

        // 🔍 BUSCA GLOBAL
        Route::get('/search',                          [SearchController::class, 'search'])->name('search.global');
        Route::get('/search/advanced',                 [SearchController::class, 'advanced'])->name('search.advanced');
        Route::patch('/candidates/{id}',               [CandidateController::class, 'update'])->name('candidates.update');
        Route::patch('/candidates/{id}/status',        [CandidateController::class, 'updateStatus'])->name('candidates.status.update');

        // 🎯 GAPS — Detecção de lacunas de skills (critical e por projeto)
        Route::get('/consultants/{id}/gaps',          [GapController::class, 'consultantGaps'])->name('consultants.gaps');
        Route::get('/projects/{id}/gaps',             [GapController::class, 'projectGaps'])->name('projects.gaps');
        Route::post('/critical-skills',               [GapController::class, 'storeCriticalSkill'])->name('critical-skills.store');
        Route::post('/projects/{id}/required-skills', [GapController::class, 'storeProjectRequiredSkill'])->name('projects.required-skills.store');
        Route::get('/projects/{id}/recommendations',  [GapController::class, 'recommendations'])->name('projects.recommendations');
        Route::get('/projects/{id}/candidate-match',  [GapController::class, 'candidateMatch'])->name('projects.candidate-match');
        Route::post('/projects/{id}/allocate',        [GapController::class, 'allocate'])->name('projects.allocate');
        Route::get('/projects/{id}/team-recommendation', [GapController::class, 'teamRecommendation'])->name('projects.team-recommendation');
        Route::post('/projects/{id}/allocate-team',    [GapController::class, 'allocateTeam'])->name('projects.allocate-team');

        // 📡 OPERATIONAL FEED — Timeline operacional (eventos, IA, alertas, riscos)
        Route::prefix('operational-feed')->group(function () {
            Route::get('/',      [\App\Http\Controllers\OperationalFeedController::class, 'index'])->name('operational-feed.index');
            Route::get('/{id}',  [\App\Http\Controllers\OperationalFeedController::class, 'show'])->name('operational-feed.show');
            Route::post('/',     [\App\Http\Controllers\OperationalFeedController::class, 'store'])->name('operational-feed.store');
            Route::delete('/{id}', [\App\Http\Controllers\OperationalFeedController::class, 'destroy'])->name('operational-feed.destroy');
        });

        // 📬 INBOX — Conversations + Messages do BOT Minutor
        Route::prefix('inbox')->group(function () {
            Route::get('/conversations',                 [\App\Http\Controllers\InboxController::class, 'conversations'])->name('inbox.conversations');
            Route::get('/conversations/{id}',            [\App\Http\Controllers\InboxController::class, 'show'])->name('inbox.show');
            Route::get('/conversations/{id}/messages',   [\App\Http\Controllers\InboxController::class, 'messages'])->name('inbox.messages');
            Route::post('/conversations/{id}/messages',  [\App\Http\Controllers\InboxController::class, 'send'])->name('inbox.send');
            Route::post('/conversations/{id}/read',      [\App\Http\Controllers\InboxController::class, 'markRead'])->name('inbox.read');
            Route::patch('/messages/{id}/status',        [\App\Http\Controllers\InboxController::class, 'updateMessageStatus'])->name('inbox.message.status');
            Route::patch('/messages/{id}',               [\App\Http\Controllers\InboxController::class, 'updateMessage'])->name('inbox.message.update');
            Route::delete('/messages/{id}',              [\App\Http\Controllers\InboxController::class, 'destroyMessage'])->name('inbox.message.destroy');
            Route::post('/messages/{id}/reactions',      [\App\Http\Controllers\InboxController::class, 'toggleReaction'])->name('inbox.message.reaction');
            Route::post('/messages/{id}/pin',            [\App\Http\Controllers\InboxController::class, 'togglePin'])->name('inbox.message.pin');
            Route::get('/conversations/{id}/pinned',     [\App\Http\Controllers\InboxController::class, 'pinnedMessages'])->name('inbox.conversation.pinned');
            Route::get('/conversations/{id}/export',     [\App\Http\Controllers\InboxController::class, 'exportConversation'])->name('inbox.conversation.export');
            Route::post('/conversations/{id}/typing',    [\App\Http\Controllers\InboxController::class, 'setTyping'])->name('inbox.conversation.typing.set');
            Route::get('/conversations/{id}/typing',     [\App\Http\Controllers\InboxController::class, 'listTyping'])->name('inbox.conversation.typing.list');
            Route::post('/conversations/{id}/mute',      [\App\Http\Controllers\InboxController::class, 'muteConversation'])->name('inbox.conversation.mute');
            Route::get('/conversations/{id}/read-status',[\App\Http\Controllers\InboxController::class, 'readStatus'])->name('inbox.conversation.readstatus');
            Route::post('/messages/{id}/favorite',       [\App\Http\Controllers\InboxController::class, 'toggleFavorite'])->name('inbox.message.favorite');
            Route::get('/favorites',                     [\App\Http\Controllers\InboxController::class, 'listFavorites'])->name('inbox.favorites');
            Route::get('/search',                        [\App\Http\Controllers\InboxController::class, 'searchMessages'])->name('inbox.search');
            Route::get('/unread-summary',                [\App\Http\Controllers\InboxController::class, 'unreadSummary'])->name('inbox.unread');
        });

        // 👤 PRESENCE — status online/away/offline
        Route::prefix('presence')->group(function () {
            Route::post('/heartbeat', [\App\Http\Controllers\PresenceController::class, 'heartbeat'])->name('presence.heartbeat');
            Route::get('/',           [\App\Http\Controllers\PresenceController::class, 'index'])->name('presence.index');
            Route::get('/online',     [\App\Http\Controllers\PresenceController::class, 'online'])->name('presence.online');
        });

        // 💬 CONVERSATIONS — criar DM/grupo, gerenciar participantes, listar usuários para abrir chat
        Route::prefix('conversations')->group(function () {
            Route::get('/users',                                  [\App\Http\Controllers\ConversationController::class, 'usersForChat'])->name('conversations.users');
            Route::post('/',                                      [\App\Http\Controllers\ConversationController::class, 'store'])->name('conversations.store');
            Route::post('/{id}/participants',                     [\App\Http\Controllers\ConversationController::class, 'addParticipant'])->name('conversations.participants.add');
            Route::delete('/{id}/participants/{userId}',          [\App\Http\Controllers\ConversationController::class, 'removeParticipant'])->name('conversations.participants.remove');
            Route::post('/{id}/bot-query',                        [\App\Http\Controllers\BotQueryController::class, 'ask'])->name('conversations.bot-query');
        });

        // 🤖 BOT MINUTOR CONFIG — providers/agents/skills/rules/general
        Route::prefix('bot')->group(function () {
            Route::get('/config',          [\App\Http\Controllers\BotConfigController::class, 'showConfig'])->name('bot.config.show');
            Route::put('/config',          [\App\Http\Controllers\BotConfigController::class, 'updateConfig'])->name('bot.config.update');
            Route::get('/providers',       [\App\Http\Controllers\BotConfigController::class, 'providers'])->name('bot.providers');
            Route::post('/providers',      [\App\Http\Controllers\BotConfigController::class, 'storeProvider'])->name('bot.providers.store');
            Route::put('/providers/{id}',  [\App\Http\Controllers\BotConfigController::class, 'updateProvider'])->name('bot.providers.update');
            Route::delete('/providers/{id}', [\App\Http\Controllers\BotConfigController::class, 'destroyProvider'])->name('bot.providers.destroy');
            Route::get('/agents',          [\App\Http\Controllers\BotConfigController::class, 'agents'])->name('bot.agents');
            Route::post('/agents',         [\App\Http\Controllers\BotConfigController::class, 'storeAgent'])->name('bot.agents.store');
            Route::put('/agents/{id}',     [\App\Http\Controllers\BotConfigController::class, 'updateAgent'])->name('bot.agents.update');
            Route::delete('/agents/{id}',  [\App\Http\Controllers\BotConfigController::class, 'destroyAgent'])->name('bot.agents.destroy');
            Route::get('/skills',          [\App\Http\Controllers\BotConfigController::class, 'skills'])->name('bot.skills');
            Route::post('/skills',         [\App\Http\Controllers\BotConfigController::class, 'storeSkill'])->name('bot.skills.store');
            Route::put('/skills/{id}',     [\App\Http\Controllers\BotConfigController::class, 'updateSkill'])->name('bot.skills.update');
            Route::delete('/skills/{id}',  [\App\Http\Controllers\BotConfigController::class, 'destroySkill'])->name('bot.skills.destroy');
            // Grupos operacionais (admin/executivo)
            Route::get('/groups',                      [\App\Http\Controllers\GroupAdminController::class, 'index'])->name('bot.groups');
            Route::get('/groups/available-users',      [\App\Http\Controllers\GroupAdminController::class, 'availableUsers'])->name('bot.groups.users');
            Route::post('/groups',                     [\App\Http\Controllers\GroupAdminController::class, 'store'])->name('bot.groups.store');
            Route::post('/groups/seed-defaults',       [\App\Http\Controllers\GroupAdminController::class, 'seedDefaults'])->name('bot.groups.seed');
            Route::get('/groups/{id}/members',         [\App\Http\Controllers\GroupAdminController::class, 'members'])->name('bot.groups.members');
            Route::patch('/groups/{id}',               [\App\Http\Controllers\GroupAdminController::class, 'rename'])->name('bot.groups.rename');
            Route::post('/groups/{id}/avatar',         [\App\Http\Controllers\GroupAdminController::class, 'uploadAvatar'])->name('bot.groups.avatar.upload');
            Route::delete('/groups/{id}/avatar',       [\App\Http\Controllers\GroupAdminController::class, 'deleteAvatar'])->name('bot.groups.avatar.delete');
            Route::delete('/groups/{id}',              [\App\Http\Controllers\GroupAdminController::class, 'destroy'])->name('bot.groups.destroy');
            Route::post('/groups/{id}/members',        [\App\Http\Controllers\GroupAdminController::class, 'addMember'])->name('bot.groups.members.add');
            Route::delete('/groups/{id}/members/{userId}', [\App\Http\Controllers\GroupAdminController::class, 'removeMember'])->name('bot.groups.members.remove');

            Route::get('/rules',                [\App\Http\Controllers\BotConfigController::class, 'rules'])->name('bot.rules');
            Route::get('/rules/options',        [\App\Http\Controllers\BotConfigController::class, 'ruleOptions'])->name('bot.rules.options');
            Route::post('/rules',               [\App\Http\Controllers\BotConfigController::class, 'storeRule'])->name('bot.rules.store');
            Route::put('/rules/{id}',           [\App\Http\Controllers\BotConfigController::class, 'updateRule'])->name('bot.rules.update');
            Route::delete('/rules/{id}',        [\App\Http\Controllers\BotConfigController::class, 'destroyRule'])->name('bot.rules.destroy');
            Route::post('/rules/{id}/test',          [\App\Http\Controllers\BotConfigController::class, 'testRule'])->name('bot.rules.test');
            Route::post('/rules/{id}/dispatch-test', [\App\Http\Controllers\BotConfigController::class, 'dispatchTestRule'])->name('bot.rules.dispatch-test');

            // Detectores proativos customizáveis pelo admin
            Route::get('/detectors',                 [\App\Http\Controllers\BotDetectorController::class, 'index'])->name('bot.detectors.index');
            Route::post('/detectors',                [\App\Http\Controllers\BotDetectorController::class, 'store'])->name('bot.detectors.store');
            Route::put('/detectors/{id}',            [\App\Http\Controllers\BotDetectorController::class, 'update'])->name('bot.detectors.update');
            Route::delete('/detectors/{id}',         [\App\Http\Controllers\BotDetectorController::class, 'destroy'])->name('bot.detectors.destroy');
            Route::post('/detectors/{id}/test',      [\App\Http\Controllers\BotDetectorController::class, 'test'])->name('bot.detectors.test');
            Route::post('/detectors/{id}/run',       [\App\Http\Controllers\BotDetectorController::class, 'run'])->name('bot.detectors.run');
            Route::post('/detectors/run-all',        [\App\Http\Controllers\BotDetectorController::class, 'run'])->name('bot.detectors.run-all');
            Route::post('/detectors/validate-sql',   [\App\Http\Controllers\BotDetectorController::class, 'validateSql'])->name('bot.detectors.validate-sql');

            // Permissões padrão do BOT por perfil de user
            Route::get('/permission-profiles',          [\App\Http\Controllers\BotPermissionProfileController::class, 'index'])->name('bot.permission-profiles.index');
            Route::put('/permission-profiles/{type}',   [\App\Http\Controllers\BotPermissionProfileController::class, 'update'])->name('bot.permission-profiles.update');
        });

        // ────────────────────────────────────────────────────────────────────
        // 📎 ATTACHMENTS (FASE 11.1 — camada global polimórfica)
        // Rotas REST genéricas. Permissão delegada ao service via registry.
        // ────────────────────────────────────────────────────────────────────
        Route::get('/attachments',                   [\App\Http\Controllers\AttachmentController::class, 'index'])->name('attachments.index');
        Route::post('/attachments',                  [\App\Http\Controllers\AttachmentController::class, 'store'])->name('attachments.store');
        // FASE 11.5 — Observability (admin-only, validado no controller).
        Route::get('/attachments/stats',             [\App\Http\Controllers\AttachmentsAnalyticsController::class, 'stats'])->name('attachments.stats');
        Route::get('/attachments/events',            [\App\Http\Controllers\AttachmentsAnalyticsController::class, 'events'])->name('attachments.events');
        Route::get('/attachments/health',            [\App\Http\Controllers\AttachmentsAnalyticsController::class, 'health'])->name('attachments.health');
        Route::get('/attachments/{id}',              [\App\Http\Controllers\AttachmentController::class, 'show'])->name('attachments.show');
        Route::get('/attachments/{id}/download',     [\App\Http\Controllers\AttachmentController::class, 'download'])->name('attachments.download');
        Route::get('/attachments/{id}/url',          [\App\Http\Controllers\AttachmentController::class, 'signedUrl'])->name('attachments.signed-url');
        Route::delete('/attachments/{id}',           [\App\Http\Controllers\AttachmentController::class, 'destroy'])->name('attachments.destroy');
        Route::post('/attachments/{id}/restore',     [\App\Http\Controllers\AttachmentController::class, 'restore'])->name('attachments.restore');

        // ────────────────────────────────────────────────────────────────────
        // 🔐 COFRE DE SENHAS (zero-knowledge) — só equipe interna.
        // Servidor guarda/entrega BLOBS cifrados no client; nunca vê segredo.
        // NUNCA logar payloads destas rotas.
        // ────────────────────────────────────────────────────────────────────
        Route::prefix('vault')->middleware('permission.or.admin:vault.use')->group(function () {
            // Perfil criptográfico / 2FA / unlock
            Route::get('/profile',              [\App\Http\Controllers\VaultProfileController::class, 'profile'])->name('vault.profile');
            Route::put('/lock-timeout',         [\App\Http\Controllers\VaultProfileController::class, 'setLockTimeout'])->middleware('throttle:20,1')->name('vault.lock-timeout');
            Route::post('/totp/setup',          [\App\Http\Controllers\VaultProfileController::class, 'totpSetup'])->middleware('throttle:10,1')->name('vault.totp.setup');
            Route::post('/totp/confirm',        [\App\Http\Controllers\VaultProfileController::class, 'totpConfirm'])->middleware('throttle:10,1')->name('vault.totp.confirm');
            Route::post('/ms/start',            [\App\Http\Controllers\VaultProfileController::class, 'msStart'])->middleware('throttle:10,1')->name('vault.ms.start');
            Route::get('/ms/status',            [\App\Http\Controllers\VaultProfileController::class, 'msStatus'])->name('vault.ms.status');
            Route::post('/profile/setup',       [\App\Http\Controllers\VaultProfileController::class, 'setup'])->name('vault.profile.setup');
            Route::post('/unlock',              [\App\Http\Controllers\VaultProfileController::class, 'unlock'])->middleware('throttle:vault-unlock')->name('vault.unlock');
            Route::post('/master-password',     [\App\Http\Controllers\VaultProfileController::class, 'changeMasterPassword'])->middleware('throttle:10,1')->name('vault.master-password');
            Route::post('/recovery/unlock',     [\App\Http\Controllers\VaultProfileController::class, 'recoveryUnlock'])->middleware('throttle:5,15')->name('vault.recovery.unlock');
            Route::post('/recovery/regenerate', [\App\Http\Controllers\VaultProfileController::class, 'regenerateRecovery'])->middleware('throttle:10,1')->name('vault.recovery.regenerate');
            Route::get('/public-keys',          [\App\Http\Controllers\VaultProfileController::class, 'publicKeys'])->name('vault.public-keys');
            Route::get('/teams',                [\App\Http\Controllers\VaultProfileController::class, 'teams'])->name('vault.teams');

            // Cofres e membros
            Route::get('/vaults',                        [\App\Http\Controllers\VaultController::class, 'index'])->name('vault.vaults.index');
            Route::post('/vaults',                       [\App\Http\Controllers\VaultController::class, 'store'])->name('vault.vaults.store');
            Route::put('/vaults/{id}',                   [\App\Http\Controllers\VaultController::class, 'update'])->name('vault.vaults.update');
            Route::delete('/vaults/{id}',                [\App\Http\Controllers\VaultController::class, 'destroy'])->name('vault.vaults.destroy');
            Route::get('/vaults/{id}/members',           [\App\Http\Controllers\VaultController::class, 'members'])->name('vault.members.index');
            Route::post('/vaults/{id}/members',          [\App\Http\Controllers\VaultController::class, 'addMember'])->name('vault.members.store');
            Route::put('/vaults/{id}/members/{userId}',  [\App\Http\Controllers\VaultController::class, 'updateMember'])->name('vault.members.update');
            Route::delete('/vaults/{id}/members/{userId}', [\App\Http\Controllers\VaultController::class, 'removeMember'])->name('vault.members.destroy');
            Route::post('/vaults/{id}/rotate',           [\App\Http\Controllers\VaultController::class, 'rotate'])->name('vault.rotate');

            // Itens
            Route::get('/vaults/{vaultId}/items',        [\App\Http\Controllers\VaultItemController::class, 'index'])->name('vault.items.index');
            Route::post('/vaults/{vaultId}/items',       [\App\Http\Controllers\VaultItemController::class, 'store'])->name('vault.items.store');
            Route::put('/items/{id}',                    [\App\Http\Controllers\VaultItemController::class, 'update'])->name('vault.items.update');
            Route::delete('/items/{id}',                 [\App\Http\Controllers\VaultItemController::class, 'destroy'])->name('vault.items.destroy');
            Route::post('/items/{id}/restore',           [\App\Http\Controllers\VaultItemController::class, 'restore'])->name('vault.items.restore');
            Route::post('/items/{id}/log',               [\App\Http\Controllers\VaultItemController::class, 'logAction'])->name('vault.items.log');

            // Auditoria — admin global vê tudo; admin de cofre vê só os seus (escopo no controller)
            Route::get('/logs',                          [\App\Http\Controllers\VaultController::class, 'logs'])->name('vault.logs');
        });

        // ────────────────────────────────────────────────────────────────────
        // 🖥️ COFRE DE AMBIENTES (infra Protheus) — camada ADITIVA sobre o cofre.
        // Metadados em CLARO; segredos só via /reveal enforced. NUNCA logar payload
        // com blob. Reusa a cripto/chaves do cofre de senhas sem tocá-las.
        // ────────────────────────────────────────────────────────────────────
        Route::prefix('environments')->middleware('permission.or.admin:environments.use')->group(function () {
            // Dashboard + busca (metadados CLARO)
            Route::get('/dashboard',                       [\App\Http\Controllers\EnvironmentController::class, 'dashboard'])->name('environments.dashboard');
            Route::get('/alerts',                          [\App\Http\Controllers\EnvironmentController::class, 'alerts'])->name('environments.alerts');
            Route::get('/search',                          [\App\Http\Controllers\EnvironmentController::class, 'search'])->name('environments.search');
            Route::get('/favorites',                       [\App\Http\Controllers\EnvironmentController::class, 'favorites'])->name('environments.favorites');
            Route::post('/environments/{envId}/favorite',  [\App\Http\Controllers\EnvironmentController::class, 'toggleFavorite'])->name('environments.favorite.toggle');
            // Clientes-vault
            Route::get('/clients',                         [\App\Http\Controllers\EnvironmentController::class, 'clients'])->name('environments.clients.index');
            Route::post('/clients',                        [\App\Http\Controllers\EnvironmentController::class, 'createClient'])->name('environments.clients.store');
            // Compartilhamento — remoção aditiva de membro do cofre do cliente (add/role reusam /vault/*)
            Route::delete('/clients/{customerId}/members/{userId}', [\App\Http\Controllers\EnvironmentController::class, 'removeMember'])->name('environments.member.remove');
            // Ambientes de um cliente
            Route::get('/clients/{customerId}/environments',  [\App\Http\Controllers\EnvironmentController::class, 'environments'])->name('environments.env.index');
            Route::post('/clients/{customerId}/environments', [\App\Http\Controllers\EnvironmentController::class, 'storeEnvironment'])->name('environments.env.store');
            Route::get('/environments/{id}',               [\App\Http\Controllers\EnvironmentController::class, 'showEnvironment'])->name('environments.env.show');
            Route::put('/environments/{id}',               [\App\Http\Controllers\EnvironmentController::class, 'updateEnvironment'])->name('environments.env.update');
            Route::delete('/environments/{id}',            [\App\Http\Controllers\EnvironmentController::class, 'destroyEnvironment'])->name('environments.env.destroy');
            // Credenciais (listagem SEM blob)
            Route::get('/environments/{envId}/credentials',  [\App\Http\Controllers\EnvironmentCredentialController::class, 'index'])->name('environments.cred.index');
            Route::post('/environments/{envId}/credentials', [\App\Http\Controllers\EnvironmentCredentialController::class, 'store'])->name('environments.cred.store');
            Route::put('/credentials/{id}',                [\App\Http\Controllers\EnvironmentCredentialController::class, 'update'])->name('environments.cred.update');
            Route::delete('/credentials/{id}',             [\App\Http\Controllers\EnvironmentCredentialController::class, 'destroy'])->name('environments.cred.destroy');
            // Reveal ENFORCED (único caminho do ciphertext)
            Route::post('/secrets/{id}/reveal',            [\App\Http\Controllers\EnvironmentSecretController::class, 'reveal'])->middleware('throttle:60,1')->name('environments.secret.reveal');

            // Infra (F1b): Banco / AppServer / VPN — listagem SEM blob; senha via /reveal.
            Route::get('/environments/{envId}/databases',  [\App\Http\Controllers\EnvironmentInfraController::class, 'databases'])->name('environments.db.index');
            Route::post('/environments/{envId}/databases', [\App\Http\Controllers\EnvironmentInfraController::class, 'storeDatabase'])->name('environments.db.store');
            Route::put('/databases/{id}',                  [\App\Http\Controllers\EnvironmentInfraController::class, 'updateDatabase'])->name('environments.db.update');
            Route::delete('/databases/{id}',               [\App\Http\Controllers\EnvironmentInfraController::class, 'destroyDatabase'])->name('environments.db.destroy');
            Route::get('/environments/{envId}/appservers',  [\App\Http\Controllers\EnvironmentInfraController::class, 'appservers'])->name('environments.app.index');
            Route::post('/environments/{envId}/appservers', [\App\Http\Controllers\EnvironmentInfraController::class, 'storeAppserver'])->name('environments.app.store');
            Route::put('/appservers/{id}',                 [\App\Http\Controllers\EnvironmentInfraController::class, 'updateAppserver'])->name('environments.app.update');
            Route::delete('/appservers/{id}',              [\App\Http\Controllers\EnvironmentInfraController::class, 'destroyAppserver'])->name('environments.app.destroy');
            Route::get('/environments/{envId}/vpns',        [\App\Http\Controllers\EnvironmentInfraController::class, 'vpns'])->name('environments.vpn.index');
            Route::post('/environments/{envId}/vpns',       [\App\Http\Controllers\EnvironmentInfraController::class, 'storeVpn'])->name('environments.vpn.store');
            Route::put('/vpns/{id}',                        [\App\Http\Controllers\EnvironmentInfraController::class, 'updateVpn'])->name('environments.vpn.update');
            Route::delete('/vpns/{id}',                     [\App\Http\Controllers\EnvironmentInfraController::class, 'destroyVpn'])->name('environments.vpn.destroy');

            // Certificados (F1c) — metadados em CLARO; senha do PFX via reveal; arquivo .pfx cifrado no client
            Route::get('/environments/{envId}/certificates',  [\App\Http\Controllers\EnvironmentCertificateController::class, 'index'])->name('environments.cert.index');
            Route::post('/environments/{envId}/certificates', [\App\Http\Controllers\EnvironmentCertificateController::class, 'store'])->name('environments.cert.store');
            Route::put('/certificates/{id}',                [\App\Http\Controllers\EnvironmentCertificateController::class, 'update'])->name('environments.cert.update');
            Route::delete('/certificates/{id}',             [\App\Http\Controllers\EnvironmentCertificateController::class, 'destroy'])->name('environments.cert.destroy');
            // Histórico de negócio do ambiente
            Route::get('/environments/{envId}/history',     [\App\Http\Controllers\EnvironmentCertificateController::class, 'history'])->name('environments.history');

            // ACL fina por ambiente (F2) — só admin do ambiente
            Route::get('/environments/{envId}/permissions',            [\App\Http\Controllers\EnvPermissionController::class, 'index'])->name('environments.perm.index');
            Route::put('/environments/{envId}/permissions/{userId}',   [\App\Http\Controllers\EnvPermissionController::class, 'upsert'])->name('environments.perm.upsert');
            Route::delete('/environments/{envId}/permissions/{userId}', [\App\Http\Controllers\EnvPermissionController::class, 'destroy'])->name('environments.perm.destroy');
            // ACL de grupo (herança automática)
            Route::get('/environments/{envId}/group-permissions',              [\App\Http\Controllers\EnvPermissionController::class, 'groupIndex'])->name('environments.gperm.index');
            Route::put('/environments/{envId}/group-permissions/{groupId}',    [\App\Http\Controllers\EnvPermissionController::class, 'groupUpsert'])->name('environments.gperm.upsert');
            Route::delete('/environments/{envId}/group-permissions/{groupId}', [\App\Http\Controllers\EnvPermissionController::class, 'groupDestroy'])->name('environments.gperm.destroy');

            // Links + Documentação (F1d)
            Route::get('/environments/{envId}/links',       [\App\Http\Controllers\EnvironmentLinkController::class, 'index'])->name('environments.link.index');
            Route::post('/environments/{envId}/links',      [\App\Http\Controllers\EnvironmentLinkController::class, 'store'])->name('environments.link.store');
            Route::delete('/links/{id}',                    [\App\Http\Controllers\EnvironmentLinkController::class, 'destroy'])->name('environments.link.destroy');
            Route::get('/environments/{envId}/docs',        [\App\Http\Controllers\EnvironmentLinkController::class, 'docs'])->name('environments.docs.index');
        });
    });

    // Signed URL externa (sem auth:sanctum; o middleware 'signed' garante
    // autenticidade do link; controller revalida permissão no service).
    Route::middleware('signed')->get('/attachments-signed-download',
        [\App\Http\Controllers\AttachmentController::class, 'signedDownload'])
        ->name('attachments.signed-download');
});
