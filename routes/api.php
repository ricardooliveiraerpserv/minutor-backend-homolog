<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContractTypeController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ServiceTypeController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseTypeController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\ConsultantGroupController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\MovideskWebhookController;
use App\Http\Controllers\ProjectStatusController;
use App\Http\Controllers\BankHoursFixedController;
use App\Http\Controllers\BankHoursMonthlyController;
use App\Http\Controllers\OnDemandController;
use App\Http\Controllers\ExecutiveController;
use App\Http\Controllers\HourContributionController;

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
    // Rotas públicas (sem autenticação)
    Route::prefix('auth')->group(function () {
        // Autenticação
        Route::post('/login', [AuthController::class, 'login'])->name('auth.login');

        // Recuperação de senha
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])
            ->name('password.email');
        Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])
            ->name('password.reset');
        Route::post('/verify-reset-token', [PasswordResetController::class, 'verifyResetToken'])
            ->name('password.verify');
    });

    // 🎫 WEBHOOKS - Rotas públicas para receber notificações externas
    Route::post('/webhooks/movidesk/ticket', [MovideskWebhookController::class, 'handleTicket'])
        ->name('webhooks.movidesk.ticket');

    // 🔍 DEBUG temporário - diagnóstico da API Movidesk (sem auth)
    Route::get('/movidesk/debug', [\App\Http\Controllers\MovideskAdminController::class, 'debug'])
        ->name('movidesk.debug.public');

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
            'status' => 'ok',
            'message' => 'API funcionando corretamente',
            'timestamp' => now(),
            'version' => '1.0.0'
        ]);
    })->name('api.health');

    // Rotas protegidas (com autenticação Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        // Dados do usuário
        Route::get('/user', [AuthController::class, 'user'])->name('user.profile');
        Route::put('/user/profile', [AuthController::class, 'updateProfile'])->name('user.update');
        Route::put('/user/theme-preference', [AuthController::class, 'updateThemePreference'])->name('user.theme-preference');

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

        // Alteração de senha
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])
            ->name('auth.change-password');
        Route::post('/auth/change-temporary-password', [AuthController::class, 'changeTemporaryPassword'])
            ->name('auth.change-temporary-password');

        // === GERENCIAMENTO DE ROLES E PERMISSÕES ===

        // 🔐 ROLES (Perfis) - Protegido por permissões específicas (Admins sempre têm acesso)
        Route::middleware('permission.or.admin:roles.view')->group(function () {
            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
            Route::get('/roles/{role}/permissions', [RoleController::class, 'permissions'])
                ->name('roles.permissions');
        });

        Route::middleware('permission.or.admin:roles.create')->group(function () {
            Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        });

        Route::middleware('permission.or.admin:roles.update')->group(function () {
            Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::post('/roles/{role}/permissions', [RoleController::class, 'givePermissions'])
                ->name('roles.give-permissions');
            Route::delete('/roles/{role}/permissions', [RoleController::class, 'revokePermissions'])
                ->name('roles.revoke-permissions');
        });

        Route::middleware('permission.or.admin:roles.delete')->group(function () {
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        // 🔑 PERMISSÕES - Protegido por permissões específicas (Admins sempre têm acesso)
        Route::middleware('permission.or.admin:permissions.view')->group(function () {
            Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
            Route::get('/permissions/grouped', [PermissionController::class, 'grouped'])
                ->name('permissions.grouped');
            Route::get('/permissions/{permission}', [PermissionController::class, 'show'])->name('permissions.show');
        });

        Route::middleware('permission.or.admin:permissions.create')->group(function () {
            Route::post('/permissions', [PermissionController::class, 'store'])->name('permissions.store');
        });

        Route::middleware('permission.or.admin:permissions.update')->group(function () {
            Route::put('/permissions/{permission}', [PermissionController::class, 'update'])->name('permissions.update');
        });

        Route::middleware('permission.or.admin:permissions.delete')->group(function () {
            Route::delete('/permissions/{permission}', [PermissionController::class, 'destroy'])->name('permissions.destroy');
        });

        // 👥 USUÁRIOS COM ROLES - Protegido por permissões específicas (Admins sempre têm acesso)
        Route::middleware('permission.or.admin:users.view')->group(function () {
            Route::get('/users/{user}/roles', [UserRoleController::class, 'getUserRoles'])
                ->name('users.roles');
            Route::get('/users/{user}/permissions', [UserRoleController::class, 'getUserPermissions'])
                ->name('users.permissions');
            Route::post('/users/{user}/permissions/check', [UserRoleController::class, 'checkPermission'])
                ->name('users.check-permission');
        });

        Route::middleware('permission.or.admin:users.update')->group(function () {
            Route::post('/users/{user}/roles', [UserRoleController::class, 'assignRoles'])
                ->name('users.assign-roles');
            Route::put('/users/{user}/roles', [UserRoleController::class, 'syncRoles'])
                ->name('users.sync-roles');
            Route::delete('/users/{user}/roles', [UserRoleController::class, 'removeRoles'])
                ->name('users.remove-roles');
        });

        // 🏆 EXECUTIVES - Gestão de executivos
        Route::get('/executives', [ExecutiveController::class, 'index'])->name('executives.index');
        Route::get('/executives/all', [ExecutiveController::class, 'all'])->name('executives.all');
        Route::middleware('permission.or.admin:users.update')->group(function () {
            Route::patch('/executives/{user}', [ExecutiveController::class, 'toggle'])->name('executives.toggle');
        });

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

        Route::middleware('permission.or.admin:projects.view')->group(function () {
            Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::get('/projects/{project}/change-history', [ProjectController::class, 'changeHistory'])->name('projects.change-history');
        });

        Route::middleware('permission.or.admin:projects.view_costs')->group(function () {
            Route::get('/projects/{project}/cost-summary', [ProjectController::class, 'costSummary'])->name('projects.cost-summary');
        });

        Route::middleware('permission.or.admin:projects.view')->group(function () {
            Route::get('/projects/{project}/available-hours', [ProjectController::class, 'availableHours'])->name('projects.available-hours');
        });

        Route::middleware('permission.or.admin:projects.create')->group(function () {
            Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        });

        Route::middleware('permission.or.admin:projects.update')->group(function () {
            Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
            Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.patch');
            Route::put('/projects/{project}/sold-hours-history/{history}', [ProjectController::class, 'updateSoldHoursHistory'])->name('projects.sold-hours-history.update');
            Route::delete('/projects/{project}/sold-hours-history/{history}', [ProjectController::class, 'destroySoldHoursHistory'])->name('projects.sold-hours-history.destroy');
            Route::put('/projects/{project}/change-history/{log}', [ProjectController::class, 'updateChangeHistory'])->name('projects.change-history.update');
            Route::delete('/projects/{project}/change-history/{log}', [ProjectController::class, 'destroyChangeHistory'])->name('projects.change-history.destroy');
        });

        Route::middleware('permission.or.admin:projects.delete')->group(function () {
            Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        });

        // 💰 HOUR CONTRIBUTIONS - Aportes de Horas (vinculados a projetos)
        Route::middleware('permission.or.admin:projects.view')->group(function () {
            Route::get('/projects/{project}/hour-contributions', [HourContributionController::class, 'index'])->name('hour-contributions.index');
        });

        Route::middleware('permission.or.admin:projects.update')->group(function () {
            Route::post('/projects/{project}/hour-contributions', [HourContributionController::class, 'store'])->name('hour-contributions.store');
            Route::put('/projects/{project}/hour-contributions/{contribution}', [HourContributionController::class, 'update'])->name('hour-contributions.update');
            Route::delete('/projects/{project}/hour-contributions/{contribution}', [HourContributionController::class, 'destroy'])->name('hour-contributions.destroy');
        });

        // ⏰ TIMESHEETS - Protegido por permissões específicas (Admins sempre têm acesso)

        // Rotas que qualquer usuário autenticado pode acessar (com lógica de permissão no controller)
        Route::get('/timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
        Route::get('/timesheets/export', [TimesheetController::class, 'export'])->name('timesheets.export');
        Route::get('/timesheets/{timesheet}', [TimesheetController::class, 'show'])->name('timesheets.show');

        Route::middleware('permission.or.admin:hours.create')->group(function () {
            Route::post('/timesheets', [TimesheetController::class, 'store'])->name('timesheets.store');
        });

        // Atualização e exclusão verificadas no controller baseado na propriedade
        Route::put('/timesheets/{timesheet}', [TimesheetController::class, 'update'])->name('timesheets.update');
        Route::patch('/timesheets/{timesheet}', [TimesheetController::class, 'update'])->name('timesheets.patch');
        Route::delete('/timesheets/{timesheet}', [TimesheetController::class, 'destroy'])->name('timesheets.destroy');

        // Aprovação e rejeição
        Route::middleware('permission.or.admin:hours.approve')->group(function () {
            Route::post('/timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])->name('timesheets.approve');
        });

        Route::middleware('permission.or.admin:hours.reject')->group(function () {
            Route::post('/timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])->name('timesheets.reject');
            Route::post('/timesheets/{timesheet}/reverse-approval', [TimesheetController::class, 'reverseApproval'])->name('timesheets.reverse-approval');
            Route::post('/timesheets/{timesheet}/reverse-rejection', [TimesheetController::class, 'reverseRejection'])->name('timesheets.reverse-rejection');
        });

        // 💰 DESPESAS - Protegido por permissões específicas (Admins sempre têm acesso)
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/export', [ExpenseController::class, 'export'])->name('expenses.export');
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');

        Route::middleware('permission.or.admin:expenses.create')->group(function () {
            Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        });

        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::patch('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.patch');
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

        // Upload de foto de perfil
        Route::post('/users/profile/photo', [UserController::class, 'uploadProfilePhoto'])->name('users.upload-photo');
        Route::delete('/users/profile/photo', [UserController::class, 'removeProfilePhoto'])->name('users.remove-photo');

        // Gerenciamento completo de usuários (requer permissões específicas)
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');

        Route::middleware('permission.or.admin:users.create')->group(function () {
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
        });

        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.patch');

        Route::middleware('permission.or.admin:users.delete')->group(function () {
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });

        Route::middleware('permission.or.admin:users.reset_password')->group(function () {
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        });

        // Histórico de alterações de valor hora
        Route::get('/users/{user}/hourly-rate-history', [UserController::class, 'getHourlyRateHistory'])->name('users.hourly-rate-history');

        // 🎯 APROVAÇÕES - Endpoints para gerenciar aprovações pendentes
        Route::middleware('permission.or.admin:hours.approve,expenses.approve')->group(function () {
            Route::get('/approvals/pending', [ApprovalController::class, 'getPendingApprovals'])->name('approvals.pending');
            Route::get('/approvals/timesheets', [ApprovalController::class, 'getPendingTimesheets'])->name('approvals.timesheets');
            Route::get('/approvals/expenses', [ApprovalController::class, 'getPendingExpenses'])->name('approvals.expenses');
            Route::post('/approvals/timesheets/bulk-approve', [ApprovalController::class, 'bulkApproveTimesheets'])->name('approvals.timesheets.bulk-approve');
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

        // Valores de campos customizados (contexto dinâmico: projects, timesheets, expenses, customers)
        Route::get('/{context}/{entityId}/custom-field-values', [CustomFieldController::class, 'getValues'])
            ->name('custom-field-values.get')
            ->where('context', 'projects|timesheets|expenses|customers');
        Route::post('/{context}/{entityId}/custom-field-values', [CustomFieldController::class, 'saveValues'])
            ->name('custom-field-values.save')
            ->where('context', 'projects|timesheets|expenses|customers');

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
        });

        Route::middleware('permission.or.admin:system_settings.update')->group(function () {
            Route::post("/movidesk/sync", [\App\Http\Controllers\MovideskAdminController::class, "sync"])->name("movidesk.sync");
        });
    });
});
