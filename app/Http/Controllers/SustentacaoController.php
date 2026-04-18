<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\MovideskTicket;
use App\Services\MovideskService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SustentacaoController extends Controller
{
    private function authorize(): void
    {
        $user = auth()->user();
        if ($user->type !== 'admin' && !($user->type === 'coordenador' && $user->coordinator_type === 'sustentacao')) {
            abort(403, 'Acesso restrito ao Portal de Sustentação');
        }
    }

    private function dateRange(Request $request): array
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : now()->subDays(30);
        $to   = $request->query('to')   ? Carbon::parse($request->query('to'))   : now();
        return [$from->startOfDay(), $to->endOfDay()];
    }

    public function kpis(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $openStatuses = ['New', 'InAttendance', 'Stopped'];

        $totalOpen      = MovideskTicket::whereIn('base_status', $openStatuses)->count();
        $newToday       = MovideskTicket::whereDate('created_date', today())->count();
        $resolvedPeriod = MovideskTicket::whereBetween('resolved_in', [$from, $to])->count();
        $closedPeriod   = MovideskTicket::whereBetween('closed_in',   [$from, $to])->count();

        // SLA response compliance
        $slaRespTotal   = MovideskTicket::whereBetween('created_date', [$from, $to])->whereNotNull('sla_response_date')->count();
        $slaRespOnTime  = MovideskTicket::whereBetween('created_date', [$from, $to])
            ->whereNotNull('sla_real_response_date')
            ->whereColumn('sla_real_response_date', '<=', 'sla_response_date')
            ->count();
        $slaResponseRate = $slaRespTotal > 0 ? round(($slaRespOnTime / $slaRespTotal) * 100, 1) : null;

        // SLA solution compliance
        $slaSolTotal  = MovideskTicket::whereBetween('created_date', [$from, $to])
            ->whereNotNull('sla_solution_date')->whereNotNull('resolved_in')->count();
        $slaSolOnTime = MovideskTicket::whereBetween('created_date', [$from, $to])
            ->whereNotNull('sla_solution_date')->whereNotNull('resolved_in')
            ->whereColumn('resolved_in', '<=', 'sla_solution_date')
            ->count();
        $slaSolutionRate = $slaSolTotal > 0 ? round(($slaSolOnTime / $slaSolTotal) * 100, 1) : null;

        $openAtRisk = MovideskTicket::whereIn('base_status', $openStatuses)
            ->whereNotNull('sla_solution_date')
            ->where('sla_solution_date', '<', now())
            ->count();

        $avgSolutionTime = MovideskTicket::whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_time')
            ->avg('sla_solution_time');

        return response()->json([
            'total_open'         => $totalOpen,
            'new_today'          => $newToday,
            'resolved_period'    => $resolvedPeriod,
            'closed_period'      => $closedPeriod,
            'sla_response_rate'  => $slaResponseRate,
            'sla_solution_rate'  => $slaSolutionRate,
            'open_at_risk'       => $openAtRisk,
            'avg_solution_time'  => $avgSolutionTime ? round($avgSolutionTime) : null,
            'period'             => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        $this->authorize();

        $tickets = MovideskTicket::whereIn('base_status', ['New', 'InAttendance', 'Stopped'])
            ->with(['user:id,name', 'customer:id,name'])
            ->orderByRaw("CASE urgencia
                WHEN 'Urgente' THEN 1
                WHEN 'Alta'    THEN 2
                WHEN 'Normal'  THEN 3
                WHEN 'Baixa'   THEN 4
                ELSE 5 END")
            ->orderBy(DB::raw('sla_solution_date IS NULL'), 'asc')
            ->orderBy('sla_solution_date')
            ->orderBy('created_date')
            ->paginate($request->query('per_page', 50));

        return response()->json($tickets);
    }

    public function sla(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $byUrgency = MovideskTicket::selectRaw('urgencia')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN sla_real_response_date IS NOT NULL AND sla_real_response_date <= sla_response_date THEN 1 ELSE 0 END) as on_time_response')
            ->selectRaw('SUM(CASE WHEN resolved_in IS NOT NULL AND resolved_in <= sla_solution_date THEN 1 ELSE 0 END) as on_time_solution')
            ->whereBetween('created_date', [$from, $to])
            ->groupBy('urgencia')
            ->orderByRaw("CASE urgencia WHEN 'Urgente' THEN 1 WHEN 'Alta' THEN 2 WHEN 'Normal' THEN 3 WHEN 'Baixa' THEN 4 ELSE 5 END")
            ->get();

        $breachingNow = MovideskTicket::whereIn('base_status', ['New', 'InAttendance', 'Stopped'])
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('sla_response_date')
                       ->whereNull('sla_real_response_date')
                       ->where('sla_response_date', '<', now());
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('sla_solution_date')
                       ->where('sla_solution_date', '<', now());
                });
            })
            ->with(['user:id,name', 'customer:id,name'])
            ->orderBy('sla_solution_date')
            ->get();

        $trend = MovideskTicket::selectRaw("TO_CHAR(DATE_TRUNC('month', created_date), 'YYYY-MM') as month")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN resolved_in IS NOT NULL AND resolved_in <= sla_solution_date THEN 1 ELSE 0 END) as on_time')
            ->whereNotNull('created_date')
            ->where('created_date', '>=', now()->subMonths(6))
            ->groupByRaw("DATE_TRUNC('month', created_date)")
            ->orderByRaw("DATE_TRUNC('month', created_date)")
            ->get();

        return response()->json([
            'by_urgency'    => $byUrgency,
            'breaching_now' => $breachingNow,
            'monthly_trend' => $trend,
        ]);
    }

    public function productivity(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $byConsultant = MovideskTicket::select('user_id')
            ->selectRaw('COUNT(*) as tickets_resolved')
            ->selectRaw('AVG(sla_solution_time) as avg_solution_minutes')
            ->whereNotNull('user_id')
            ->whereNotNull('resolved_in')
            ->whereBetween('resolved_in', [$from, $to])
            ->groupBy('user_id')
            ->with('user:id,name')
            ->orderByDesc('tickets_resolved')
            ->get();

        $hoursFromTimesheets = DB::table('timesheets')
            ->join('projects', 'projects.id', '=', 'timesheets.project_id')
            ->join('service_types', 'service_types.id', '=', 'projects.service_type_id')
            ->where(function ($q) {
                $q->where('service_types.code', 'sustentacao')
                  ->orWhere('service_types.name', 'ilike', '%sustenta%');
            })
            ->whereBetween('timesheets.date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('timesheets.status', ['approved', 'pending'])
            ->select('timesheets.user_id', DB::raw('SUM(timesheets.effort_minutes) as total_minutes'))
            ->groupBy('timesheets.user_id')
            ->get()
            ->keyBy('user_id');

        $byConsultant = $byConsultant->map(function ($item) use ($hoursFromTimesheets) {
            $ts = $hoursFromTimesheets->get($item->user_id);
            $item->total_minutes_worked = $ts ? (int) $ts->total_minutes : 0;
            return $item;
        });

        return response()->json([
            'by_consultant' => $byConsultant,
            'period'        => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function financial(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $byProject = DB::table('timesheets')
            ->join('projects', 'projects.id', '=', 'timesheets.project_id')
            ->join('service_types', 'service_types.id', '=', 'projects.service_type_id')
            ->join('customers', 'customers.id', '=', 'projects.customer_id')
            ->where(function ($q) {
                $q->where('service_types.code', 'sustentacao')
                  ->orWhere('service_types.name', 'ilike', '%sustenta%');
            })
            ->whereBetween('timesheets.date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('timesheets.status', ['approved', 'pending'])
            ->select(
                'projects.id as project_id',
                'projects.name as project_name',
                'projects.sold_hours',
                'customers.id as customer_id',
                'customers.name as customer_name',
                DB::raw('SUM(timesheets.effort_minutes) as total_minutes'),
                DB::raw('COUNT(DISTINCT timesheets.ticket) as ticket_count')
            )
            ->groupBy('projects.id', 'projects.name', 'projects.sold_hours', 'customers.id', 'customers.name')
            ->orderByDesc('total_minutes')
            ->get();

        return response()->json([
            'by_project' => $byProject,
            'period'     => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function clients(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $byClient = MovideskTicket::select('customer_id')
            ->selectRaw('COUNT(*) as total_period')
            ->selectRaw("SUM(CASE WHEN base_status IN ('New','InAttendance','Stopped') THEN 1 ELSE 0 END) as open_now")
            ->selectRaw('SUM(CASE WHEN resolved_in IS NOT NULL AND resolved_in <= sla_solution_date THEN 1 ELSE 0 END) as sla_ok')
            ->selectRaw('ROUND(AVG(sla_solution_time)::numeric, 0) as avg_solution_minutes')
            ->whereNotNull('customer_id')
            ->whereBetween('created_date', [$from, $to])
            ->groupBy('customer_id')
            ->with('customer:id,name')
            ->orderByDesc('total_period')
            ->get();

        return response()->json([
            'by_client' => $byClient,
            'period'    => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function distribution(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $base = fn() => MovideskTicket::whereBetween('created_date', [$from, $to]);

        return response()->json([
            'by_urgency'    => $base()->selectRaw('urgencia as label, COUNT(*) as count')->whereNotNull('urgencia')->groupBy('urgencia')->orderByDesc('count')->get(),
            'by_category'   => $base()->selectRaw('categoria as label, COUNT(*) as count')->whereNotNull('categoria')->groupBy('categoria')->orderByDesc('count')->get(),
            'by_service'    => $base()->selectRaw('servico as label, COUNT(*) as count')->whereNotNull('servico')->groupBy('servico')->orderByDesc('count')->get(),
            'by_team'       => $base()->selectRaw('owner_team as label, COUNT(*) as count')->whereNotNull('owner_team')->groupBy('owner_team')->orderByDesc('count')->get(),
            'by_base_status' => $base()->selectRaw('base_status as label, COUNT(*) as count')->whereNotNull('base_status')->groupBy('base_status')->orderByDesc('count')->get(),
            'by_origin'     => $base()->selectRaw('origin as label, COUNT(*) as count')->whereNotNull('origin')->groupBy('origin')->orderByDesc('count')->get(),
            'period'        => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function evolution(Request $request): JsonResponse
    {
        $this->authorize();

        $monthly = MovideskTicket::selectRaw("TO_CHAR(DATE_TRUNC('month', created_date), 'YYYY-MM') as month")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN resolved_in IS NOT NULL THEN 1 ELSE 0 END) as resolved')
            ->selectRaw('SUM(CASE WHEN resolved_in IS NOT NULL AND resolved_in <= sla_solution_date THEN 1 ELSE 0 END) as sla_ok')
            ->whereNotNull('created_date')
            ->where('created_date', '>=', now()->subMonths(12)->startOfMonth())
            ->groupByRaw("DATE_TRUNC('month', created_date)")
            ->orderByRaw("DATE_TRUNC('month', created_date)")
            ->get();

        return response()->json(['monthly' => $monthly]);
    }

    public function debugClientes(MovideskService $service): JsonResponse
    {
        $this->authorize();

        // Busca organizações do Movidesk com cache de 1h para não bater rate limit
        $movideskOrgs = Cache::remember('movidesk_orgs_debug', 3600, fn() => $service->fetchOrganizations());

        $rows = MovideskTicket::whereNotNull('solicitante')
            ->selectRaw("
                solicitante->>'organization' as org,
                COUNT(*)                     as tickets,
                SUM(CASE WHEN customer_id IS NOT NULL THEN 1 ELSE 0 END) as vinculados
            ")
            ->groupByRaw("solicitante->>'organization'")
            ->orderByDesc('tickets')
            ->get();

        $customersByCnpj = Customer::whereNotNull('cgc')
            ->get()
            ->keyBy(fn($c) => preg_replace('/[^0-9]/', '', $c->cgc));

        $result = $rows->map(function ($row) use ($movideskOrgs, $customersByCnpj) {
            $orgKey      = strtolower(trim($row->org ?? ''));
            $movideskOrg = $movideskOrgs[$orgKey] ?? null;
            $cnpjNorm    = $movideskOrg['cpfCnpj'] ?? '';

            if ($cnpjNorm && isset($customersByCnpj[$cnpjNorm])) {
                $match       = 'cnpj';
                $minutorName = $customersByCnpj[$cnpjNorm]->name ?? $customersByCnpj[$cnpjNorm]->company_name;
                $minutorCgc  = $customersByCnpj[$cnpjNorm]->cgc;
            } else {
                $byName      = Customer::where('name', $row->org)->orWhere('company_name', $row->org)->first();
                $match       = $byName ? 'nome' : 'nao';
                $minutorName = $byName?->name ?? $byName?->company_name;
                $minutorCgc  = $byName?->cgc;
            }

            return [
                'org'           => $row->org,
                'cnpj_movidesk' => $cnpjNorm ?: null,
                'tickets'       => (int) $row->tickets,
                'vinculados'    => (int) $row->vinculados,
                'match'         => $match,
                'minutor_name'  => $minutorName,
                'minutor_cgc'   => $minutorCgc,
            ];
        });

        return response()->json(['rows' => $result]);
    }
}
