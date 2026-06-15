<?php

namespace App\Http\Controllers;

use App\Models\FechamentoDiretoria;
use App\Models\FechamentoDiretoriaItem;
use App\Models\FechamentoSendStatus;
use App\Models\User;
use App\Services\GraphMailer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Fechamento Diretoria — por diretor (usuário) e competência. Cada diretor tem
 * seu próprio fechamento (linhas: Cooperativa, Repasse, Taxa+INSS, Valor a
 * Receber, Observação por itens) com status aberto/finalizado. Fluxo: abre o
 * fechamento de um diretor, lança, finaliza, abre o do próximo.
 */
class FechamentoDiretoriaController extends Controller
{
    private function authorizeManage(Request $request): bool
    {
        $u = $request->user();
        return $u && ($u->isAdmin() || $u->type === 'administrativo');
    }

    /**
     * GET /fechamento-diretoria/diretores?year_month=AAAA-MM
     * Usuários disponíveis + status do fechamento no mês.
     */
    public function diretores(Request $request): JsonResponse
    {
        $yearMonth = (string) $request->query('year_month');
        $statusMap = $yearMonth
            ? FechamentoDiretoria::where('year_month', $yearMonth)->pluck('status', 'user_id')
            : collect();
        // user_ids que têm linhas no mês (mesmo sem cabeçalho ainda).
        $comItens = $yearMonth
            ? FechamentoDiretoriaItem::where('year_month', $yearMonth)->whereNotNull('user_id')->distinct()->pluck('user_id')->flip()
            : collect();

        $users = User::where('is_diretor', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => [
                'id'     => $u->id,
                'nome'   => $u->name,
                'status' => $statusMap[$u->id] ?? ($comItens->has($u->id) ? FechamentoDiretoria::STATUS_ABERTO : 'sem_registro'),
            ]);

        return response()->json(['data' => $users]);
    }

    /** GET /fechamento-diretoria/usuarios — todos os usuários + flag is_diretor (p/ gerenciar). */
    public function usuarios(): JsonResponse
    {
        $users = User::where('enabled', true)
            ->whereNotIn('type', ['cliente'])
            ->orderBy('name')
            ->get(['id', 'name', 'is_diretor'])
            ->map(fn ($u) => ['id' => $u->id, 'nome' => $u->name, 'is_diretor' => (bool) $u->is_diretor]);

        return response()->json(['data' => $users]);
    }

    /** POST /fechamento-diretoria/diretores — define o conjunto de diretores (user_ids). */
    public function definirDiretores(Request $request): JsonResponse
    {
        if (!$this->authorizeManage($request)) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }
        $validated = $request->validate([
            'user_ids'   => ['present', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $ids = $validated['user_ids'];

        // Toca só as linhas que mudam de estado.
        User::whereIn('id', $ids)->where('is_diretor', false)->update(['is_diretor' => true]);
        User::whereNotIn('id', $ids ?: [0])->where('is_diretor', true)->update(['is_diretor' => false]);

        return $this->usuarios();
    }

    /** GET /fechamento-diretoria/{userId}/{yearMonth} — fechamento de um diretor. */
    public function show(string $userId, string $yearMonth): JsonResponse
    {
        $header = FechamentoDiretoria::where('user_id', $userId)->where('year_month', $yearMonth)->first();
        $lancamentos = $this->itensDoDiretor((int) $userId, $yearMonth);

        // Adiantamento (parcelas da rotina) do mês — desconto que reduz o valor a receber.
        // Uma linha por adiantamento (não somadas); o total ainda reduz o repasse.
        $adiantamentos    = \App\Models\Adiantamento::parcelasNoMes('consultor', (int) $userId, $yearMonth);
        $adiantamento     = round(collect($adiantamentos)->sum('valor'), 2);
        $adiantamentoDesc = $this->adiantamentoLegenda((int) $userId, $yearMonth);

        // Valor a Receber = soma dos lançamentos − adiantamento; REPASSE = valor a receber + Taxa+INSS.
        $taxa  = (float) ($header?->taxa_inss ?? 0);
        $total = round(collect($lancamentos)->sum(fn ($l) => (float) ($l['valor'] ?? 0)) - $adiantamento + $taxa, 2);

        $envio = FechamentoSendStatus::mapFor(FechamentoSendStatus::TIPO_DIRETORIA, $yearMonth, [(int) $userId])[(int) $userId] ?? null;

        return response()->json([
            'status'        => $header?->status ?? 'sem_registro',
            'finalized_at'  => $header?->finalized_at?->toISOString(),
            'diretor_email' => User::find($userId)?->email,
            'envio_em'      => $envio['envio_em'] ?? null,
            'envio_por'     => $envio['envio_por'] ?? null,
            'cooperativa'   => $header?->cooperativa,
            'taxa_inss'    => $header?->taxa_inss !== null ? (float) $header->taxa_inss : null,
            'valor_coop_erpserv' => $header?->valor_coop_erpserv !== null ? (float) $header->valor_coop_erpserv : null,
            'valor_coop_bizify'  => $header?->valor_coop_bizify !== null ? (float) $header->valor_coop_bizify : null,
            'taxa_coop_erpserv'  => $header?->taxa_coop_erpserv !== null ? (float) $header->taxa_coop_erpserv : null,
            'taxa_coop_bizify'   => $header?->taxa_coop_bizify !== null ? (float) $header->taxa_coop_bizify : null,
            'adiantamento_coop'  => $header?->adiantamento_coop,
            'lancamentos'  => $lancamentos,
            'adiantamento' => $adiantamento,
            'adiantamento_desc' => $adiantamentoDesc,
            'adiantamentos' => $adiantamentos,
            'total'        => $total,
        ]);
    }

    /** POST /fechamento-diretoria/{userId}/{yearMonth} — salva as linhas do diretor. */
    public function salvar(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        if (!$this->authorizeManage($request)) {
            return response()->json(['message' => 'Sem permissão para gerenciar o fechamento da diretoria.'], 403);
        }
        if (!User::whereKey($userId)->exists()) {
            return response()->json(['message' => 'Diretor não encontrado.'], 422);
        }

        $header = FechamentoDiretoria::firstOrNew(['user_id' => (int) $userId, 'year_month' => $yearMonth]);
        if ($header->exists && $header->isFinalizado()) {
            return response()->json(['message' => 'Fechamento finalizado — reabra para editar.'], 422);
        }

        $data = $request->validate([
            'cooperativa'             => ['nullable', 'string', 'max:255'],
            'taxa_inss'               => ['nullable', 'numeric'],   // campo da Taxa+INSS → vira linha no total
            'valor_coop_erpserv'      => ['nullable', 'numeric'],
            'valor_coop_bizify'       => ['nullable', 'numeric'],
            'taxa_coop_erpserv'       => ['nullable', 'numeric'],
            'taxa_coop_bizify'        => ['nullable', 'numeric'],
            'adiantamento_coop'       => ['nullable', 'in:erpserv,bizify'],
            'lancamentos'             => ['present', 'array'],
            'lancamentos.*.descricao' => ['nullable', 'string', 'max:255'],
            'lancamentos.*.valor'     => ['nullable', 'numeric'],   // negativo = desconto; entra no total
            'lancamentos.*.coop'      => ['nullable', 'in:erpserv,bizify'], // coop do lançamento (divisão)
        ]);

        $uid = $request->user()->id;
        DB::transaction(function () use ($data, $userId, $yearMonth, $uid, $header) {
            FechamentoDiretoriaItem::where('user_id', $userId)->where('year_month', $yearMonth)->delete();
            foreach (array_values($data['lancamentos']) as $ordem => $item) {
                $desc  = trim((string) ($item['descricao'] ?? ''));
                $valor = isset($item['valor']) && $item['valor'] !== null && $item['valor'] !== '' ? round((float) $item['valor'], 2) : null;
                if ($desc === '' && $valor === null) {
                    continue; // pula lançamento vazio
                }
                $coop = in_array($item['coop'] ?? null, ['erpserv', 'bizify'], true) ? $item['coop'] : null;
                FechamentoDiretoriaItem::create([
                    'year_month' => $yearMonth,
                    'user_id'    => (int) $userId,
                    'ordem'      => $ordem,
                    'descricao'  => $desc ?: null,
                    'valor'      => $valor,
                    'coop'       => $coop,
                    'created_by' => $uid,
                ]);
            }
            // Cabeçalho: rótulo + Taxa+INSS (campo que vira linha) + divisão por coop.
            $val = fn ($k) => isset($data[$k]) && $data[$k] !== null && $data[$k] !== '' ? round((float) $data[$k], 2) : null;
            $header->cooperativa        = $data['cooperativa'] ?? null;
            $header->taxa_inss          = $val('taxa_inss');
            $header->valor_coop_erpserv = $val('valor_coop_erpserv');
            $header->valor_coop_bizify  = $val('valor_coop_bizify');
            $header->taxa_coop_erpserv  = $val('taxa_coop_erpserv');
            $header->taxa_coop_bizify   = $val('taxa_coop_bizify');
            $header->adiantamento_coop  = in_array($data['adiantamento_coop'] ?? null, ['erpserv', 'bizify'], true) ? $data['adiantamento_coop'] : null;
            if (!$header->exists) {
                $header->status = FechamentoDiretoria::STATUS_ABERTO;
            }
            $header->save();
        });

        return $this->show($userId, $yearMonth);
    }

    /** POST /fechamento-diretoria/{userId}/{yearMonth}/finalizar */
    public function finalizar(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        if (!$this->authorizeManage($request)) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }
        $header = FechamentoDiretoria::firstOrNew(['user_id' => (int) $userId, 'year_month' => $yearMonth]);
        $header->status = FechamentoDiretoria::STATUS_FINALIZADO;
        $header->finalized_at = now();
        $header->finalized_by = $request->user()->id;
        $header->save();

        return $this->show($userId, $yearMonth);
    }

    /** POST /fechamento-diretoria/{userId}/{yearMonth}/reabrir */
    public function reabrir(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        if (!$this->authorizeManage($request)) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }
        $header = FechamentoDiretoria::where('user_id', $userId)->where('year_month', $yearMonth)->first();
        if ($header) {
            $header->update(['status' => FechamentoDiretoria::STATUS_ABERTO, 'finalized_at' => null, 'finalized_by' => null]);
        }
        return $this->show($userId, $yearMonth);
    }

    /** Folha do diretor (usuário) no mês — para montar o repasse no fechamento. */
    public function folha(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        if (!$this->authorizeManage($request)) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }

        $row = app(FolhaPagamentoController::class)->rowForUser($yearMonth, (int) $userId);
        if (!$row) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'nome'          => $row['nome'] ?? null,
            'producao'      => (float) ($row['producao'] ?? 0),
            'variavel'      => (float) ($row['variavel'] ?? 0),
            'reemb'         => (float) ($row['reemb'] ?? 0),
            'reemb_auto'    => (float) ($row['reemb_auto'] ?? 0),
            'descontos'     => (float) ($row['descontos'] ?? 0),
            'adiantamento'  => (float) ($row['adiantamento'] ?? 0),
            'total_rend'    => (float) ($row['total_rend'] ?? 0),
            'total_debitos' => (float) ($row['total_debitos'] ?? 0),
            'liquido'       => (float) ($row['liquido'] ?? 0),
        ]]);
    }

    /** POST /fechamento-diretoria/{userId}/{yearMonth}/enviar-email */
    public function enviarEmail(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        if (!$this->authorizeManage($request)) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }
        $sender = $request->user();
        $validated = $request->validate([
            'emails'   => ['required', 'array', 'min:1'],
            'emails.*' => ['email'],
            'mensagem' => ['nullable', 'string', 'max:5000'],
        ]);
        $to = array_values(array_unique(array_filter($validated['emails'])));
        if (!$to) {
            return response()->json(['message' => 'Informe ao menos um e-mail.'], 422);
        }

        $user    = User::find($userId);
        $nome    = $user?->name ?? 'Diretor';
        $subject = 'Fechamento Diretoria - ' . $this->periodoMMAAAA($yearMonth) . ' | ' . $nome;

        // Central de workflows: papéis em cópia (administrativo/financeiro) configuráveis pela Central.
        $wf = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('fechamento.diretoria', ['actor' => $sender, 'user' => $user]);
        $cc = array_values(array_diff(array_unique(array_merge($wf['to'], $wf['cc'])), $to));
        $body    = $this->buildEmailBody((int) $userId, $yearMonth, $validated['mensagem'] ?? null, $sender->name ?? 'ERPSERV Consultoria');

        // Relatório (padrão consultor) em PDF anexo.
        $dir = storage_path('app/fechamentos');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $safe    = preg_replace('/[^A-Za-z0-9_\- ]/', '', $nome) ?: 'diretor';
        $pdfName = "Fechamento_Diretoria_{$yearMonth}_{$safe}.pdf";
        $pdfPath = $dir . '/' . $pdfName;

        try {
            $pdf = Pdf::loadView('pdf.fechamento-diretoria', $this->buildReportView((int) $userId, $yearMonth))->setPaper('a4');
            file_put_contents($pdfPath, $pdf->output());

            if (GraphMailer::enabled() && filled($sender->email)) {
                GraphMailer::sendAs($sender->email, $to, $cc, $subject, $body, [$pdfPath]);
            } else {
                Mail::html($body, function ($m) use ($to, $cc, $subject, $pdfPath, $pdfName) {
                    $m->to($to)->subject($subject)->attach($pdfPath, ['as' => $pdfName, 'mime' => 'application/pdf']);
                    if ($cc) {
                        $m->cc($cc);
                    }
                });
            }
        } catch (\Throwable $e) {
            @unlink($pdfPath);
            return response()->json(['message' => 'Falha ao enviar: ' . $e->getMessage()], 500);
        }
        @unlink($pdfPath);

        FechamentoSendStatus::marcarEnviado(FechamentoSendStatus::TIPO_DIRETORIA, (int) $userId, $yearMonth, (int) $sender->id);

        return $this->show($userId, $yearMonth);
    }

    private function periodoMMAAAA(string $yearMonth): string
    {
        $meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        [$y, $m] = array_pad(explode('-', $yearMonth), 2, '');
        return ($meses[(int) $m] ?? $m) . '/' . $y;
    }

    /**
     * GET/POST /fechamento-diretoria/{userId}/{yearMonth}/report-html — relatório (padrão consultor).
     * Se vierem dados no corpo (POST), monta com os dados da TELA (preview ao vivo); senão usa o banco.
     */
    public function reportHtml(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        $ov = $request->isMethod('post') && $request->has('lancamentos')
            ? $request->only(['lancamentos', 'taxa_inss', 'cooperativa', 'valor_coop_erpserv', 'valor_coop_bizify', 'taxa_coop_erpserv', 'taxa_coop_bizify'])
            : null;
        $html = view('pdf.fechamento-diretoria', $this->buildReportView((int) $userId, $yearMonth, $ov))->render();
        return response()->json(['html' => $html]);
    }

    /** POST /fechamento-diretoria/{userId}/{yearMonth}/email-preview — prévia do CORPO do e-mail. */
    public function emailPreview(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        $html = $this->buildEmailBody((int) $userId, $yearMonth, $request->input('mensagem'), $sender->name ?? 'ERPSERV Consultoria');
        return response()->json(['html' => $html]);
    }

    /** Corpo do e-mail — padrão dos fechamentos (card/logo); relatório vai em PDF anexo. */
    private function buildEmailBody(int $userId, string $yearMonth, ?string $mensagem, string $senderName): string
    {
        $rv = $this->buildReportView($userId, $yearMonth);
        return view('emails.fechamento.diretoria', [
            'diretorNome' => $rv['diretorNome'],
            'periodo'     => $rv['periodo'],
            'mensagem'    => $mensagem ?? '',
            'valorTotal'  => $rv['liquidoFmt'],
            'senderName'  => $senderName,
        ])->render();
    }

    /**
     * Dados do relatório (padrão consultor) — usado no preview e no e-mail.
     * $ov (override): se passado, usa os dados da TELA (preview ao vivo) em vez do banco.
     */
    private function buildReportView(int $userId, string $yearMonth, ?array $ov = null): array
    {
        $header  = FechamentoDiretoria::where('user_id', $userId)->where('year_month', $yearMonth)->first();
        if ($ov !== null) {
            $lancs = collect($ov['lancamentos'] ?? [])->map(fn ($l) => [
                'descricao' => $l['descricao'] ?? null,
                'valor'     => isset($l['valor']) && $l['valor'] !== null && $l['valor'] !== '' ? (float) $l['valor'] : null,
            ])->all();
            $taxa     = (float) ($ov['taxa_inss'] ?? 0);
            $coopOver = $ov['cooperativa'] ?? null;
            $valErp = (float) ($ov['valor_coop_erpserv'] ?? 0); $taxErp = (float) ($ov['taxa_coop_erpserv'] ?? 0);
            $valBiz = (float) ($ov['valor_coop_bizify'] ?? 0);  $taxBiz = (float) ($ov['taxa_coop_bizify'] ?? 0);
        } else {
            $lancs    = $this->itensDoDiretor($userId, $yearMonth);
            $taxa     = (float) ($header?->taxa_inss ?? 0);
            $coopOver = $header?->cooperativa;
            $valErp = (float) ($header?->valor_coop_erpserv ?? 0); $taxErp = (float) ($header?->taxa_coop_erpserv ?? 0);
            $valBiz = (float) ($header?->valor_coop_bizify ?? 0);  $taxBiz = (float) ($header?->taxa_coop_bizify ?? 0);
        }
        // Adiantamento (parcelas da rotina) do mês — sempre do banco; UMA linha de desconto
        // por adiantamento (nunca vem do payload da tela, evitando dupla contagem).
        foreach (\App\Models\Adiantamento::parcelasNoMes('consultor', $userId, $yearMonth) as $a) {
            $lancs[] = ['descricao' => 'Adiantamento' . ($a['legenda'] ? " ({$a['legenda']})" : ''), 'valor' => -$a['valor']];
        }

        // Lançamentos (salário − descontos) somam o VALOR A RECEBER (líquido do diretor).
        // REPASSE à cooperativa = valor a receber + Taxa+INSS (gross-up); taxa incide no repasse.
        $somaLan = round(collect($lancs)->sum(fn ($l) => (float) ($l['valor'] ?? 0)), 2);
        $liquido = $somaLan;                          // VALOR A RECEBER
        $total   = round($somaLan + $taxa, 2);        // REPASSE
        $u       = User::find($userId);
        $coop    = $coopOver ?: ($u?->name ?? '');
        $brl = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');

        $logoFile    = public_path('logo-erpserv.png');
        $logoDataUri = is_file($logoFile) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoFile)) : null;

        return [
            'diretorNome' => $u?->name ?? 'Diretor',
            'cooperativa' => $coop,
            'periodo'     => $this->periodoMMAAAA($yearMonth),
            'logoDataUri' => $logoDataUri,
            'lancamentos' => array_map(fn ($l) => [
                'descricao' => $l['descricao'] ?? '—',
                'valorFmt'  => $brl($l['valor'] ?? 0),
                'neg'       => ((float) ($l['valor'] ?? 0)) < 0,
            ], $lancs),
            'temTaxa'    => $taxa != 0.0,
            'taxaFmt'    => $brl($taxa),
            'totalFmt'   => $brl($total),
            'liquidoFmt' => $brl($liquido),
            'temCoop'    => (bool) ($valErp || $taxErp || $valBiz || $taxBiz),
            'coopErpValorFmt' => $brl($valErp), 'coopErpTaxaFmt' => $brl($taxErp), 'coopErpProdFmt' => $brl($valErp + $taxErp),
            'coopBizValorFmt' => $brl($valBiz), 'coopBizTaxaFmt' => $brl($taxBiz), 'coopBizProdFmt' => $brl($valBiz + $taxBiz),
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Legenda do adiantamento: descrição + parcela (NN/TT), ex.: "Empréstimo · 01/24". */
    private function adiantamentoLegenda(int $userId, string $yearMonth): ?string
    {
        $parts = array_filter([
            \App\Models\Adiantamento::descricaoNoMes('consultor', $userId, $yearMonth),
            \App\Models\Adiantamento::parcelaLabelNoMes('consultor', $userId, $yearMonth),
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function itensDoDiretor(int $userId, string $yearMonth): array
    {
        return FechamentoDiretoriaItem::where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->orderBy('ordem')->orderBy('id')
            ->get()
            ->map(fn ($i) => [
                'id'        => $i->id,
                'descricao' => $i->descricao,
                'valor'     => $i->valor !== null ? (float) $i->valor : null,
                'coop'      => $i->coop,
            ])->all();
    }
}
