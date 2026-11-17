<?php

namespace App\Http\Controllers\Financeiro;

use App\Models\Financeiro\FinAuditLog;
use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinInstallment;
use App\Models\Financeiro\FinInvoice;
use App\Services\Financeiro\EmissaoException;
use App\Services\Financeiro\EmissaoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** F3 — emissão e consulta de NFS-e / Nota de Débito a partir das parcelas. */
class NotaController extends FinanceiroController
{
    public function index(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $request->validate(['competencia' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $q = $this->daEmpresa(FinInvoice::class, $request)
            ->with(['customer:id,name,company_name,cgc', 'installment:id,origem,descricao'])
            ->orderByDesc('id');
        if ($ym = $request->query('competencia')) $q->where('competencia', $ym);
        if ($s = $request->query('status')) $q->where('status', $s);
        if ($t = $request->query('tipo')) $q->where('tipo', $t);
        return response()->json($q->limit(500)->get());
    }

    public function emitir(Request $request, int $parcela, EmissaoService $svc): JsonResponse
    {
        $this->autorizar($request);
        $p = $this->daEmpresa(FinInstallment::class, $request)->with(['customer', 'project', 'contract'])->findOrFail($parcela);
        try {
            $nota = $svc->emitir($p, $request->user()->id);
        } catch (EmissaoException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json($nota->load('customer:id,name,company_name'), $nota->status === 'autorizada' ? 201 : 422);
    }

    /** Emite várias parcelas prontas; cada uma independente (falha de uma não para as outras). */
    public function emitirLote(Request $request, EmissaoService $svc): JsonResponse
    {
        $this->autorizar($request);
        $data = $request->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer']);
        $parcelas = $this->daEmpresa(FinInstallment::class, $request)->with(['customer', 'project', 'contract'])
            ->whereIn('id', $data['ids'])->get();
        $res = ['autorizadas' => 0, 'falhas' => []];
        foreach ($parcelas as $p) {
            try {
                $n = $svc->emitir($p, $request->user()->id);
                if ($n->status === 'autorizada') { $res['autorizadas']++; continue; }
                $res['falhas'][] = ['parcela_id' => $p->id, 'cliente' => $p->customer?->name, 'motivo' => collect($n->mensagens)->pluck('descricao')->implode(' | ')];
            } catch (EmissaoException $e) {
                $res['falhas'][] = ['parcela_id' => $p->id, 'cliente' => $p->customer?->name, 'motivo' => $e->getMessage()];
            }
        }
        return response()->json($res);
    }

    public function pdf(Request $request, int $id): Response
    {
        $this->autorizar($request);
        $nota = $this->daEmpresa(FinInvoice::class, $request)->with(['installment', 'company'])->findOrFail($id);
        abort_unless(in_array($nota->status, ['autorizada', 'cancelada'], true), 422, 'Documento não autorizado.');
        $empresa = FinCompanySetting::forCompany($nota->company_id);
        $pdf = Pdf::loadView('financeiro.documento', [
            'nota' => $nota, 'empresa' => $empresa, 'parcela' => $nota->installment,
            'despesas' => $nota->installment?->detalhes['despesas'] ?? [],
        ])->setPaper('a4');
        $nome = ($nota->tipo === 'nota_debito' ? 'ND-' : 'NFSe-') . $nota->numero . '.pdf';
        return $pdf->stream($nome);
    }

    /**
     * Cancela documento: Nota de Débito e NFS-e SIMULADA são canceladas localmente e a parcela
     * volta a "pronta". NFS-e nacional exige evento de cancelamento na API (a implementar com o leiaute).
     */
    public function cancelar(Request $request, int $id): JsonResponse
    {
        $this->autorizar($request);
        $data = $request->validate(['motivo' => 'required|string|min:15|max:255']);
        $nota = $this->daEmpresa(FinInvoice::class, $request)->findOrFail($id);
        abort_unless($nota->status === 'autorizada', 422, 'Só documentos autorizados podem ser cancelados.');
        abort_if($nota->tipo === 'nfse' && $nota->emissor === 'nacional', 422, 'Cancelamento de NFS-e nacional ainda não disponível (evento na API).');

        DB::transaction(function () use ($nota, $data, $request) {
            $nota->update(['status' => 'cancelada', 'cancelada_em' => now(), 'motivo_cancelamento' => $data['motivo']]);
            FinInstallment::withoutGlobalScopes()->whereKey($nota->installment_id)->update(['status' => 'pronta', 'processada_em' => null, 'updated_by' => $request->user()->id]);
            FinAuditLog::registrar($request, $nota->company_id, 'nota.cancelada', $nota, ['tipo' => $nota->tipo, 'numero' => $nota->numero, 'motivo' => $data['motivo']]);
        });
        return response()->json($nota->fresh());
    }

    /**
     * Nota em "erro" (falha de comunicação: pode ou não ter sido emitida). Depois de conferir no
     * portal/API que NÃO foi emitida, o usuário descarta e a parcela volta a poder ser emitida.
     */
    public function descartar(Request $request, int $id): JsonResponse
    {
        $this->autorizar($request);
        $data = $request->validate(['conferido' => 'accepted', 'observacao' => 'nullable|string|max:255']);
        $nota = $this->daEmpresa(FinInvoice::class, $request)->findOrFail($id);
        abort_unless(in_array($nota->status, ['erro', 'rejeitada'], true), 422, 'Só notas com erro ou rejeitadas podem ser descartadas.');
        $nota->update(['status' => 'rejeitada', 'mensagens' => array_merge($nota->mensagens ?? [], [[
            'codigo' => 'DESCARTE', 'descricao' => 'Descartada por ' . $request->user()->name . (! empty($data['observacao']) ? ': ' . $data['observacao'] : ''),
        ]])]);
        FinAuditLog::registrar($request, $nota->company_id, 'nota.descartada', $nota, ['numero_dps' => $nota->numero_dps]);
        return response()->json($nota->fresh());
    }
}
