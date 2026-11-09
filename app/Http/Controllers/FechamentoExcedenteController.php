<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\ExcessHourCharge;
use App\Models\FechamentoSendStatus;
use App\Models\HelpDeskTicket;
use App\Models\Project;
use App\Models\Timesheet;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Fechamento de HORAS EXCEDENTES (BH Mensal / BH Fixo).
 *
 * Rotina do administrativo pra cobrar horas consumidas acima das contratadas.
 *  - BH Mensal: excedente por COMPETÊNCIA (consumo do mês − contratadas do mês).
 *  - BH Fixo:   excedente pelo ESTADO ATUAL (saldo negativo), incremental sobre o
 *               que já foi cobrado (não recobra o mesmo excedente).
 * Valor = horas excedentes × Hora Adicional (additional_hourly_rate). Flag
 * charge_excess_hours (projeto) liga/desliga a cobrança; ajustável na rotina.
 */
class FechamentoExcedenteController extends Controller
{
    use \App\Http\Traits\ListCacheable;

    /** Projetos PAI de BH Mensal ou BH Fixo (não-investimento). */
    private function baseQuery()
    {
        return Project::query()
            ->with(['customer:id,name,company_name', 'contractType:id,name,code', 'hourlyRateChanges', 'soldHoursHistory',
                     'childProjects.contractType', 'hourContributions'])
            ->whereNull('parent_project_id')
            ->where('is_investimento_comercial', false)
            ->whereHas('contractType', function ($q) {
                // Só BH Mensal e BH Fixo entram na apuração de horas excedentes.
                $q->whereIn('code', ['monthly_hours', 'fixed_hours'])
                  ->orWhereRaw('lower(name) in (?, ?)', ['banco de horas mensal', 'banco de horas fixo']);
            });
    }

    /**
     * Apuração da competência.
     *  • BH MENSAL: excedente = saldo acumulado NO FIM da competência (déficit), usando o
     *    extrato mês-a-mês (monthlyStatement). Isso EXCLUI o mês atual/futuro — as horas
     *    mensais do mês corrente NÃO entram na apuração do fechamento. O acerto do que já
     *    foi cobrado em meses anteriores é feito por APORTE (processo interno), NÃO abatido aqui.
     *  • BH FIXO/FECHADO: estado atual (managementBreakdown), incremental sobre o já cobrado.
     */
    private function apuracao(Project $p, string $yearMonth): array
    {
        if ($p->isBankHoursMonthly()) {
            $bal = $this->monthlyBalanceAt($p, $yearMonth);
            return [
                'basis'      => 'monthly',
                'monthly'    => true,
                'contracted' => round($bal['vendidas'] ?? 0.0, 2),
                'consumed'   => round($bal['consumed'] ?? 0.0, 2),
                'excess'     => $bal ? max(0, round(-$bal['balance'], 2)) : 0.0,
                'ym'         => $bal['ym'] ?? $yearMonth,
            ];
        }
        $ap = $p->excessHoursApuracao($yearMonth);
        $ap['monthly'] = false;
        $ap['ym'] = $yearMonth;
        return $ap;
    }

    /**
     * Saldo (déficit/superávit) VIGENTE via extrato mês-a-mês. A rotina não tem seletor de
     * competência: apura o excedente ACUMULADO atual. Pega a linha do $yearMonth pedido; se
     * não existir (ex.: contrato encerrado cujo último mês com dados é anterior), cai na ÚLTIMA
     * linha <= $yearMonth (o estado mais recente). Assim "mês atual" sempre resolve o excedente real.
     */
    private function monthlyBalanceAt(Project $p, string $yearMonth): ?array
    {
        // CORTE NA VIRADA: o mês CORRENTE ainda não fechou — a cota mensal do BH Mensal é incrementada
        // na virada do mês, então apurar com o mês atual (cota nova mascarando o déficit, consumo ainda
        // parcial) gera número falso. Apuramos sempre até a última competência FECHADA (mês anterior ao
        // atual), cortando tanto as horas compradas quanto os apontamentos do mês corrente. Para um
        // $yearMonth já passado, usa ele mesmo; nunca ultrapassa o mês anterior ao atual.
        $ceiling = \Carbon\Carbon::now()->subMonthNoOverflow()->format('Y-m');
        $target  = $yearMonth < $ceiling ? $yearMonth : $ceiling;

        $resp = json_decode(app(ProjectController::class)->monthlyStatement($p)->getContent(), true);
        $pick = null;
        foreach (($resp['rows'] ?? []) as $r) {
            $ym = $r['year_month'] ?? '';
            if ($ym === $target) { $pick = $r; break; }
            if ($ym !== '' && $ym <= $target) { $pick = $r; }   // mantém a última linha <= alvo (mês fechado)
        }
        if (!$pick) { return null; }
        return [
            'balance'  => (float) ($pick['balance_hours'] ?? 0),
            'vendidas' => (float) ($pick['vendidas_hours'] ?? 0),
            'consumed' => (float) ($pick['accumulated_consumption_hours'] ?? 0),
            'ym'       => (string) ($pick['year_month'] ?? $yearMonth),
        ];
    }

    /** Excedente PENDENTE a cobrar. BH Mensal = saldo do mês (acerto via aporte); demais = incremental. */
    private function excessPendente(array $ap, float $jaCobrado): float
    {
        return ($ap['monthly'] ?? false) ? $ap['excess'] : max(0, round($ap['excess'] - $jaCobrado, 2));
    }

    /**
     * Monta as linhas de excedente da competência para uma coleção de projetos.
     * Reusado pela listagem (index) e pelo relatório por cliente.
     */
    private function buildRows(Collection $projects, string $yearMonth): Collection
    {
        $ids = $projects->pluck('id');

        // Total já cobrado por projeto (para o incremental do BH Fixo).
        $charged = ExcessHourCharge::where('status', ExcessHourCharge::STATUS_COBRADO)
            ->whereIn('project_id', $ids)->get()->groupBy('project_id')
            ->map(fn ($g) => (float) $g->sum('excess_hours'));

        // Registros persistidos por projeto. O casamento é pela competência DA APURAÇÃO (não a da
        // requisição): BH Mensal usa o mês do CORTE (última competência fechada), que pode diferir do
        // mês corrente — sem isso o contrato recém-faturado continuava "Pendente" ("não saiu da tela").
        $recordsByProject = ExcessHourCharge::whereIn('project_id', $ids)->get()->groupBy('project_id');

        return $projects->map(function (Project $p) use ($yearMonth, $charged, $recordsByProject) {
            $ap   = $this->apuracao($p, $yearMonth);
            $rate = (float) ($p->additional_hourly_rate ?? 0);
            $rec  = optional($recordsByProject->get($p->id))->firstWhere('year_month', (string) ($ap['ym'] ?? $yearMonth));

            $jaCobrado  = (float) ($charged->get($p->id) ?? 0);
            $excessPend = $this->excessPendente($ap, $jaCobrado);

            $excess = $rec ? (float) $rec->excess_hours : $excessPend;
            $status = $rec?->status ?? ExcessHourCharge::STATUS_PENDENTE;

            return [
                'project_id'      => $p->id,
                'code'            => $p->code,
                'project_name'    => $p->name,
                'customer_id'     => $p->customer_id,
                'customer_name'   => $p->customer?->name ?? '—',
                'basis'           => $ap['basis'],
                'contracted_hours'=> $ap['contracted'],
                'consumed_hours'  => $ap['consumed'],
                'excess_hours'    => round($excess, 2),
                'additional_hourly_rate' => round($rate, 2),
                'excess_value'    => round($excess * $rate, 2),
                'charge'          => (bool) $p->charge_excess_hours,
                'status'          => $status,
                'record_id'       => $rec?->id,
                'closed_at'       => $rec?->closed_at?->toISOString(),
                // Situação do CONTRATO (projeto) — p/ separar "Encerrados" numa aba própria na rotina.
                'project_status'  => $p->status,
                'encerrado'       => in_array($p->status, [Project::STATUS_FINISHED, Project::STATUS_CANCELLED], true),
            ];
        });
    }

    /**
     * GET /fechamento-excedente
     * Lista os contratos com horas excedentes A COBRAR (excedente ACUMULADO vigente). A rotina
     * NÃO tem seletor de competência — apura sempre o estado atual (desde que estourou). O
     * year_month é opcional (default = mês corrente); a apuração cai na última linha disponível.
     */
    public function index(Request $request): JsonResponse
    {
        $yearMonth = $request->query('year_month') ?: now()->format('Y-m');

        $envio = FechamentoSendStatus::mapFor(
            FechamentoSendStatus::TIPO_EXCEDENTE, $yearMonth,
            $this->baseQuery()->pluck('customer_id')->unique()->values()->all(),
        );

        $data = $this->buildRows($this->baseQuery()->get(), $yearMonth)
            // Mostra TODO contrato com horas excedentes (>0) OU com registro na competência.
            // Hora Adicional zerada aparece com valor R$ 0,00 (a cobrança/relatório é que
            // filtra por valor > 0 — ver reportRows). Ex.: Fechado sem Hora Adicional setada.
            ->filter(fn ($r) => $r['excess_hours'] > 0 || $r['record_id'] !== null)
            ->map(function ($r) use ($envio) {
                $r['envio_em']  = $envio[$r['customer_id']]['envio_em'] ?? null;
                $r['envio_por'] = $envio[$r['customer_id']]['envio_por'] ?? null;
                return $r;
            })
            ->sortByDesc('excess_value')
            ->values();

        return response()->json([
            'data'        => $data,
            'total_geral' => round($data->sum('excess_value'), 2),
        ]);
    }

    /**
     * PATCH /fechamento-excedente/{project}/flag — liga/desliga a cobrança do
     * excedente do contrato/projeto (default do cadastro).
     */
    public function toggleFlag(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate(['charge_excess_hours' => 'required|boolean']);
        $project->update(['charge_excess_hours' => $validated['charge_excess_hours']]);
        return response()->json(['ok' => true, 'charge_excess_hours' => (bool) $project->charge_excess_hours]);
    }

    /**
     * POST /fechamento-excedente/{project}/{yearMonth}
     * Registra/atualiza a apuração da competência com o status escolhido
     * (cobrado | nao_cobrar | pendente). Congela o snapshot do excedente e valor.
     */
    public function salvar(Request $request, int $project, string $yearMonth): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pendente,cobrado,nao_cobrar',
            'notes'  => 'nullable|string|max:1000',
        ]);

        $p = $this->baseQuery()->whereKey($project)->first();
        if (!$p) {
            return response()->json(['message' => 'Projeto não elegível a horas excedentes.'], 422);
        }

        $ap   = $this->apuracao($p, $yearMonth);
        $rate = (float) ($p->additional_hourly_rate ?? 0);

        // BH Mensal: excedente = saldo do fim da competência (acerto do já-cobrado via aporte).
        // BH Fixo/Fechado: incremental (atual − já cobrado em qualquer competência).
        $jaCobrado = (float) ExcessHourCharge::where('status', ExcessHourCharge::STATUS_COBRADO)
            ->where('project_id', $p->id)->sum('excess_hours');
        $excess = $this->excessPendente($ap, $jaCobrado);

        $rec = ExcessHourCharge::updateOrCreate(
            ['project_id' => $p->id, 'year_month' => $yearMonth],
            [
                'basis'                  => $ap['basis'],
                'contracted_hours'       => $ap['contracted'],
                'consumed_hours'         => $ap['consumed'],
                'excess_hours'           => $excess,
                'additional_hourly_rate' => $rate,
                'excess_value'           => round($excess * $rate, 2),
                'status'                 => $validated['status'],
                'notes'                  => $validated['notes'] ?? null,
                'closed_at'              => $validated['status'] === ExcessHourCharge::STATUS_COBRADO ? now() : null,
                'closed_by_id'           => $request->user()?->id,
            ]
        );

        return response()->json(['ok' => true, 'record' => $rec]);
    }

    /**
     * POST /fechamento-excedente/{project}/faturar
     * FATURA o excedente: cria um APORTE (motivo 'excedentes') das horas faturadas no contrato —
     * adicionando as horas ao banco, o que ATUALIZA O SALDO (zera/reduz o excedente) — e registra
     * a cobrança. O usuário pode faturar o excedente apurado, INCLUIR MAIS horas ou faturar MENOS
     * (campo `horas`, default = excedente vigente). Valor = horas × Hora Adicional.
     */
    public function faturar(Request $request, int $project): JsonResponse
    {
        $user = $request->user();
        if (!$user || !($user->isAdmin() || $user->isAdministrativo())) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }

        $p = $this->baseQuery()->whereKey($project)->first();
        if (!$p) {
            return response()->json(['message' => 'Projeto não elegível a horas excedentes.'], 422);
        }

        $rate = (float) ($p->additional_hourly_rate ?? 0);
        if ($rate <= 0) {
            return response()->json(['message' => 'Defina a Hora Adicional do contrato antes de faturar.'], 422);
        }

        $validated = $request->validate([
            'horas' => 'nullable|numeric|min:0.01|max:999999',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Excedente acumulado vigente (default a faturar); o usuário pode faturar mais ou menos.
        $ap    = $this->apuracao($p, now()->format('Y-m'));
        $ym    = (string) ($ap['ym'] ?? now()->format('Y-m'));
        $horas = isset($validated['horas']) ? round((float) $validated['horas'], 2) : round((float) $ap['excess'], 2);
        if ($horas <= 0) {
            return response()->json(['message' => 'Informe as horas a faturar.'], 422);
        }
        $valor = round($horas * $rate, 2);
        $obs   = trim((string) ($validated['notes'] ?? ''));

        // Aporte (motivo 'excedentes' = ajuste interno: entra no banco, atualiza saldo, NÃO notifica cliente).
        $aporte = $p->hourContributions()->create([
            'contributed_hours' => $horas,
            'hourly_rate'       => $rate,
            'nao_valorizado'    => false,
            'description'       => 'Faturamento de horas excedentes — ' . $this->periodoExtenso($ym) . ($obs !== '' ? (' · ' . $obs) : ''),
            'motivo'            => 'excedentes',
            // Nasce na coluna "Novo Contrato" de Demandas e Projetos (não vai direto p/ "Aporte"),
            // p/ o time formalizar o contrato do excedente faturado.
            'kanban_status'     => \App\Models\HourContribution::KANBAN_NOVO,
            'contributed_by'    => $user->id,
            'contributed_at'    => now(),
        ]);
        $this->invalidateListCache('projects');

        // Registra a cobrança (faturado) com o snapshot faturado + referência ao aporte gerado.
        $rec = ExcessHourCharge::updateOrCreate(
            ['project_id' => $p->id, 'year_month' => $ym],
            [
                'basis'                  => $ap['basis'],
                'contracted_hours'       => $ap['contracted'],
                'consumed_hours'         => $ap['consumed'],
                'excess_hours'           => $horas,
                'additional_hourly_rate' => $rate,
                'excess_value'           => $valor,
                'status'                 => ExcessHourCharge::STATUS_COBRADO,
                'notes'                  => trim('Faturado via aporte #' . $aporte->id . ($obs !== '' ? (' · ' . $obs) : '')),
                'closed_at'              => now(),
                'closed_by_id'           => $user->id,
            ]
        );

        return response()->json([
            'ok'        => true,
            'aporte_id' => $aporte->id,
            'horas'     => $horas,
            'valor'     => $valor,
            'record'    => $rec,
        ]);
    }

    // ─── Relatório + E-mail ──────────────────────────────────────────────────

    private function periodoExtenso(string $yearMonth): string
    {
        $meses = [1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
        $c = Carbon::parse($yearMonth . '-01');
        return ($meses[(int) $c->format('n')] ?? '') . ' de ' . $c->format('Y');
    }

    private function brl(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    /** Converte a observação (rich-text/HTML) do apontamento em texto puro legível p/ o relatório. */
    private function plainText(?string $html): string
    {
        $s = (string) $html;
        if ($s === '') return '';
        $s = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $s);   // remove script/style
        $s = preg_replace('/<img\b[^>]*>/i', ' ', $s);                          // remove imagens (URLs assinadas)
        $s = preg_replace('/<br\s*\/?>/i', "\n", $s);
        $s = preg_replace('/<\/(p|div|h[1-6]|li|tr|pre)>/i', "\n", $s);
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Emojis viram "??" no DejaVu (fonte do dompdf) — removidos do relatório.
        $s = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}]/u', '', $s);
        $s = preg_replace('/[ \t]+/u', ' ', $s);
        $s = preg_replace('/\n{2,}/', "\n", $s);
        return trim($s);
    }

    /**
     * Detalhamento do EXCEDENTE por projeto — o "PONTO DE ESTOURO". Percorre o consumo em
     * ordem cronológica (baseline do consumo manual migrado + apontamentos do banco = pai +
     * filhos que compartilham sold<=0) e marca, A PARTIR de qual apontamento o acumulado
     * ultrapassou as horas contratadas. Para cada apontamento excedente traz o solicitante +
     * nº do chamado (Help Desk) + interação + o valor a cobrar (porção excedente × Hora
     * Adicional). Espelha o raciocínio da Gestão de Contratos.
     *
     * @param  Collection  $rows  linhas já apuradas (buildRows) com excess_value>0
     * @return array<int, array<string, mixed>>
     */
    private function buildExcedenteApontamentos(Collection $rows, string $yearMonth): array
    {
        $cutoff    = ProjectController::MONTHLY_CONSUMPTION_CUTOFF;              // 'YYYY-MM'
        $periodEnd = Carbon::parse($yearMonth . '-01')->endOfMonth()->toDateString();
        $out = [];

        foreach ($rows as $r) {
            $p = Project::find($r['project_id']);
            if (!$p) { continue; }

            $contracted = (float) ($r['contracted_hours'] ?? 0);
            $consumed   = (float) ($r['consumed_hours'] ?? 0);
            $rate       = (float) ($r['additional_hourly_rate'] ?? 0);

            // Banco (fontes de apontamento que compõem o consumo), alinhado ao managementBreakdown:
            //  • BH MENSAL: pai + filhos que COMPARTILHAM o banco (sold<=0). Filhos-bloco (sold>0) são bloco.
            //  • BH FIXO/FECHADO: pai + filhos ON DEMAND (consumo APONTADO, independe de sold_hours).
            //    Filhos Fechado/BH-Fixo = bloco; Projeto/BH-Mensal = ignorados.
            $children = Project::where('parent_project_id', $p->id)
                ->with('contractType:id,name')->get(['id', 'sold_hours', 'contract_type_id', 'tipo_faturamento']);
            if ($p->isBankHoursMonthly()) {
                $childIds = $children->where('sold_hours', '<=', 0)->pluck('id')->all();
            } else {
                $childIds = $children->filter(function ($c) {
                    $n = strtolower((string) ($c->contractType->name ?? ''));
                    return str_contains($n, 'on demand') || str_contains($n, 'on-demand')
                        || $c->tipo_faturamento === 'on_demand';
                })->pluck('id')->all();
            }
            $bankIds = array_merge([$p->id], $childIds);

            // Apontamentos do banco, em ordem cronológica, do corte até o fim da competência.
            $ts = Timesheet::with(['user:id,name'])
                ->whereIn('project_id', $bankIds)
                ->whereIn('status', ['approved', 'pending'])
                ->where('date', '>=', $cutoff . '-01')
                ->where('date', '<=', $periodEnd)
                ->orderBy('date')->orderBy('created_at')->orderBy('id')
                ->get(['id', 'user_id', 'date', 'effort_minutes', 'contract_client_pct', 'client_extra_pct', 'ticket', 'observation']);

            // Horas faturáveis (com multiplicador) por apontamento + soma da janela.
            $horasDe = [];
            $apSum = 0.0;
            foreach ($ts as $t) {
                $pct = (float) ($t->contract_client_pct ?? $t->client_extra_pct ?? 0);
                $h = (float) $t->effort_minutes * (1 + $pct / 100) / 60.0;
                $horasDe[$t->id] = $h;
                $apSum += $h;
            }
            // Baseline = tudo que NÃO é apontamento da janela (consumo manual migrado + blocos de
            // filhos-bloco sold>0 + etc.), DERIVADO da apuração: consumido − soma dos apontamentos.
            // Assim o acumulado do walk termina exatamente no consumido e as porções excedentes
            // reconciliam com o headline (consumido − contratadas). Cobre contratos cujo consumo
            // vem de carve-outs (não-apontamentos), que o baseline só-manual não enxergava.
            $baseline = max(0.0, $consumed - $apSum);

            // Solicitante + nº do chamado: resolvidos do Help Desk pelo número do chamado
            // (casa com ticket cru OU zero-padded a 6 dígitos, como no Movidesk).
            $tickets = $ts->pluck('ticket')->filter()->map(fn ($t) => (string) $t)->unique()->values()->all();
            $solicitantes = [];
            if (!empty($tickets)) {
                $padded = array_map(fn ($t) => str_pad($t, 6, '0', STR_PAD_LEFT), $tickets);
                // Número é único POR EMPRESA: em colisão, a ERPSERV (menor company_id) prevalece
                // (pluck: último vence → ordena desc).
                $solicitantes = HelpDeskTicket::whereIn('ticket_number', array_merge($tickets, $padded))
                    ->orderByDesc('company_id')
                    ->pluck('requester_name', 'ticket_number')->all();
            }
            $solFor = function ($ticket) use ($solicitantes) {
                $t = (string) $ticket;
                return $solicitantes[$t] ?? $solicitantes[str_pad($t, 6, '0', STR_PAD_LEFT)] ?? null;
            };

            $running = $baseline;
            $itens = [];
            $excTot = 0.0; $valTot = 0.0; $first = true;
            foreach ($ts as $t) {
                $horas = $horasDe[$t->id];
                $running += $horas;
                if ($running <= $contracted) { continue; }                      // ainda dentro do contratado
                $portionRaw = min($horas, $running - $contracted);
                if ($portionRaw <= 0) { continue; }
                $portion = round($portionRaw, 2);
                $valor   = round($portion * $rate, 2);
                // Totais acumulam SEM arredondar por linha (p/ bater com o headline da apuração;
                // as linhas exibem valores arredondados, o total é o oficial).
                $excTot += $portionRaw; $valTot += $portionRaw * $rate;
                $itens[] = [
                    'data'        => Carbon::parse($t->date)->format('d/m/Y'),
                    'consultor'   => $t->user->name ?? '—',
                    'chamado'     => $t->ticket ? (string) $t->ticket : null,
                    'solicitante' => $solFor($t->ticket),
                    'interacao'   => $this->plainText($t->observation),
                    'horas'       => number_format(round($horas, 2), 2, ',', '.'),
                    'excedente'   => number_format($portion, 2, ',', '.'),
                    'valor'       => $this->brl($valor),
                    'estouro'     => $first,
                ];
                $first = false;
            }

            // Excedente NÃO coberto por apontamento (vem de blocos de filhos contratados ou
            // consumo migrado anterior ao período de rastreio). Ex.: BH Fixo cujo banco estourou
            // majoritariamente por blocos, não por apontamentos.
            $restoHoras = round((float) $r['excess_hours'] - round($excTot, 2), 2);

            // SEMPRE inclui o contrato (mesmo sem itens detalhados, ou com Hora Adicional zerada)
            // — o relatório nunca vem vazio se há excedente apurado.
            $out[] = [
                'projeto'       => $r['code'] . ' — ' . $r['project_name'],
                'tipo'          => $r['basis'] === 'fixed' ? 'BH Fixo' : ($r['basis'] === 'closed' ? 'Fechado' : 'BH Mensal'),
                'contratadas'   => number_format((float) $r['contracted_hours'], 2, ',', '.') . 'h',
                'consumido'     => number_format((float) $r['consumed_hours'], 2, ',', '.') . 'h',
                'valor_hora'    => $this->brl($rate),
                // Totais = os da APURAÇÃO (headline do resumo), p/ o detalhamento BATER com
                // o resumo. As linhas (porções) são a composição e podem diferir em centavos.
                'excedente_tot' => number_format((float) $r['excess_hours'], 2, ',', '.') . 'h',
                'valor_tot'     => $this->brl((float) $r['excess_value']),
                'sem_hora_adic' => $rate <= 0,
                'resto_horas'   => $restoHoras > 0.1 ? (number_format($restoHoras, 2, ',', '.') . 'h') : null,
                'itens'         => $itens,
            ];
        }

        return $out;
    }

    /** Dados da view do relatório de horas excedentes de um cliente. */
    private function buildExcedenteViewData(Customer $customer, string $yearMonth): array
    {
        // Relatório CLIENTE (cobrança): só contratos efetivamente cobráveis — excedente > 0,
        // flag de cobrança ligada, não "não cobrar" E com VALOR a cobrar > 0 (Hora Adicional
        // definida). Contratos com excedente mas Hora Adicional R$ 0,00 ficam só na listagem
        // interna (com o aviso p/ definir a tarifa), NUNCA no relatório/e-mail do cliente.
        $rows = $this->buildRows(
            $this->baseQuery()->where('customer_id', $customer->id)->get(),
            $yearMonth,
        )->filter(fn ($r) => $r['excess_hours'] > 0 && $r['charge'] && $r['status'] !== ExcessHourCharge::STATUS_NAO_COBRAR && $r['excess_value'] > 0)
         ->values();

        $total = round($rows->sum('excess_value'), 2);

        // Agregados do resumo (para o modelo de e-mail): somas do cliente na competência.
        $contratadasTot = round((float) $rows->sum('contracted_hours'), 2);
        $consumidoTot   = round((float) $rows->sum('consumed_hours'), 2);
        $excedenteTot   = round((float) $rows->sum('excess_hours'), 2);
        // Valor da hora excedente: se todos os contratos usam a mesma tarifa, usa-a;
        // senão, tarifa efetiva ponderada (total ÷ horas excedentes).
        $rates = $rows->pluck('additional_hourly_rate')->map(fn ($v) => round((float) $v, 2))->unique();
        $valorHora = $rates->count() === 1
            ? (float) $rates->first()
            : ($excedenteTot > 0 ? round($total / $excedenteTot, 2) : 0.0);

        // Logo BRANCO (header roxo do relatório). Fallback p/ o logo padrão se não existir.
        $logoFile    = public_path('logo-erpserv-white.png');
        if (!is_file($logoFile)) $logoFile = public_path('logo-erpserv.png');
        $logoDataUri = is_file($logoFile)
            ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoFile))
            : null;

        $linhas = $rows->map(fn ($r) => [
            'projeto'    => $r['code'] . ' — ' . $r['project_name'],
            'tipo'       => $r['basis'] === 'fixed' ? 'BH Fixo' : 'BH Mensal',
            'contratadas'=> number_format((float) $r['contracted_hours'], 2, ',', '.') . 'h',
            'consumido'  => number_format((float) $r['consumed_hours'], 2, ',', '.') . 'h',
            'excedente'  => number_format((float) $r['excess_hours'], 2, ',', '.') . 'h',
            'hora_adic'  => $this->brl((float) $r['additional_hourly_rate']),
            'valor'      => $this->brl((float) $r['excess_value']),
        ])->all();

        // Detalhamento do EXCEDENTE (PONTO DE ESTOURO) por projeto — com solicitante,
        // nº do chamado, interação e valor a cobrar por apontamento.
        $apontamentos = $this->buildExcedenteApontamentos($rows, $yearMonth);

        // Período do excedente = DESDE o 1º apontamento que estourou (a rotina não tem
        // competência — o relatório traz tudo desde que começou o excedente).
        $estouros = [];
        foreach ($apontamentos as $ap) {
            if (!empty($ap['itens'][0]['data'])) { $estouros[] = $ap['itens'][0]['data']; }
        }
        usort($estouros, fn ($a, $b) => Carbon::createFromFormat('d/m/Y', $a)->timestamp <=> Carbon::createFromFormat('d/m/Y', $b)->timestamp);
        $periodoLabel = !empty($estouros) ? ('desde ' . $estouros[0]) : '';

        return [
            'clienteName' => $customer->name,
            'periodo'     => $this->periodoExtenso($yearMonth),
            'periodoLabel'=> $periodoLabel,
            'logoDataUri' => $logoDataUri,
            'emitidoEm'   => now()->format('d/m/Y'),
            'linhas'      => $linhas,
            'apontamentos'=> $apontamentos,
            'qtd'         => count($linhas),
            'totalFmt'    => $this->brl($total),
            'totalValue'  => $total,
            // Resumo agregado para o modelo de e-mail.
            'competencia'         => Carbon::parse($yearMonth . '-01')->format('m/Y'),
            'contratadasHorasFmt' => number_format($contratadasTot, 2, ',', '.') . 'h',
            'consumidoHorasFmt'   => number_format($consumidoTot, 2, ',', '.') . 'h',
            'excedenteHorasFmt'   => number_format($excedenteTot, 2, ',', '.') . 'h',
            'valorHoraFmt'        => $this->brl($valorHora),
        ];
    }

    /** Modelo padrão do e-mail de horas excedentes (resumo da apuração preenchido). */
    private function defaultEmailMessage(array $v): string
    {
        return "Prezados,\n\n"
            . "Segue em anexo o relatório de apuração das horas excedentes referente à competência {$v['competencia']}.\n\n"
            . "Resumo da apuração:\n\n"
            . "• Horas contratadas (acumuladas): {$v['contratadasHorasFmt']}\n"
            . "• Horas consumidas: {$v['consumidoHorasFmt']}\n"
            . "• Horas excedentes: {$v['excedenteHorasFmt']}\n"
            . "• Valor da hora excedente: {$v['valorHoraFmt']}\n"
            . "• Valor total a faturar: {$v['totalFmt']}\n\n"
            . "Essas horas correspondem ao consumo realizado acima da quantidade de horas contratadas e serão faturadas conforme previsto em contrato.\n\n"
            . "Em caso de dúvidas ou divergências, nossa equipe permanece à disposição.\n\n"
            . "Atenciosamente,";
    }

    /** Variáveis disponíveis no modelo de e-mail (cadastro "Horas Excedentes"). */
    private function templateVars(Customer $customer, array $v): array
    {
        return [
            'nome'              => $customer->name,
            'periodo'           => $v['periodo'],
            'competencia'       => $v['competencia'],
            'horas_contratadas' => $v['contratadasHorasFmt'],
            'horas_consumidas'  => $v['consumidoHorasFmt'],
            'horas_excedentes'  => $v['excedenteHorasFmt'],
            'valor_hora'        => $v['valorHoraFmt'],
            'valor_total'       => $v['totalFmt'],
            'valor'             => $v['totalFmt'],
        ];
    }

    /** Modelo ativo do cadastro (categoria "excedente"), com variáveis substituídas — null se não houver. */
    private function resolveTemplate(Customer $customer, array $viewData, string $yearMonth): ?array
    {
        return app(\App\Services\FechamentoEmailTemplateService::class)
            ->resolve('excedente', null, $this->templateVars($customer, $viewData), $yearMonth);
    }

    /** Corpo padrão: modelo ativo do cadastro ou o texto embutido de fallback. */
    private function mensagemPadrao(Customer $customer, array $viewData, string $yearMonth): string
    {
        $tpl = $this->resolveTemplate($customer, $viewData, $yearMonth);
        return $tpl && trim((string) $tpl['body']) !== '' ? $tpl['body'] : $this->defaultEmailMessage($viewData);
    }

    /** GET /fechamento-excedente/{customerId}/{yearMonth}/report-html — preview (mesma Blade do PDF). */
    public function reportHtml(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }
        $viewData = $this->buildExcedenteViewData($customer, $yearMonth);
        $html = view('pdf.fechamento-excedente', $viewData)->render();
        return response()->json([
            'html'            => $html,
            'default_message' => $this->mensagemPadrao($customer, $viewData, $yearMonth),
        ]);
    }

    /** GET /fechamento-excedente/{customerId}/{yearMonth}/export-excel — Excel (resumo + apontamentos). */
    public function exportExcel(Request $request, string $customerId, string $yearMonth)
    {
        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }
        $viewData = $this->buildExcedenteViewData($customer, $yearMonth);
        $safeName = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $customer->name);
        $fileName = "Horas_Excedentes_{$yearMonth}_{$safeName}.xlsx";
        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\ExcedenteExport($viewData), $fileName);
    }

    /** GET /fechamento-excedente/{customerId}/{yearMonth}/pdf — baixa o PDF (o mesmo anexado no e-mail). */
    public function downloadPdf(Request $request, string $customerId, string $yearMonth)
    {
        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }
        $viewData = $this->buildExcedenteViewData($customer, $yearMonth);
        $safeName = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $customer->name);
        $fileName = "Horas_Excedentes_{$yearMonth}_{$safeName}.pdf";
        return Pdf::loadView('pdf.fechamento-excedente', $viewData)
            ->setPaper('a4', 'portrait')
            ->download($fileName);
    }

    /** POST .../email-preview — prévia do E-MAIL (layout branded, mesma Blade do envio), live com a mensagem editada. */
    public function emailPreview(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }

        $viewData       = $this->buildExcedenteViewData($customer, $yearMonth);
        $mensagemPadrao = $this->mensagemPadrao($customer, $viewData, $yearMonth);
        $mensagem       = trim((string) $request->input('mensagem'));
        $mensagem       = $mensagem !== '' ? $mensagem : $mensagemPadrao;

        $html = view('emails.fechamento.excedente', [
            'clienteName'     => $viewData['clienteName'] ?? $customer->name,
            'senderName'      => $sender->name,
            'periodo'         => $viewData['periodo'],
            'valorTotal'      => $viewData['totalFmt'],
            'mensagem'        => $mensagem,
            'withAttachments' => true,
        ])->render();

        // Prévia: força o logo claro a aparecer no card branco (swap dark-mode mostraria o branco, invisível aqui).
        $override = '<style>.erp-light{display:inline-block !important}.erp-dark{display:none !important}</style>';
        $html = str_ireplace('</head>', $override . '</head>', $html);

        return response()->json(['html' => $html, 'default_message' => $mensagemPadrao]);
    }

    /**
     * POST /fechamento-excedente/{customerId}/{yearMonth}/email
     * Envia o relatório de horas excedentes ao cliente (PDF anexo), como o
     * remetente logado (Graph Send As) — destinatários informados na tela + CC
     * dos papéis da Central de Workflows. Marca "enviado".
     */
    public function enviarEmail(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão para enviar.'], 403);
        }
        if (!$sender->email) {
            return response()->json(['success' => false, 'message' => 'Seu usuário não tem e-mail cadastrado como remetente.'], 422);
        }

        $validated = $request->validate([
            'emails'   => 'required|array|min:1',
            'emails.*' => 'email',
            'mensagem' => 'nullable|string',
        ], [
            'emails.required' => 'Informe ao menos um e-mail de destino.',
            'emails.min'      => 'Informe ao menos um e-mail de destino.',
            'emails.*.email'  => 'Um dos e-mails informados é inválido.',
        ]);

        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }

        $viewData = $this->buildExcedenteViewData($customer, $yearMonth);
        if ($viewData['qtd'] === 0) {
            return response()->json(['success' => false, 'message' => 'Nenhuma hora excedente a cobrar para este cliente na competência.'], 422);
        }

        $to = array_values(array_unique(array_filter($validated['emails'])));

        // CC: papéis configurados na Central de Workflows (workflow dedicado "Horas excedentes"), sem duplicar o To.
        $wf = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('fechamento.excedente', ['customer' => $customer]);
        $financeiroCc = (string) (config('mail.financeiro_cc') ?? '');
        $cc = array_values(array_diff(
            array_unique(array_merge(array_filter([$financeiroCc]), $wf['to'], $wf['cc'])), $to,
        ));

        // Modelo do cadastro ("Horas Excedentes"), se ativo — usa assunto/corpo dele.
        $tpl      = $this->resolveTemplate($customer, $viewData, $yearMonth);
        $subject  = ($tpl && trim((string) $tpl['subject']) !== '')
            ? $tpl['subject']
            : 'Horas Excedentes ' . Carbon::parse($yearMonth . '-01')->format('m/Y') . ' | ' . $customer->name;
        $mensagem = trim((string) ($validated['mensagem'] ?? '')) ?:
            (($tpl && trim((string) $tpl['body']) !== '') ? $tpl['body'] : $this->defaultEmailMessage($viewData));

        // PDF
        $dirFull = storage_path('app/fechamentos');
        if (!is_dir($dirFull)) { mkdir($dirFull, 0775, true); }
        $safeName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $customer->name);
        $pdfName  = "Horas_Excedentes_{$yearMonth}_{$safeName}.pdf";
        $pdfPath  = "{$dirFull}/{$pdfName}";
        $pdf = Pdf::loadView('pdf.fechamento-excedente', $viewData)
            ->setPaper('a4', 'portrait')->setOption(['defaultMediaType' => 'print']);
        file_put_contents($pdfPath, $pdf->output());

        // Excel (resumo + apontamentos) — anexado junto do PDF.
        $xlsxName = "Horas_Excedentes_{$yearMonth}_{$safeName}.xlsx";
        $xlsxPath = "{$dirFull}/{$xlsxName}";
        file_put_contents($xlsxPath, \Maatwebsite\Excel\Facades\Excel::raw(new \App\Exports\ExcedenteExport($viewData), \Maatwebsite\Excel\Excel::XLSX));

        // E-mail no layout branded padrão (mesmo dos demais fechamentos), com a mensagem/resumo no corpo.
        $bodyHtml = view('emails.fechamento.excedente', [
            'clienteName'     => $viewData['clienteName'] ?? $customer->name,
            'senderName'      => $sender->name,
            'periodo'         => $viewData['competencia'] ?? Carbon::parse($yearMonth . '-01')->format('m/Y'),
            'valorTotal'      => $viewData['totalFmt'],
            'mensagem'        => $mensagem,
            'withAttachments' => true,
        ])->render();

        try {
            if (\App\Services\GraphMailer::enabled() && filled($sender->email)) {
                \App\Services\GraphMailer::sendAs($sender->email, $to, $cc, $subject, $bodyHtml, [$pdfPath, $xlsxPath]);
            } else {
                Mail::html($bodyHtml, function ($m) use ($to, $cc, $subject, $sender, $pdfPath, $pdfName, $xlsxPath, $xlsxName) {
                    $m->to($to)->subject($subject)->from($sender->email, $sender->name);
                    if (!empty($cc)) { $m->cc($cc); }
                    $m->attach($pdfPath, ['as' => $pdfName, 'mime' => 'application/pdf']);
                    $m->attach($xlsxPath, ['as' => $xlsxName, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
                });
            }

            FechamentoSendStatus::marcarEnviado(
                FechamentoSendStatus::TIPO_EXCEDENTE, (int) $customer->id, $yearMonth, (int) $sender->id,
            );
            Log::info('Fechamento de horas excedentes enviado', [
                'cliente' => $customer->id, 'remetente' => $sender->id, 'to' => $to, 'cc' => $cc, 'total' => $viewData['totalValue'],
            ]);
            @unlink($pdfPath);
            @unlink($xlsxPath);
        } catch (\Throwable $e) {
            @unlink($pdfPath);
            @unlink($xlsxPath);
            Log::error('Falha ao enviar horas excedentes por e-mail', ['cliente' => $customer->id, 'erro' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Falha ao enviar o e-mail: ' . $e->getMessage()], 500);
        }

        $toLabel = implode(', ', $to);
        return response()->json([
            'success' => true,
            'message' => "Relatório de horas excedentes enviado para {$toLabel}" . (!empty($cc) ? ' (cópia: ' . implode(', ', $cc) . ')' : '') . '.',
        ]);
    }
}
