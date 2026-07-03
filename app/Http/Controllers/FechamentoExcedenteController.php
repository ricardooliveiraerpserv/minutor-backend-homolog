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
                // BH Mensal, BH Fixo e Fechado têm horas contratadas que podem estourar.
                // On Demand não entra (paga por hora consumida, sem teto → sem excedente).
                $q->whereIn('code', ['monthly_hours', 'fixed_hours', 'closed'])
                  ->orWhereRaw('lower(name) in (?, ?, ?)', ['banco de horas mensal', 'banco de horas fixo', 'fechado']);
            });
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
            $ap   = $p->excessHoursApuracao($yearMonth);
            $rate = (float) ($p->additional_hourly_rate ?? 0);
            $rec  = $records->get($p->id);

            if ($ap['basis'] === 'fixed' || $ap['basis'] === 'closed') {
                $jaCobrado  = (float) ($charged->get($p->id) ?? 0);
                $excessPend = max(0, round($ap['excess'] - $jaCobrado, 2));
            } else {
                $excessPend = $rec && in_array($rec->status, [ExcessHourCharge::STATUS_COBRADO, ExcessHourCharge::STATUS_NAO_COBRAR])
                    ? (float) $rec->excess_hours
                    : $ap['excess'];
            }

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
            // Só quem tem excedente A COBRAR (valor > 0) OU já tem registro na competência.
            // Excedente em horas com Hora Adicional zerada fica de fora (tela de cobrança).
            ->filter(fn ($r) => $r['excess_value'] > 0 || $r['record_id'] !== null)
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

        $ap   = $p->excessHoursApuracao($yearMonth);
        $rate = (float) ($p->additional_hourly_rate ?? 0);

        if ($ap['basis'] === 'fixed' || $ap['basis'] === 'closed') {
            $jaCobrado = (float) ExcessHourCharge::where('status', ExcessHourCharge::STATUS_COBRADO)
                ->where('project_id', $p->id)->sum('excess_hours');
            $excess = max(0, round($ap['excess'] - $jaCobrado, 2));
        } else {
            $excess = $ap['excess'];
        }

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

    /** Dados da view do relatório de horas excedentes de um cliente. */
    private function buildExcedenteViewData(Customer $customer, string $yearMonth): array
    {
        $rows = $this->buildRows(
            $this->baseQuery()->where('customer_id', $customer->id)->get(),
            $yearMonth,
        )->filter(fn ($r) => $r['excess_value'] > 0 && $r['charge'] && $r['status'] !== ExcessHourCharge::STATUS_NAO_COBRAR)
         ->values();

        $total = round($rows->sum('excess_value'), 2);

        $logoFile    = public_path('logo-erpserv.png');
        $logoDataUri = is_file($logoFile)
            ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoFile))
            : null;

        $linhas = $rows->map(fn ($r) => [
            'projeto'    => $r['code'] . ' — ' . $r['project_name'],
            'tipo'       => $r['basis'] === 'fixed' ? 'BH Fixo' : ($r['basis'] === 'closed' ? 'Fechado' : 'BH Mensal'),
            'contratadas'=> number_format((float) $r['contracted_hours'], 2, ',', '.') . 'h',
            'consumido'  => number_format((float) $r['consumed_hours'], 2, ',', '.') . 'h',
            'excedente'  => number_format((float) $r['excess_hours'], 2, ',', '.') . 'h',
            'hora_adic'  => $this->brl((float) $r['additional_hourly_rate']),
            'valor'      => $this->brl((float) $r['excess_value']),
        ])->all();

        return [
            'clienteName' => $customer->name,
            'periodo'     => $this->periodoExtenso($yearMonth),
            'logoDataUri' => $logoDataUri,
            'emitidoEm'   => now()->format('d/m/Y'),
            'linhas'      => $linhas,
            'qtd'         => count($linhas),
            'totalFmt'    => $this->brl($total),
            'totalValue'  => $total,
        ];
    }

    /** GET /fechamento-excedente/{customerId}/{yearMonth}/report-html — preview (mesma Blade do PDF). */
    public function reportHtml(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }
        $html = view('pdf.fechamento-excedente', $this->buildExcedenteViewData($customer, $yearMonth))->render();
        return response()->json(['html' => $html]);
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

        $periodo = $viewData['periodo'];
        $to      = array_values(array_unique(array_filter($validated['emails'])));

        // CC: papéis configurados na Central de Workflows (executivo, financeiro), sem duplicar o To.
        $wf = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('fechamento.cliente', ['customer' => $customer]);
        $financeiroCc = (string) (config('mail.financeiro_cc') ?? '');
        $cc = array_values(array_diff(
            array_unique(array_merge(array_filter([$financeiroCc]), $wf['to'], $wf['cc'])), $to,
        ));

        $subject = 'Horas Excedentes ' . Carbon::parse($yearMonth . '-01')->format('m/Y') . ' | ' . $customer->name;
        $mensagem = trim((string) ($validated['mensagem'] ?? '')) ?:
            "Prezados,\n\nSegue em anexo a apuração das horas excedentes referente ao período de {$periodo} (horas consumidas acima das contratadas).\n\nEm caso de dúvidas ou divergências, por gentileza entrar em contato.";

        // PDF
        $dirFull = storage_path('app/fechamentos');
        if (!is_dir($dirFull)) { mkdir($dirFull, 0775, true); }
        $safeName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $customer->name);
        $pdfName  = "Horas_Excedentes_{$yearMonth}_{$safeName}.pdf";
        $pdfPath  = "{$dirFull}/{$pdfName}";
        $pdf = Pdf::loadView('pdf.fechamento-excedente', $viewData)
            ->setPaper('a4', 'portrait')->setOption(['defaultMediaType' => 'print']);
        file_put_contents($pdfPath, $pdf->output());

        $bodyHtml = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937;white-space:pre-line">'
            . e($mensagem) . '</div>';

        try {
            if (\App\Services\GraphMailer::enabled() && filled($sender->email)) {
                \App\Services\GraphMailer::sendAs($sender->email, $to, $cc, $subject, $bodyHtml, [$pdfPath]);
            } else {
                Mail::html($bodyHtml, function ($m) use ($to, $cc, $subject, $sender, $pdfPath, $pdfName) {
                    $m->to($to)->subject($subject)->from($sender->email, $sender->name);
                    if (!empty($cc)) { $m->cc($cc); }
                    $m->attach($pdfPath, ['as' => $pdfName, 'mime' => 'application/pdf']);
                });
            }

            FechamentoSendStatus::marcarEnviado(
                FechamentoSendStatus::TIPO_EXCEDENTE, (int) $customer->id, $yearMonth, (int) $sender->id,
            );
            Log::info('Fechamento de horas excedentes enviado', [
                'cliente' => $customer->id, 'remetente' => $sender->id, 'to' => $to, 'cc' => $cc, 'total' => $viewData['totalValue'],
            ]);
            @unlink($pdfPath);
        } catch (\Throwable $e) {
            @unlink($pdfPath);
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
