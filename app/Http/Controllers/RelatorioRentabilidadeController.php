<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Models\Timesheet;
use App\Models\UserHourlyRateLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

/**
 * Relatório de Rentabilidade — por consultor × projeto, no mês:
 *   receita = horas apontadas × valor/hora DO PROJETO (o que o projeto fatura/hora)
 *   custo   = horas apontadas × valor/hora DO CONSULTOR (efetivo; mensalista = salário ÷ 160)
 *   margem  = receita − custo ; margem% = margem / receita.
 */
class RelatorioRentabilidadeController extends Controller
{
    public function rentabilidade(Request $request, string $yearMonth): JsonResponse
    {
        [$y, $m] = array_map('intval', explode('-', $yearMonth));
        $from = Carbon::create($y, $m, 1)->startOfMonth()->toDateString();
        $to   = Carbon::create($y, $m, 1)->endOfMonth()->toDateString();

        $timesheets = Timesheet::with([
                'user:id,name,hourly_rate,rate_type,partner_id',
                'user.partner:id,pricing_type,hourly_rate',
                'project:id,name,hourly_rate,customer_id',
                'project.customer:id,name',
            ])
            ->whereBetween('date', [$from, $to])
            // Mesma regra do faturamento (fechamento cliente): tudo que é cobrado,
            // exceto ajuste/rejeitado/conflito/interno/atraso (inclui 'liberado').
            ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL, Timesheet::STATUS_LATE])
            ->where('is_billable_only', false)
            ->where('is_internal_action', false)
            ->get();

        // Metadados de custo por consultor: ['eff' => custo/hora, 'type' => hourly|monthly,
        // 'salary' => salário mensal cheio quando monthly (0 caso contrário)].
        $costMetaCache = [];
        $costMeta = function ($user) use (&$costMetaCache, $from, $yearMonth) {
            if (!$user) return ['eff' => 0.0, 'type' => 'hourly', 'salary' => 0.0];
            if (isset($costMetaCache[$user->id])) return $costMetaCache[$user->id];

            // Consultor vinculado a parceiro herda o valor/hora DO PARCEIRO na competência
            // quando o parceiro é de valor fixo. Se o parceiro for "por consultor"
            // (pricing_type 'variable'), usa o valor do próprio consultor (regra abaixo).
            if ($user->partner_id && $user->partner && $user->partner->pricing_type === Partner::PRICING_FIXED) {
                $r = (float) $user->partner->hourlyRateForCompetencia($yearMonth);
                return $costMetaCache[$user->id] = ['eff' => $r, 'type' => 'hourly', 'salary' => 0.0];
            }

            $hist = UserHourlyRateLog::effectiveValuesAt($user->id, $user, $from);
            $rate = (float) ($hist['hourly_rate'] ?? $user->hourly_rate ?? 0);
            $type = $hist['rate_type'] ?? $user->rate_type ?? 'hourly';
            $eff  = ($type === 'monthly' && $rate > 0) ? round($rate / 160, 4) : $rate;
            return $costMetaCache[$user->id] = ['eff' => $eff, 'type' => $type, 'salary' => ($type === 'monthly' ? $rate : 0.0)];
        };
        $costRate = fn ($user) => $costMeta($user)['eff'];

        $groups = [];
        $porDia = []; // horas apontadas por dia × consultor × projeto (p/ gráfico diário no FE)
        foreach ($timesheets as $ts) {
            if (!$ts->project) continue;
            $key = $ts->user_id . ':' . $ts->project_id;
            $horas    = round($ts->effort_minutes / 60, 4);
            $rateProj = (float) ($ts->project->hourly_rate ?? 0);
            $meta     = $costMeta($ts->user);
            $rateCons = $meta['eff'];

            $dia = Carbon::parse($ts->date)->toDateString();
            $dk  = $dia . ':' . $ts->user_id . ':' . $ts->project_id;
            if (!isset($porDia[$dk])) {
                $porDia[$dk] = ['dia' => $dia, 'user_id' => $ts->user_id, 'project_id' => $ts->project_id, 'cliente' => $ts->project->customer->name ?? '—', 'horas' => 0.0];
            }
            $porDia[$dk]['horas'] += $horas;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'user_id'              => $ts->user_id,
                    'consultor'            => $ts->user->name ?? '—',
                    'project_id'           => $ts->project_id,
                    'projeto'              => $ts->project->name ?? '—',
                    'cliente'              => $ts->project->customer->name ?? '—',
                    'valor_hora_projeto'   => round($rateProj, 2),
                    'valor_hora_consultor' => round($rateCons, 2),
                    'rate_type'            => $meta['type'],
                    'custo_fixo_mes'       => round($meta['salary'], 2), // salário mensal cheio (monthly)
                    'horas'                => 0.0,
                    'receita'              => 0.0,
                    'custo'                => 0.0,
                ];
            }
            $groups[$key]['horas']   += $horas;
            $groups[$key]['receita'] += $horas * $rateProj;
            $groups[$key]['custo']   += $horas * $rateCons;
        }

        $rows = array_map(function ($g) {
            $g['horas']   = round($g['horas'], 2);
            $g['receita'] = round($g['receita'], 2);
            $g['custo']   = round($g['custo'], 2);
            $g['margem']  = round($g['receita'] - $g['custo'], 2);
            $g['margem_pct'] = $g['receita'] > 0 ? round($g['margem'] / $g['receita'] * 100, 1) : null;
            return $g;
        }, array_values($groups));

        usort($rows, fn ($a, $b) => strcasecmp($a['consultor'], $b['consultor']) ?: strcasecmp($a['projeto'], $b['projeto']));

        $porDia = array_map(fn ($d) => [
            'dia'        => $d['dia'],
            'user_id'    => $d['user_id'],
            'project_id' => $d['project_id'],
            'cliente'    => $d['cliente'],
            'horas'      => round($d['horas'], 2),
        ], array_values($porDia));

        return response()->json(['data' => ['rows' => $rows, 'por_dia' => $porDia]]);
    }

    /**
     * Rentabilidade POR CLIENTE no mês, cruzada com o RECEBIMENTO do Keruak por CNPJ.
     * O recebimento é sempre do mês SEGUINTE ao do apontamento (trabalha no mês M,
     * cobra/recebe no M+1): apontamentos de maio ↔ recebimento de junho no Keruak.
     */
    public function clientes(Request $request, string $yearMonth): JsonResponse
    {
        [$y, $m] = array_map('intval', explode('-', $yearMonth));
        $from = Carbon::create($y, $m, 1)->startOfMonth()->toDateString();
        $to   = Carbon::create($y, $m, 1)->endOfMonth()->toDateString();
        $recebMonth = Carbon::create($y, $m, 1)->addMonth()->format('Y-m'); // M+1

        $timesheets = Timesheet::with([
                'user:id,name,hourly_rate,rate_type,partner_id',
                'user.partner:id,pricing_type,hourly_rate',
                'project:id,name,hourly_rate,customer_id,is_investimento_comercial',
                'project.customer:id,name,cgc,secondary_cgcs,executive_id',
                'project.customer.executive:id,name',
                // Investimento: o custo é atribuído ao CLIENTE REAL (projeto real do apontamento).
                'realProject:id,name,hourly_rate,customer_id',
                'realProject.customer:id,name,cgc,secondary_cgcs,executive_id',
                'realProject.customer.executive:id,name',
            ])
            ->whereBetween('date', [$from, $to])
            // Mesma regra do faturamento (fechamento cliente): tudo que é cobrado,
            // exceto ajuste/rejeitado/conflito/interno/atraso (inclui 'liberado').
            ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL, Timesheet::STATUS_LATE])
            ->where('is_billable_only', false)
            ->where('is_internal_action', false)
            ->get();

        $costRateCache = [];
        $costRate = function ($user) use (&$costRateCache, $from, $yearMonth) {
            if (!$user) return 0.0;
            if (isset($costRateCache[$user->id])) return $costRateCache[$user->id];
            if ($user->partner_id && $user->partner && $user->partner->pricing_type === Partner::PRICING_FIXED) {
                return $costRateCache[$user->id] = (float) $user->partner->hourlyRateForCompetencia($yearMonth);
            }
            $hist = UserHourlyRateLog::effectiveValuesAt($user->id, $user, $from);
            $rate = (float) ($hist['hourly_rate'] ?? $user->hourly_rate ?? 0);
            $type = $hist['rate_type'] ?? $user->rate_type ?? 'hourly';
            $eff  = ($type === 'monthly' && $rate > 0) ? round($rate / 160, 4) : $rate;
            return $costRateCache[$user->id] = $eff;
        };

        // Agrega por cliente (com CNPJ p/ casar com o Keruak).
        $byCustomer = [];
        // Inicializa o bucket do cliente (usado tanto por apontamento quanto por despesa).
        $ensureCustomer = function (int $cid, $proj) use (&$byCustomer) {
            if (isset($byCustomer[$cid])) return;
            // Todos os CNPJs do cliente (principal + secundários) p/ UNIR o
            // recebimento do Keruak de clientes faturados em mais de um CNPJ.
            $cnpjs = collect([$proj->customer->cgc])
                ->merge((array) ($proj->customer->secondary_cgcs ?? []))
                ->map(fn ($c) => preg_replace('/\D/', '', (string) $c))
                ->filter()->unique()->values()->all();
            $byCustomer[$cid] = [
                'customer_id' => $cid,
                'cliente'     => $proj->customer->name ?? '—',
                'executivo'   => $proj->customer->executive->name ?? null,
                'cnpj'        => preg_replace('/\D/', '', (string) ($proj->customer->cgc ?? '')),
                'cnpjs'       => $cnpjs,
                'horas'       => 0.0,
                'receita'     => 0.0,
                'custo'       => 0.0,
                'investimento_mo'   => 0.0, // mão de obra de apontamentos de investimento
                'investimento_desp' => 0.0, // despesas de projetos de investimento
                'consultores' => [],
                'despesas'    => ['custo' => 0.0, 'projetos' => []],
            ];
        };
        foreach ($timesheets as $ts) {
            if (!$ts->project) continue;
            // Apontamento de INVESTIMENTO: o custo vai para o CLIENTE REAL (projeto real do
            // apontamento), não para a ERPSERV (dona do projeto de investimento). Sem projeto
            // real, mantém o cliente do próprio projeto.
            $proj = ($ts->project->is_investimento_comercial && $ts->real_project_id && $ts->realProject && $ts->realProject->customer)
                ? $ts->realProject
                : $ts->project;
            if (!$proj->customer) continue;
            $cid = $proj->customer_id;
            $ensureCustomer($cid, $proj);
            $horas = round($ts->effort_minutes / 60, 4);
            $rateCons = $costRate($ts->user);
            $byCustomer[$cid]['horas']   += $horas;
            $byCustomer[$cid]['receita'] += $horas * (float) ($proj->hourly_rate ?? 0);
            $byCustomer[$cid]['custo']   += $horas * $rateCons;
            // Breakdown por consultor dentro do cliente (p/ a margem do consultor na expansão).
            $uid = $ts->user_id;
            if (!isset($byCustomer[$cid]['consultores'][$uid])) {
                $byCustomer[$cid]['consultores'][$uid] = [
                    'user_id'          => $uid,
                    'consultor'        => $ts->user->name ?? '—',
                    'valor_hora'       => round($rateCons, 2),
                    'horas'            => 0.0,
                    'custo'            => 0.0,
                    'tem_investimento' => false,
                    'projetos'         => [],
                ];
            }
            $byCustomer[$cid]['consultores'][$uid]['horas'] += $horas;
            $byCustomer[$cid]['consultores'][$uid]['custo'] += $horas * $rateCons;
            // Breakdown por PROJETO que o consultor atuou (3º nível da expansão).
            // Projeto efetivamente apontado ($ts->project): em investimento é o projeto
            // de investimento (o trabalho real), atribuído a este cliente via projeto real.
            $isInvest = (bool) $ts->project->is_investimento_comercial;
            if ($isInvest) {
                $byCustomer[$cid]['consultores'][$uid]['tem_investimento'] = true;
                $byCustomer[$cid]['investimento_mo'] += $horas * $rateCons;
            }
            $pid = $ts->project_id;
            if (!isset($byCustomer[$cid]['consultores'][$uid]['projetos'][$pid])) {
                $byCustomer[$cid]['consultores'][$uid]['projetos'][$pid] = [
                    'project_id'      => $pid,
                    'projeto'         => $ts->project->name ?? '—',
                    'horas'           => 0.0,
                    'custo'           => 0.0,
                    'is_investimento' => $isInvest,
                ];
            }
            $byCustomer[$cid]['consultores'][$uid]['projetos'][$pid]['horas'] += $horas;
            $byCustomer[$cid]['consultores'][$uid]['projetos'][$pid]['custo'] += $horas * $rateCons;
        }

        // DESPESAS (aprovadas + pendentes) entram no CUSTO, mapeadas ao cliente como o
        // apontamento (projeto → cliente; investimento → cliente real). Aparecem numa
        // linha "Despesas" separada no detalhamento (não misturam com mão de obra).
        $expenses = \App\Models\Expense::with([
                'user:id,name',
                'project:id,name,customer_id,is_investimento_comercial',
                'project.customer:id,name,cgc,secondary_cgcs,executive_id',
                'project.customer.executive:id,name',
                'realProject:id,name,customer_id',
                'realProject.customer:id,name,cgc,secondary_cgcs,executive_id',
                'realProject.customer.executive:id,name',
            ])
            ->whereBetween('expense_date', [$from, $to])
            ->whereIn('status', [\App\Models\Expense::STATUS_APPROVED, \App\Models\Expense::STATUS_PENDING])
            ->get();

        foreach ($expenses as $exp) {
            if (!$exp->project) continue;
            $proj = ($exp->project->is_investimento_comercial && $exp->real_project_id && $exp->realProject && $exp->realProject->customer)
                ? $exp->realProject
                : $exp->project;
            if (!$proj->customer) continue;
            $cid = $proj->customer_id;
            $ensureCustomer($cid, $proj);

            $amount = (float) $exp->amount;
            $byCustomer[$cid]['custo']               += $amount;
            $byCustomer[$cid]['despesas']['custo']   += $amount;

            $isInvest = (bool) $exp->project->is_investimento_comercial;
            if ($isInvest) {
                $byCustomer[$cid]['investimento_desp'] += $amount;
            }
            $pid = $exp->project_id;
            if (!isset($byCustomer[$cid]['despesas']['projetos'][$pid])) {
                $byCustomer[$cid]['despesas']['projetos'][$pid] = [
                    'project_id'      => $pid,
                    'projeto'         => $exp->project->name ?? '—',
                    'custo'           => 0.0,
                    'is_investimento' => $isInvest,
                    'usuarios'        => [],
                ];
            }
            $byCustomer[$cid]['despesas']['projetos'][$pid]['custo'] += $amount;

            // Quem apontou a despesa, agrupado DENTRO do respectivo projeto.
            $euid = $exp->user_id;
            if ($euid) {
                if (!isset($byCustomer[$cid]['despesas']['projetos'][$pid]['usuarios'][$euid])) {
                    $byCustomer[$cid]['despesas']['projetos'][$pid]['usuarios'][$euid] = [
                        'user_id' => $euid,
                        'usuario' => $exp->user->name ?? '—',
                        'custo'   => 0.0,
                    ];
                }
                $byCustomer[$cid]['despesas']['projetos'][$pid]['usuarios'][$euid]['custo'] += $amount;
            }
        }

        // ?refresh=1 (botão "Atualizar Keruak"): ignora o cache de 3h e busca ao vivo.
        $keruak = app(\App\Services\KeruakRentabilidadeService::class)->recebido($request->boolean('refresh'));

        $rows = [];
        $usados = [];
        foreach ($byCustomer as $g) {
            $cnpj = $g['cnpj'];
            // Soma o recebido de TODOS os CNPJs do cliente (principal + secundários).
            $recebido = 0.0;
            foreach ($g['cnpjs'] as $cc) {
                $usados[$cc] = true;
                $recebido += (float) ($keruak[$cc]['receb'][$recebMonth] ?? 0);
            }

            $horas = round($g['horas'], 2);
            $receita = round($g['receita'], 2);
            $custo = round($g['custo'], 2);
            $margem = round($receita - $custo, 2);
            $margemReal = round($recebido - $custo, 2);
            $rows[] = [
                'customer_id'     => $g['customer_id'],
                'cliente'         => $g['cliente'],
                'executivo'       => $g['executivo'],
                'cnpj'            => $cnpj,
                'horas'           => $horas,
                'receita'         => $receita,
                'custo'           => $custo,
                'margem'          => $margem,
                'margem_pct'      => $receita > 0 ? round($margem / $receita * 100, 1) : null,
                'recebido'        => round($recebido, 2),
                'margem_real'     => $margemReal,
                'margem_real_pct' => $recebido > 0 ? round($margemReal / $recebido * 100, 1) : null,
                'no_minutor'      => true,
                'consultores'     => array_map(fn ($c) => [
                    'user_id'          => $c['user_id'],
                    'consultor'        => $c['consultor'],
                    'valor_hora'       => $c['valor_hora'],
                    'horas'            => round($c['horas'], 2),
                    'custo'            => round($c['custo'], 2),
                    'tem_investimento' => $c['tem_investimento'],
                    'projetos'         => array_map(fn ($p) => [
                        'project_id'      => $p['project_id'],
                        'projeto'         => $p['projeto'],
                        'horas'           => round($p['horas'], 2),
                        'custo'           => round($p['custo'], 2),
                        'is_investimento' => $p['is_investimento'],
                    ], array_values($c['projetos'])),
                ], array_values($g['consultores'])),
                'investimento_mo'   => round($g['investimento_mo'], 2),
                'investimento_desp' => round($g['investimento_desp'], 2),
                'despesas'        => [
                    'custo'    => round($g['despesas']['custo'], 2),
                    'projetos' => array_map(fn ($p) => [
                        'project_id'      => $p['project_id'],
                        'projeto'         => $p['projeto'],
                        'custo'           => round($p['custo'], 2),
                        'is_investimento' => $p['is_investimento'],
                        'usuarios'        => array_map(fn ($u) => [
                            'user_id' => $u['user_id'],
                            'usuario' => $u['usuario'],
                            'custo'   => round($u['custo'], 2),
                        ], array_values($p['usuarios'] ?? [])),
                    ], array_values($g['despesas']['projetos'])),
                ],
            ];
        }

        // Mapa global CNPJ→cliente (principal + secundários) de TODOS os clientes cadastrados.
        // Necessário pra casar o recebimento do Keruak com clientes que NÃO tiveram movimento
        // no mês M (não entram em $byCustomer) — senão o recebido deles (inclusive sob CNPJ
        // adicional cadastrado) cairia indevidamente como "fora do Minutor".
        $cnpjToCustomer = [];
        \App\Models\Customer::with('executive:id,name')
            ->get(['id', 'name', 'cgc', 'secondary_cgcs', 'executive_id'])
            ->each(function ($c) use (&$cnpjToCustomer) {
                collect([$c->cgc])->merge((array) ($c->secondary_cgcs ?? []))
                    ->map(fn ($x) => preg_replace('/\D/', '', (string) $x))
                    ->filter()->unique()
                    ->each(function ($cc) use (&$cnpjToCustomer, $c) {
                        if (!isset($cnpjToCustomer[$cc])) {
                            $cnpjToCustomer[$cc] = [
                                'customer_id' => $c->id,
                                'cliente'     => $c->name,
                                'executivo'   => $c->executive->name ?? null,
                            ];
                        }
                    });
            });

        // Recebimento no M+1 SEM apontamento Minutor no mês M:
        //  - CNPJ casa com cliente cadastrado (principal/adicional) → consolida no cliente (no_minutor=true);
        //  - CNPJ não cadastrado em ninguém → "fora do Minutor".
        $extraByCustomer = [];
        foreach ($keruak as $cnpj => $info) {
            if (isset($usados[$cnpj])) continue;
            $recebido = (float) ($info['receb'][$recebMonth] ?? 0);
            if ($recebido <= 0) continue;
            $usados[$cnpj] = true;

            $match = $cnpjToCustomer[$cnpj] ?? null;
            if ($match) {
                $cid = $match['customer_id'];
                if (!isset($extraByCustomer[$cid])) {
                    $extraByCustomer[$cid] = ['info' => $match, 'cnpj' => $cnpj, 'recebido' => 0.0];
                }
                $extraByCustomer[$cid]['recebido'] += $recebido;
                continue;
            }

            $rows[] = [
                'customer_id'     => null,
                'cliente'         => $info['name'] ?: '(fora do Minutor)',
                'executivo'       => null,
                'cnpj'            => $cnpj,
                'horas'           => 0.0,
                'receita'         => 0.0,
                'custo'           => 0.0,
                'margem'          => 0.0,
                'margem_pct'      => null,
                'recebido'        => round($recebido, 2),
                'margem_real'     => round($recebido, 2),
                'margem_real_pct' => 100.0,
                'no_minutor'      => false,
                'consultores'     => [],
            ];
        }

        // Clientes CADASTRADOS sem movimento no mês, mas com recebido (consolidado por cliente).
        foreach ($extraByCustomer as $cid => $e) {
            $recebido = round($e['recebido'], 2);
            $rows[] = [
                'customer_id'     => $cid,
                'cliente'         => $e['info']['cliente'],
                'executivo'       => $e['info']['executivo'],
                'cnpj'            => $e['cnpj'],
                'horas'           => 0.0,
                'receita'         => 0.0,
                'custo'           => 0.0,
                'margem'          => 0.0,
                'margem_pct'      => null,
                'recebido'        => $recebido,
                'margem_real'     => $recebido,
                'margem_real_pct' => $recebido > 0 ? 100.0 : null,
                'no_minutor'      => true,
                'consultores'     => [],
            ];
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['cliente'], $b['cliente']));

        return response()->json(['data' => ['rows' => $rows, 'receb_month' => $recebMonth]]);
    }

    /**
     * Drill-down do "Valor Recebido": títulos do Keruak que compõem o valor de um
     * cliente. Recebe os CNPJs do cliente (principal + secundários) e os meses de
     * recebimento (YYYY-MM) que a tela está somando, garantindo que o total bata
     * com a célula.
     *
     * Query: ?cnpjs=11111111000111,22222222000122&receb=2026-02,2026-03
     */
    public function keruakTitulos(Request $request): JsonResponse
    {
        $cnpjs = array_filter(array_map(
            fn ($c) => preg_replace('/\D/', '', (string) $c),
            explode(',', (string) $request->query('cnpjs', ''))
        ));
        $receb = array_filter(array_map('trim', explode(',', (string) $request->query('receb', ''))));

        if (empty($cnpjs)) {
            return response()->json(['data' => ['titulos' => [], 'total' => 0]]);
        }

        $result = app(\App\Services\KeruakRentabilidadeService::class)
            ->titulos($cnpjs, $receb, $request->boolean('refresh'));

        return response()->json(['data' => $result]);
    }

    /**
     * Ajustes iniciais (custo/receita) por cliente do ANO. Mapa { customer_id: {custo_inicial, receita_inicial} }.
     * O FE soma esses valores UMA vez no agregado anual (a visão anual é a soma dos meses no FE).
     */
    public function initials(int $year): JsonResponse
    {
        $map = [];
        foreach (\App\Models\CustomerRentabInitial::where('year', $year)->get(['customer_id', 'custo_inicial', 'receita_inicial']) as $r) {
            $map[$r->customer_id] = [
                'custo_inicial'   => (float) $r->custo_inicial,
                'receita_inicial' => (float) $r->receita_inicial,
            ];
        }
        return response()->json(['data' => $map]);
    }

    /**
     * Upsert do ajuste inicial de um cliente em um ano.
     */
    public function saveInitial(Request $request): JsonResponse
    {
        $v = $request->validate([
            'customer_id'     => 'required|exists:customers,id',
            'year'            => 'required|integer|min:2000|max:2100',
            'custo_inicial'   => 'nullable|numeric|min:0',
            'receita_inicial' => 'nullable|numeric|min:0',
        ]);

        $rec = \App\Models\CustomerRentabInitial::updateOrCreate(
            ['customer_id' => $v['customer_id'], 'year' => $v['year']],
            ['custo_inicial' => $v['custo_inicial'] ?? 0, 'receita_inicial' => $v['receita_inicial'] ?? 0],
        );

        return response()->json(['ok' => true, 'data' => [
            'customer_id'     => $rec->customer_id,
            'custo_inicial'   => (float) $rec->custo_inicial,
            'receita_inicial' => (float) $rec->receita_inicial,
        ]]);
    }
}
