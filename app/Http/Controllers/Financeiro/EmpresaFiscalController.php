<?php

namespace App\Http\Controllers\Financeiro;

use App\Models\Financeiro\FinAuditLog;
use App\Models\Financeiro\FinCompanySetting;
use App\Services\Financeiro\CertificadoA1Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Dados fiscais da empresa emissora + certificado A1 (cofre cifrado). */
class EmpresaFiscalController extends FinanceiroController
{
    public function show(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $s = FinCompanySetting::forCompany($this->empresaId($request))->load('enviadoPor:id,name');
        return response()->json($s);
    }

    public function update(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $data = $request->validate([
            'razao_social' => 'nullable|string|max:255',
            'inscricao_municipal' => 'nullable|string|max:30',
            'regime_tributario' => ['nullable', Rule::in(FinCompanySetting::REGIMES)],
            'codigo_ibge' => 'nullable|digits:7',
            'cep' => 'nullable|string|max:9',
            'logradouro' => 'nullable|string|max:255',
            'numero' => 'nullable|string|max:20',
            'bairro' => 'nullable|string|max:255',
            'cidade' => 'nullable|string|max:255',
            'uf' => 'nullable|string|size:2',
            'aliquota_iss' => 'nullable|numeric|min:0|max:100',
            'aliquota_irrf' => 'nullable|numeric|min:0|max:100',
            'aliquota_csll' => 'nullable|numeric|min:0|max:100',
            'aliquota_pis' => 'nullable|numeric|min:0|max:100',
            'aliquota_cofins' => 'nullable|numeric|min:0|max:100',
            'aliquota_cbs' => 'nullable|numeric|min:0|max:100',
            'aliquota_ibs' => 'nullable|numeric|min:0|max:100',
            'codigo_tributacao_nacional' => 'nullable|string|max:20',
            'dps_serie' => 'nullable|string|max:5',
            'dps_proximo_numero' => 'nullable|integer|min:1',
            'nfse_ambiente' => ['nullable', Rule::in(FinCompanySetting::AMBIENTES)],
            'nfse_emissor' => ['nullable', Rule::in(FinCompanySetting::EMISSORES)],
        ]);

        $s = FinCompanySetting::forCompany($this->empresaId($request));

        // Ir para PRODUÇÃO exige certificado válido e é registrado na auditoria.
        if (($data['nfse_ambiente'] ?? null) === 'producao' && $s->nfse_ambiente !== 'producao') {
            abort_unless($s->tem_certificado && $s->certificado_valido_ate?->isFuture(), 422,
                'Cadastre um certificado A1 válido antes de mudar a NFS-e para produção.');
            FinAuditLog::registrar($request, $s->company_id, 'nfse.ambiente.producao', $s);
        }

        // validate() devolve só as chaves enviadas: o que não veio fica como está; null limpa.
        if (array_key_exists('nfse_ambiente', $data) && $data['nfse_ambiente'] === null) {
            unset($data['nfse_ambiente']); // coluna NOT NULL — ignorar null
        }
        if (($data['nfse_emissor'] ?? null) === 'nacional' && $s->nfse_emissor !== 'nacional') {
            abort_unless($s->tem_certificado && $s->certificado_valido_ate?->isFuture(), 422,
                'Envie um certificado A1 válido antes de usar o emissor nacional.');
            FinAuditLog::registrar($request, $s->company_id, 'nfse.emissor.nacional', $s);
        }
        if (array_key_exists('nfse_emissor', $data) && $data['nfse_emissor'] === null) {
            unset($data['nfse_emissor']);
        }
        if (array_key_exists('dps_proximo_numero', $data) && $data['dps_proximo_numero'] === null) {
            unset($data['dps_proximo_numero']);
        }
        $s->fill($data);
        $s->save();

        return response()->json($s->fresh());
    }

    public function uploadCertificado(Request $request, CertificadoA1Service $svc): JsonResponse
    {
        $this->autorizar($request);
        $request->validate([
            'arquivo' => 'required|file|max:64', // KB; .pfx típico tem 3–10 KB
            'senha' => 'required|string|max:200',
        ]);

        $binario = file_get_contents($request->file('arquivo')->getRealPath());
        try {
            $meta = $svc->inspecionar($binario, (string) $request->input('senha'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $s = FinCompanySetting::forCompany($this->empresaId($request));
        $s->certificado_pfx = base64_encode($binario);
        $s->certificado_senha = (string) $request->input('senha');
        $s->certificado_titular = $meta['titular'];
        $s->certificado_cnpj = $meta['cnpj'];
        $s->certificado_valido_ate = $meta['valido_ate'];
        $s->certificado_enviado_em = now();
        $s->certificado_enviado_por = $request->user()->id;
        $s->save();

        FinAuditLog::registrar($request, $s->company_id, 'certificado.enviado', $s, [
            'titular' => $meta['titular'], 'cnpj' => $meta['cnpj'], 'valido_ate' => $meta['valido_ate']->toDateString(),
        ]);

        return response()->json($s->fresh()->load('enviadoPor:id,name'));
    }

    public function removerCertificado(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $s = FinCompanySetting::forCompany($this->empresaId($request));
        abort_if($s->nfse_ambiente === 'producao', 422, 'Volte a NFS-e para homologação antes de remover o certificado.');

        $s->forceFill([
            'certificado_pfx' => null, 'certificado_senha' => null, 'certificado_titular' => null,
            'certificado_cnpj' => null, 'certificado_valido_ate' => null,
            'certificado_enviado_em' => null, 'certificado_enviado_por' => null,
        ])->save();

        FinAuditLog::registrar($request, $s->company_id, 'certificado.removido', $s);

        return response()->json($s->fresh());
    }
}
