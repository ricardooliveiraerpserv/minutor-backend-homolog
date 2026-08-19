<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\ExcessHourCharge;
use App\Models\FechamentoSendStatus;
use App\Models\Project;
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
            ];
        }
        $ap = $p->excessHoursApuracao($yearMonth);
        $ap['monthly'] = false;
        return $ap;
    }

    /** Saldo (déficit/superávit) no FIM da competência via extrato mês-a-mês. */
    private function monthlyBalanceAt(Project $p, string $yearMonth): ?array
    {
        $resp = json_decode(app(ProjectController::class)->monthlyStatement($p)->getContent(), true);
        foreach (($resp['rows'] ?? []) as $r) {
            if (($r['year_month'] ?? null) === $yearMonth) {
                return [
                    'balance'  => (float) ($r['balance_hours'] ?? 0),
                    'vendidas' => (float) ($r['vendidas_hours'] ?? 0),
                    'consumed' => (float) ($r['accumulated_consumption_hours'] ?? 0),
                ];
            }
        }
        return null;
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

        // Registro persistido desta competência (status definido na rotina).
        $records = ExcessHourCharge::where('year_month', $yearMonth)
            ->whereIn('project_id', $ids)->get()->keyBy('project_id');

        return $projects->map(function (Project $p) use ($yearMonth, $charged, $records) {
            $ap   = $this->apuracao($p, $yearMonth);
            $rate = (float) ($p->additional_hourly_rate ?? 0);
            $rec  = $records->get($p->id);

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
            ];
        });
    }

    /**
     * GET /fechamento-excedente?year_month=AAAA-MM
     * Lista os projetos com horas excedentes A COBRAR (valor > 0) na competência.
     */
    public function index(Request $request): JsonResponse
    {
        $yearMonth = $request->query('year_month');
        if (!$yearMonth) {
            return response()->json(['data' => [], 'total_geral' => 0]);
        }

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
        $s = preg_replace('/[ \t]+/u', ' ', $s);
        $s = preg_replace('/\n{2,}/', "\n", $s);
        return trim($s);
    }

    /** Dados da view do relatório de horas excedentes de um cliente. */
    private function buildExcedenteViewData(Customer $customer, string $yearMonth): array
    {
        $rows = $this->buildRows(
            $this->baseQuery()->where('customer_id', $customer->id)->get(),
            $yearMonth,
        )->filter(fn ($r) => $r['excess_value'] > 0 && $r['charge'] && $r['status'] !== ExcessHourCharge::STATUS_NAO_COBRAR)
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

        $logoFile    = public_path('logo-erpserv.png');
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

        // Apontamentos (detalhamento) da competência apurada — por projeto excedente.
        $apontamentos = [];
        $projIds = $rows->pluck('project_id')->filter()->all();
        if (!empty($projIds)) {
            $from = Carbon::parse($yearMonth . '-01')->startOfMonth()->toDateString();
            $to   = Carbon::parse($yearMonth . '-01')->endOfMonth()->toDateString();
            $ts = \App\Models\Timesheet::with(['user:id,name'])
                ->whereIn('project_id', $projIds)
                ->whereIn('status', ['approved', 'pending'])
                ->whereBetween('date', [$from, $to])
                ->orderBy('project_id')->orderBy('date')->get()
                ->groupBy('project_id');
            foreach ($rows as $r) {
                $g = $ts->get($r['project_id']) ?? collect();
                $apontamentos[] = [
                    'projeto'     => $r['code'] . ' — ' . $r['project_name'],
                    'total_horas' => number_format((float) $g->sum('effort_minutes') / 60, 2, ',', '.') . 'h',
                    'itens'       => $g->map(fn ($t) => [
                        'data'      => Carbon::parse($t->date)->format('d/m/Y'),
                        'consultor' => $t->user->name ?? '—',
                        'horas'     => number_format((float) ($t->effort_minutes ?? 0) / 60, 2, ',', '.'),
                        'descricao' => $this->plainText($t->observation),
                    ])->values()->all(),
                ];
            }
        }

        return [
            'clienteName' => $customer->name,
            'periodo'     => $this->periodoExtenso($yearMonth),
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
