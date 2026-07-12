<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Ai\Tools\MinutorToolRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Camada de visibilidade para o BOT.
 *
 * Funciona em duas etapas, executadas em MinutorToolRegistry::execute():
 *
 *   1) resolveScopeAccess($user, $scope) — retorna 'self'|'team'|'all'|'denied'
 *      considerando: user.bot_scope_overrides → user.bot_visibility → default.
 *
 *   2) applyToolFilters($user, $toolName, $input) — força/ajusta os params
 *      de entrada (user_id, customer_id) conforme o acesso resolvido. Retorna
 *      ['allowed' => bool, 'input' => array, 'reason' => string|null].
 *
 * Auditoria: toda barragem é logada com a tool, scope e user_id.
 */
class BotAccessControl
{
    /**
     * Tools que NUNCA podem ser chamadas por user com visibility != 'all'.
     * São endpoints agregados sobre toda a base (folha global, banco de horas
     * de todos os consultores, overview financeiro etc).
     */
    private const ADMIN_ONLY_TOOLS = [
        'get_financial_overview',
        'list_critical_bank_hours',
        'list_pending_expense_payments',
    ];

    /** Tools que aceitam user_id e devem ser forçadas pro próprio user em 'self'. */
    private const USER_ID_TOOLS = [
        'get_consultant_summary',
        'get_consultant_payroll',
        'get_consultant_payroll_breakdown',
        'get_consultant_bank_hours',
        'list_consultant_tickets',
        'list_consultant_expenses',
        'get_consultant_capacity',
    ];

    /** Tools que aceitam customer_id e devem ser filtradas pelo team em 'self'/'team'. */
    private const CUSTOMER_ID_TOOLS = [
        'get_customer_overview',
        'list_customer_projects',
        'list_customer_contracts',
        'get_customer_billing_status',
        'list_customer_tickets',
    ];

    /** Tools que aceitam user_id OPCIONAL (filtra automaticamente em 'self'). */
    private const OPTIONAL_USER_ID_TOOLS = [
        'list_late_timesheets',
        'list_pending_approvals',
    ];

    /**
     * Resolve o nível de acesso (self|team|all|denied) deste user para um scope.
     */
    public function resolveScopeAccess(User $user, string $scope): string
    {
        $overrides = is_array($user->bot_scope_overrides) ? $user->bot_scope_overrides : [];
        if (array_key_exists($scope, $overrides)) {
            $v = $overrides[$scope];
            if (in_array($v, ['self', 'team', 'all', 'denied', 'inherit'], true) && $v !== 'inherit') {
                return $v;
            }
        }
        $vis = $user->bot_visibility ?? 'self';
        return in_array($vis, ['self', 'team', 'all'], true) ? $vis : 'self';
    }

    /**
     * Aplica as regras de visibilidade aos params da tool.
     *
     * @return array{allowed: bool, input: array, reason: string|null, access: string}
     */
    public function applyToolFilters(User $user, string $toolName, array $input): array
    {
        $scope = MinutorToolRegistry::TOOL_SCOPE[$toolName] ?? null;
        if (! $scope) {
            return ['allowed' => true, 'input' => $input, 'reason' => null, 'access' => 'all'];
        }

        $access = $this->resolveScopeAccess($user, $scope);

        if ($access === 'denied') {
            $this->audit($user, $toolName, $scope, $access, 'scope denied');
            return [
                'allowed' => false,
                'input'   => $input,
                'access'  => $access,
                'reason'  => "Você não tem permissão para consultar dados de '{$scope}'.",
            ];
        }

        // Tools agregadas que exigem 'all'
        if (in_array($toolName, self::ADMIN_ONLY_TOOLS, true) && $access !== 'all') {
            $this->audit($user, $toolName, $scope, $access, 'admin-only tool');
            return [
                'allowed' => false,
                'input'   => $input,
                'access'  => $access,
                'reason'  => "Esta consulta agrega dados globais e está disponível apenas para administradores.",
            ];
        }

        // Tools com user_id obrigatório
        if (in_array($toolName, self::USER_ID_TOOLS, true)) {
            $requested = (int) ($input['user_id'] ?? 0);
            $allowedUserIds = $this->resolveUserIds($user, $access);

            if ($requested <= 0) {
                // BOT não passou user_id → usa o próprio user
                $input['user_id'] = $user->id;
                return ['allowed' => true, 'input' => $input, 'reason' => null, 'access' => $access];
            }

            if ($allowedUserIds !== null && ! in_array($requested, $allowedUserIds, true)) {
                $this->audit($user, $toolName, $scope, $access, "user_id={$requested} fora do escopo");
                return [
                    'allowed' => false,
                    'input'   => $input,
                    'access'  => $access,
                    'reason'  => $access === 'self'
                        ? "Você só pode consultar dados sobre você mesmo."
                        : "Esse consultor está fora da sua equipe.",
                ];
            }
            return ['allowed' => true, 'input' => $input, 'reason' => null, 'access' => $access];
        }

        // Tools com customer_id obrigatório
        if (in_array($toolName, self::CUSTOMER_ID_TOOLS, true)) {
            $requested = (int) ($input['customer_id'] ?? 0);
            $allowedCustomerIds = $this->resolveCustomerIds($user, $access);

            if ($requested <= 0) {
                return ['allowed' => true, 'input' => $input, 'reason' => null, 'access' => $access];
            }

            if ($allowedCustomerIds !== null && ! in_array($requested, $allowedCustomerIds, true)) {
                $this->audit($user, $toolName, $scope, $access, "customer_id={$requested} fora do escopo");
                return [
                    'allowed' => false,
                    'input'   => $input,
                    'access'  => $access,
                    'reason'  => $access === 'self'
                        ? "Você só pode consultar clientes onde você atua diretamente."
                        : "Esse cliente está fora da sua equipe.",
                ];
            }
            return ['allowed' => true, 'input' => $input, 'reason' => null, 'access' => $access];
        }

        // Tools com user_id OPCIONAL — força em self
        if (in_array($toolName, self::OPTIONAL_USER_ID_TOOLS, true) && $access === 'self') {
            $input['user_id'] = $user->id;
            return ['allowed' => true, 'input' => $input, 'reason' => null, 'access' => $access];
        }

        // search_customer / get_movidesk_ticket etc. — só checagem de scope (já passou)
        return ['allowed' => true, 'input' => $input, 'reason' => null, 'access' => $access];
    }

    /**
     * Lista de user_ids que este user pode ver. null = qualquer um (acesso 'all').
     */
    private function resolveUserIds(User $user, string $access): ?array
    {
        return match ($access) {
            'self' => [$user->id],
            'team' => $user->botTeamUserIds(),
            'all'  => null,
            default => [$user->id],
        };
    }

    /**
     * Lista de customer_ids que este user pode ver. null = qualquer um.
     */
    private function resolveCustomerIds(User $user, string $access): ?array
    {
        return match ($access) {
            'self', 'team' => $user->botTeamCustomerIds(),
            'all'  => null,
            default => $user->botTeamCustomerIds(),
        };
    }

    private function audit(User $user, string $tool, string $scope, string $access, string $reason): void
    {
        Log::warning('[BotAccessControl] denied', [
            'user_id' => $user->id,
            'tool'    => $tool,
            'scope'   => $scope,
            'access'  => $access,
            'reason'  => $reason,
        ]);
    }
}
