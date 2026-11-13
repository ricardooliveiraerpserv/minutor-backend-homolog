<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cliente NUNCA recebe dados comerciais/de custo dos projetos (valor do projeto, valor-hora,
 * custo e capacidade dos consultores, horas de coordenação, aportes, saldo interno…).
 * As telas do cliente são "só status" e não usam nada disso — mas os campos iam na resposta
 * de /projects, /projects/{id} e do quadro /contracts/kanban (visíveis pelo navegador).
 *
 * Remove as chaves sensíveis em QUALQUER nível da resposta (projeto, subprojeto, consultores…).
 * Horas vendidas/consumidas só ficam quando o projeto tem "cliente acompanha apontamento".
 * Internos: resposta intacta.
 */
class ClientRedactProjectFinancials
{
    private const DENY = [
        // valores / taxas do projeto
        'project_value', 'total_project_value', 'hourly_rate', 'weighted_hourly_rate', 'valor_hora',
        'charge_excess_hours', 'is_rateio', 'coordinator_percentage', 'coordinator_percentage_logs',
        // horas internas (coordenação, aportes, saldo, disponível, composição das vendidas)
        'consultant_hours', 'coordinator_hours', 'coordination_hours', 'coordination_consumed_hours',
        'total_contributions_hours', 'vendidas_projeto_hours', 'vendidas_aporte_hours',
        'general_hours_balance', 'total_available_hours', 'allow_negative_balance', 'hour_contributions',
        // custo / capacidade de consultores
        'rate_type', 'daily_hours', 'capacity_hours', 'allocated_hours', 'bank_hours_initial_balance',
        'salary', 'cost', 'cost_hour', 'custo', 'custo_hora', 'margin', 'margem', 'expected_cost', 'real_cost',
        'revenue', 'receita', 'profit', 'lucro',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();
        if (!$user || $user->type !== 'cliente' || !$response instanceof JsonResponse) {
            return $response;
        }
        $data = $response->getData(true);
        if (!is_array($data)) return $response;
        $response->setData($this->scrub($data));
        return $response;
    }

    private function scrub(array $node): array
    {
        // Projeto sem "cliente acompanha apontamento": também sem horas vendidas/consumidas.
        if (array_key_exists('client_follows_timesheets', $node) && $node['client_follows_timesheets'] === false) {
            unset($node['sold_hours'], $node['consumed_hours'], $node['hours_balance']);
        }
        foreach ($node as $k => $v) {
            if (is_string($k) && in_array($k, self::DENY, true)) { unset($node[$k]); continue; }
            if (is_array($v)) $node[$k] = $this->scrub($v);
        }
        return $node;
    }
}
