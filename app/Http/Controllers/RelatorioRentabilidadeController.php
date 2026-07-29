<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
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
        // Por padrão o mês inteiro; o FE pode passar ?from/?to (YYYY-MM-DD) p/ filtrar por DIA
        // (clampado a este mês pelo FE). A competência (rates) continua sendo $yearMonth.
        $from = $request->query('from') ?: Carbon::create($y, $m, 1)->startOfMonth()->toDateString();
        $to   = $request->query('to')   ?: Carbon::create($y, $m, 1)->endOfMonth()->toDateString();

        $timesheets = Timesheet::with([
                'user:id,name,hourly_rate,rate_type,partner_id,type,coordinator_type,is_bizify,is_diretor,is_diretor_projetos',
                'user.partner:id,pricing_type,hourly_rate',
                'project:id,name,hourly_rate,customer_id,is_investimento_comercial,service_type_id,contract_type_id',
                'project.customer:id,name',
                'project.serviceType:id,name',
                'project.contractType:id,name',
            ])
            ->whereBetween('date', [$from, $to])
            // Mesma regra do faturamento (fechamento cliente): tudo que é cobrado,
            // exceto ajuste/rejeitado/conflito/interno/atraso (inclui 'liberado').
            ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL, Timesheet::STATUS_LATE])
            ->where('is_billable_only', false)
            ->where('is_internal_action', false)
            ->whereNotIn('user_id', $this->rentabHiddenUserIds()) // oculta usuários da Rentabilidade (config admin) — fora dos dados E dos totais
            ->get();

        // Metadados de custo por consultor: ['eff' => custo/hora, 'type' => hourly|monthly,
        // 'salary' => salário mensal cheio quando monthly (0 caso contrário)].
        $costMetaCache = [];
        $costMeta = function ($user) use (&$costMetaCache, $from, $yearMonth) {
            if (!$user) return ['eff' => 0.0, 'type' => 'hourly', 'salary' => 0.0, 'ctype' => null];
            if (isset($costMetaCache[$user->id])) return $costMetaCache[$user->id];

            // Consultor vinculado a parceiro herda o valor/hora DO PARCEIRO na competência
            // quando o parceiro é de valor fixo. Se o parceiro for "por consultor"
            // (pricing_type 'variable'), usa o valor do próprio consultor (regra abaixo).
            if ($user->partner_id && $user->partner && $user->partner->pricing_type === Partner::PRICING_FIXED) {
                $r = (float) $user->partner->hourlyRateForCompetencia($yearMonth);
                return $costMetaCache[$user->id] = ['eff' => $r, 'type' => 'hourly', 'salary' => 0.0, 'ctype' => $user->consultant_type];
            }

            $hist = UserHourlyRateLog::effectiveValuesAt($user->id, $user, $from);
            $rate = (float) ($hist['hourly_rate'] ?? $user->hourly_rate ?? 0);
            $type = $hist['rate_type'] ?? $user->rate_type ?? 'hourly';
            $ctype = $hist['consultant_type'] ?? $user->consultant_type;
            $eff  = ($type === 'monthly' && $rate > 0) ? round($rate / 160, 4) : $rate;
            return $costMetaCache[$user->id] = ['eff' => $eff, 'type' => $type, 'salary' => ($type === 'monthly' ? $rate : 0.0), 'ctype' => $ctype];
        };
        $costRate = fn ($user) => $costMeta($user)['eff'];

        $groups = [];
        $porDia = []; // horas apontadas por dia × consultor × projeto (p/ gráfico diário no FE)
        foreach ($timesheets as $ts) {
            if (!$ts->project) continue;
            $key = $ts->user_id . ':' . $ts->project_id;
            // Horas do lado CLIENTE (receita + exibição) = com uplift do contrato.
            // Horas REAIS = pro CUSTO do consultor (o acréscimo tem custo ZERO).
            $horasReal = round($ts->effort_minutes / 60, 4);
            $horas     = round($ts->billableMinutes() / 60, 4);
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
                // Seção "Recebe Fixo" do FE NÃO mostra coordenador/diretor/Bizify (são
                // tratados em outras rotinas: Fechamento Diretoria, aba Bizify, etc).
                $u = $ts->user;
                $fixoExcluir = $u && (
                    $u->is_bizify || $u->is_diretor || $u->is_diretor_projetos
                    || $u->type === 'coordenador' || !empty($u->coordinator_type)
                );
                // Categoria do projeto: Cloud/Bizify/Sustentação → 'sustentacao'; demais → 'projeto'.
                $svcName = strtolower((string) ($ts->project->serviceType->name ?? ''));
                $ctName  = strtolower((string) ($ts->project->contractType->name ?? ''));
                $isSust  = str_contains($svcName, 'sustenta') || str_contains($svcName, 'bizify') || str_contains($ctName, 'cloud');
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
                    'fixo_excluir'         => $fixoExcluir, // coordenador/diretor/Bizify → fora da seção Recebe Fixo
                    'is_investimento'      => (bool) $ts->project->is_investimento_comercial, // projeto de investimento (receita 0, em evidência)
                    'categoria'            => $isSust ? 'sustentacao' : 'projeto', // Cloud/Bizify/Sustentação = sustentacao
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

        // Feriados ativos no período → dias não úteis (FE pinta fim de semana/feriado).
        $feriados = Holiday::whereBetween('date', [$from, $to])->where('active', true)
            ->pluck('date')->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))->all();

        $porDia = array_map(fn ($d) => [
            'dia'        => $d['dia'],
            'user_id'    => $d['user_id'],
            'project_id' => $d['project_id'],
            'cliente'    => $d['cliente'],
            'horas'      => round($d['horas'], 2),
            'nao_util'   => Carbon::parse($d['dia'])->isWeekend() || in_array($d['dia'], $feriados), // fim de semana ou feriado
        ], array_values($porDia));

        // Consultores que RECEBEM FIXO (salário mensal) SEM apontamento no período ainda
        // contam custo — o salário roda independente de horas. Sem isto, um fixo que não
        // apontou no mês sumia da seção "Recebe Fixo". Retornados à PARTE (fixos_zerados)
        // p/ não poluir a tabela/gráfico; o FE só os mescla na seção Recebe Fixo
        // (receita 0, custo = salário, resultado negativo).
        $jaNoRelatorio = [];
        foreach ($groups as $g) { $jaNoRelatorio[$g['user_id']] = true; }

        // Fixos SEM apontamento: quem entra é decidido SÓ pela config Visibilidade (ocultos) —
        // inclui coordenador/diretor/Bizify (não são mais excluídos). Precisa ter salário mensal (checado no loop).
        $fixosQuery = \App\Models\User::query()
            ->where('enabled', true)
            ->where('type', '!=', 'cliente')
            ->whereNotIn('id', $this->rentabHiddenUserIds()) // oculta usuários da Rentabilidade (config admin)
            ->with('partner:id,pricing_type,hourly_rate');
        // Mesma empresa dos apontamentos (Timesheet é escopado por empresa quando o
        // multi-empresa está ligado): filtra os fixos pela empresa da folha ativa.
        if (config('multiempresa.scoping_enabled')) {
            $cid = app(\App\Services\CompanyContext::class)->id();
            if ($cid) { $fixosQuery->where('home_company_id', $cid); }
        }

        $fixosZerados = [];
        foreach ($fixosQuery->get() as $u) {
            if (isset($jaNoRelatorio[$u->id])) continue; // já aparece (tem apontamento)
            $meta = $costMeta($u);
            if ($meta['type'] !== 'monthly' || $meta['salary'] <= 0) continue; // só recebe fixo com salário
            $fixosZerados[] = [
                'user_id'        => $u->id,
                'consultor'      => $u->name,
                'custo_fixo_mes' => round($meta['salary'], 2),
            ];
        }

        // Hora extra do BANCO DE HORAS — mesma conta do Fechamento do Consultor:
        // horas extras pagas (HourBankService) × (salário/160). Só p/ 'banco_de_horas';
        // fixo/horista NÃO entram aqui. O FE soma isto no Custo Fixo do consultor.
        $monthlyIds = [];
        foreach ($groups as $g) { if (($g['rate_type'] ?? '') === 'monthly') $monthlyIds[$g['user_id']] = true; }
        foreach ($fixosZerados as $z) { $monthlyIds[$z['user_id']] = true; }
        $fixosExtras = [];
        if (!empty($monthlyIds)) {
            $bankSvc = app(\App\Services\HourBankService::class);
            foreach (\App\Models\User::whereIn('id', array_keys($monthlyIds))->get() as $u) {
                $meta = $costMeta($u);
                if (($meta['ctype'] ?? null) !== 'banco_de_horas' || $meta['salary'] <= 0) continue;
                $extraTs = Timesheet::whereBetween('date', [$from, $to])
                    ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL, Timesheet::STATUS_LATE])
                    ->whereNull('deleted_at')->where('is_billable_only', false)->where('is_internal_action', false)
                    ->whereNotNull('consultant_extra_pct')->where('user_id', $u->id)
                    ->get(['effort_minutes', 'consultant_extra_pct']);
                $extraHoursForBank = round($extraTs->sum(fn ($t) => ($t->effort_minutes / 60) * ((float) $t->consultant_extra_pct / 100)), 2);
                $startDate = $u->bank_hours_start_date ? $u->bank_hours_start_date->format('Y-m-d') : null;
                $calc = $bankSvc->calculateMonth($u->id, $y, $m, (float) ($u->daily_hours ?? 8.0), $startDate, $extraHoursForBank);
                $valorHoraExtra = round($meta['salary'] / 160, 4);
                $overtime = round((float) ($calc['paid_hours'] ?? 0) * $valorHoraExtra, 2);
                if ($overtime != 0.0) { $fixosExtras[] = ['user_id' => $u->id, 'extra_cost' => $overtime]; }
            }
        }

        return response()->json(['data' => ['rows' => $rows, 'por_dia' => $porDia, 'fixos_zerados' => $fixosZerados, 'fixos_extras' => $fixosExtras]]);
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
            // Exibição + receita = horas COM uplift; custo = horas REAIS (acréscimo custo zero).
            $horasReal = round($ts->effort_minutes / 60, 4);
            $horas = round($ts->billableMinutes() / 60, 4);
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

    /**
     * Clientes SEM recebimento recente (churn) a partir dos dados do Keruak.
     * Usa o "Mês-Ano Recebimento" de cada título; lista clientes cujo último
     * recebimento está há >= N meses do mês de referência (default 2).
     */
    public function atividadeClientes(Request $request): JsonResponse
    {
        // Limiares parametrizáveis (Configurador): Ativo até N meses; Inativo a partir de M.
        // A faixa entre eles é "Inativando" (ex.: ativo_ate=4, inativo_a_partir=6 → 5 = inativando).
        $ativoAte = max(0, (int) \App\Models\SystemSetting::get('cliente_status_ativo_ate', 4));
        $inativoApartir = max($ativoAte + 1, (int) \App\Models\SystemSetting::get('cliente_status_inativo_a_partir', 6));
        $ref = Carbon::now()->startOfMonth();
        $refIdx = $ref->year * 12 + $ref->month;

        $map = app(\App\Services\KeruakRentabilidadeService::class)->recebido($request->boolean('refresh'));

        // CNPJ (só dígitos) -> cliente Minutor (nome + executivo), via cgc + secundários.
        $custByCnpj = [];
        \App\Models\Customer::with('executive:id,name')
            ->get(['id', 'name', 'cgc', 'secondary_cgcs', 'executive_id'])
            ->each(function ($c) use (&$custByCnpj) {
                $cnpjs = collect([$c->cgc])->merge((array) ($c->secondary_cgcs ?? []))
                    ->map(fn ($x) => preg_replace('/\D/', '', (string) $x))->filter()->unique();
                foreach ($cnpjs as $cnpj) {
                    $custByCnpj[$cnpj] = ['cliente' => $c->name, 'executivo' => $c->executive->name ?? null, 'customer_id' => $c->id];
                }
            });

        // Unifica os CNPJs do MESMO cliente numa linha só (igual à Rentabilidade por
        // cliente): quem casa por customer_id agrupa; CNPJ sem cliente fica avulso.
        $groups = [];
        foreach ($map as $cnpj => $info) {
            $cust = $custByCnpj[$cnpj] ?? null;
            $key = $cust ? 'c' . $cust['customer_id'] : 'x' . $cnpj;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'cnpj' => $cnpj,
                    'cliente' => $cust['cliente'] ?? ($info['name'] ?? '—'),
                    'executivo' => $cust['executivo'] ?? null,
                    'no_minutor' => (bool) $cust,
                    'meses' => [],
                    'titulos' => [],
                    'receb_total' => 0.0,
                ];
            }
            $groups[$key]['receb_total'] += array_sum($info['receb'] ?? []);
            foreach ($info['titulos'] ?? [] as $t) {
                $groups[$key]['titulos'][] = $t;
            }
            // "Mês-Ano Recebimento": o mapa `receb` já é indexado por mês de recebimento.
            foreach (array_keys($info['receb'] ?? []) as $rm) {
                $groups[$key]['meses'][] = $rm;
            }
        }

        $rows = [];
        foreach ($groups as $g) {
            $lastReceb = $g['meses'] ? max($g['meses']) : null;
            if (! $lastReceb) {
                continue;
            }
            [$ey, $em] = array_map('intval', explode('-', $lastReceb));
            $mesesInativo = $refIdx - ($ey * 12 + $em);
            // Traz TODOS os clientes; os limiares definem o status em 3 faixas.
            $status = $mesesInativo <= $ativoAte
                ? 'ativo'
                : ($mesesInativo >= $inativoApartir ? 'inativo' : 'inativando');
            // Último valor recebido (títulos do último mês de recebimento).
            $lastValor = 0.0;
            foreach ($g['titulos'] as $t) {
                if (($t['recebimento'] ?? null) === $lastReceb) {
                    $lastValor += (float) $t['valor'];
                }
            }
            $rows[] = [
                'cnpj' => $g['cnpj'],
                'cliente' => $g['cliente'],
                'executivo' => $g['executivo'],
                'no_minutor' => $g['no_minutor'],
                'ultimo_faturamento' => $lastReceb,
                'meses_inativo' => $mesesInativo,
                'status' => $status,
                'ultimo_valor' => round($lastValor, 2),
                'total_recebido' => round($g['receb_total'], 2),
            ];
        }
        usort($rows, fn ($a, $b) => [$b['meses_inativo'], $b['total_recebido']] <=> [$a['meses_inativo'], $a['total_recebido']]);

        return response()->json([
            'ref' => $ref->format('Y-m'),
            'config' => ['ativo_ate' => $ativoAte, 'inativo_a_partir' => $inativoApartir],
            'total' => count($rows),
            'ativos' => collect($rows)->where('status', 'ativo')->count(),
            'inativando' => collect($rows)->where('status', 'inativando')->count(),
            'inativos' => collect($rows)->where('status', 'inativo')->count(),
            'clientes' => $rows,
        ]);
    }

    /** Config dos limiares de status do cliente (Configurador). */
    public function statusClientesConfig(): JsonResponse
    {
        return response()->json([
            'ativo_ate' => max(0, (int) \App\Models\SystemSetting::get('cliente_status_ativo_ate', 4)),
            'inativo_a_partir' => max(1, (int) \App\Models\SystemSetting::get('cliente_status_inativo_a_partir', 6)),
        ]);
    }

    public function statusClientesConfigUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ativo_ate' => ['required', 'integer', 'min:0', 'max:60'],
            'inativo_a_partir' => ['required', 'integer', 'min:1', 'max:120'],
        ]);
        if ($data['inativo_a_partir'] <= $data['ativo_ate']) {
            return response()->json(['message' => '"Inativo a partir de" deve ser maior que "Ativo até".'], 422);
        }
        \App\Models\SystemSetting::set('cliente_status_ativo_ate', (string) $data['ativo_ate'], 'integer', 'clientes', 'Status Ativo até N meses sem receber');
        \App\Models\SystemSetting::set('cliente_status_inativo_a_partir', (string) $data['inativo_a_partir'], 'integer', 'clientes', 'Status Inativo a partir de N meses sem receber');

        return response()->json(['ok' => true, 'ativo_ate' => $data['ativo_ate'], 'inativo_a_partir' => $data['inativo_a_partir']]);
    }

    /** IDs de usuários ocultados da tela de Rentabilidade (config global do admin). */
    private function rentabHiddenUserIds(): array
    {
        $ids = \App\Models\SystemSetting::get('rentab_hidden_user_ids', []);
        if (is_string($ids)) $ids = json_decode($ids, true);
        return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
    }

    /** Config "quem aparece na Rentabilidade": todos os usuários (menos clientes) + quais estão ocultos. Admin. */
    public function hiddenUsersConfig(Request $request): JsonResponse
    {
        if (!$request->user()?->isAdmin()) abort(403);
        $users = \App\Models\User::where('type', '!=', 'cliente')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'enabled'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'type' => $u->type, 'enabled' => (bool) $u->enabled]);
        return response()->json(['hidden_ids' => $this->rentabHiddenUserIds(), 'users' => $users]);
    }

    /** Salva os usuários ocultados da Rentabilidade (saem da tela e dos totais). Admin. */
    public function hiddenUsersConfigUpdate(Request $request): JsonResponse
    {
        if (!$request->user()?->isAdmin()) abort(403);
        $data = $request->validate([
            'user_ids'   => 'present|array',
            'user_ids.*' => 'integer',
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['user_ids'])));
        \App\Models\SystemSetting::set('rentab_hidden_user_ids', $ids, 'json', 'rentabilidade', 'Usuários ocultados da tela de Rentabilidade');
        return response()->json(['ok' => true, 'hidden_ids' => $ids]);
    }
}
