<?php

namespace App\Services\Financeiro;

use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinContractBilling;
use App\Models\Financeiro\FinInstallment;
use App\Models\Financeiro\FinInvoice;
use App\Services\Financeiro\Nfse\NfseEmissor;
use App\Services\Financeiro\Nfse\SimuladoNfseEmissor;
use Illuminate\Support\Facades\DB;

/**
 * Emite o documento de uma parcela "pronta":
 *  - nota_debito → numeração própria da empresa, autorizada na hora;
 *  - nfse        → reserva série/nº da DPS, chama o emissor (simulado ou nacional) e grava o retorno.
 * Garantias: uma nota viva por parcela (lock + checagem), número de DPS nunca reaproveitado,
 * parcela vira "processada" só com nota autorizada; rejeição devolve a parcela a "pronta";
 * falha indefinida (timeout) deixa a nota em "erro" e a parcela travada até conferência manual.
 * NFS-e em PRODUÇÃO só com config('financeiro.nfse_permitir_producao') = true (nunca na DEV2).
 */
class EmissaoService
{
    public function emitir(FinInstallment $parcela, ?int $userId = null): FinInvoice
    {
        [$nota, $empresa] = DB::transaction(function () use ($parcela, $userId) {
            /** @var FinInstallment $p */
            $p = FinInstallment::withoutGlobalScopes()->whereKey($parcela->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== 'pronta') {
                throw new EmissaoException("Parcela #{$p->id} está \"{$p->status}\" — só parcelas prontas podem ser emitidas.");
            }
            if ($p->documento === 'nenhum') {
                throw new EmissaoException('Esta parcela está configurada como "sem documento".');
            }
            if ((float) $p->valor_liquido <= 0) {
                throw new EmissaoException('Valor líquido zerado — nada a emitir.');
            }
            if (FinInvoice::withoutGlobalScopes()->where('installment_id', $p->id)->whereIn('status', ['enviando', 'autorizada', 'erro'])->exists()) {
                throw new EmissaoException('Já existe documento emitido (ou pendente de conferência) para esta parcela.');
            }

            $empresa = FinCompanySetting::where('company_id', $p->company_id)->lockForUpdate()->first()
                ?? throw new EmissaoException('Configure os dados fiscais da empresa (Financeiro › Cadastros) antes de emitir.');

            $base = [
                'company_id' => $p->company_id, 'installment_id' => $p->id, 'contract_id' => $p->contract_id,
                'customer_id' => $p->customer_id, 'tipo' => $p->documento, 'competencia' => $p->competencia,
                'data_emissao' => now()->toDateString(), 'valor_servico' => $p->valor_bruto, 'desconto' => $p->desconto,
                'valor_iss' => $p->valor_iss, 'total_retencoes' => $p->total_retencoes, 'valor_liquido' => $p->valor_liquido,
                'discriminacao' => $this->discriminacao($p), 'tomador' => $this->tomador($p), 'emitida_por' => $userId,
            ];

            if ($p->documento === 'nota_debito') {
                $numero = (int) $empresa->nd_proximo_numero ?: 1;
                $empresa->nd_proximo_numero = $numero + 1;
                $empresa->save();
                $nota = FinInvoice::create($base + [
                    'emissor' => 'proprio', 'status' => 'autorizada', 'numero' => (string) $numero, 'emitida_em' => now(),
                ]);
                $p->update(['status' => 'processada', 'processada_em' => now(), 'updated_by' => $userId]);
                return [$nota, $empresa];
            }

            // NFS-e
            $this->validarNfse($p, $empresa);
            $numeroDps = (int) $empresa->dps_proximo_numero ?: 1;
            $empresa->dps_proximo_numero = $numeroDps + 1; // nunca reaproveita, mesmo se rejeitar
            $empresa->save();
            $nota = FinInvoice::create($base + [
                'emissor' => $this->emissor($empresa)->nome(), 'ambiente' => $empresa->nfse_ambiente, 'status' => 'enviando',
                'serie' => $empresa->dps_serie ?: '1', 'numero_dps' => $numeroDps,
            ]);
            return [$nota, $empresa];
        });

        if ($nota->tipo !== 'nfse') {
            return $nota;
        }

        // Chamada externa FORA da transação (não segura lock durante a rede).
        try {
            $r = $this->emissor($empresa)->emitir($nota, $empresa);
        } catch (\Throwable $e) {
            $nota->update(['status' => 'erro', 'mensagens' => [['codigo' => 'EXC', 'descricao' => mb_substr($e->getMessage(), 0, 500)]]]);
            return $nota->fresh();
        }

        DB::transaction(function () use ($nota, $r, $userId) {
            if ($r->autorizada) {
                $nota->update([
                    'status' => 'autorizada', 'numero' => $r->numero, 'chave_acesso' => $r->chaveAcesso,
                    'codigo_verificacao' => $r->codigoVerificacao, 'xml_dps' => $r->xmlDps, 'xml_nfse' => $r->xmlNfse,
                    'mensagens' => $r->mensagens ?: null, 'emitida_em' => now(),
                ]);
                FinInstallment::withoutGlobalScopes()->whereKey($nota->installment_id)
                    ->update(['status' => 'processada', 'processada_em' => now(), 'updated_by' => $userId]);
            } else {
                // rejeitada: parcela continua "pronta" para corrigir e emitir de novo (novo nº de DPS);
                // indefinida: fica em "erro" e a parcela não pode ser reemitida até conferir na API.
                $nota->update(['status' => $r->indefinido ? 'erro' : 'rejeitada', 'xml_dps' => $r->xmlDps, 'mensagens' => $r->mensagens]);
            }
        });

        return $nota->fresh();
    }

    public function emissor(?FinCompanySetting $empresa = null): NfseEmissor
    {
        return match ($empresa?->nfse_emissor ?: config('financeiro.nfse_emissor')) {
            'nacional' => app(\App\Services\Financeiro\Nfse\NacionalNfseEmissor::class),
            default => app(SimuladoNfseEmissor::class),
        };
    }

    private function validarNfse(FinInstallment $p, FinCompanySetting $empresa): void
    {
        $producao = $empresa->nfse_ambiente === 'producao';
        if ($producao && ! config('financeiro.nfse_permitir_producao')) {
            throw new EmissaoException('Emissão de NFS-e em PRODUÇÃO está bloqueada neste ambiente (FIN_NFSE_PERMITIR_PRODUCAO).');
        }
        if ($producao && $this->emissor($empresa)->nome() === 'simulado') {
            throw new EmissaoException('O emissor simulado só pode ser usado com a NFS-e em homologação.');
        }
        if ($this->emissor($empresa)->nome() === 'nacional') {
            if (! $empresa->tem_certificado || ! $empresa->certificado_valido_ate?->isFuture()) {
                throw new EmissaoException('Certificado A1 ausente ou vencido (Financeiro › Cadastros › Empresa).');
            }
        }
        $faltando = [];
        foreach (['inscricao_municipal' => 'IM da empresa', 'codigo_ibge' => 'IBGE da empresa'] as $k => $rot) {
            if (empty($empresa->{$k})) $faltando[] = $rot;
        }
        $c = $p->customer;
        if (! $c || empty($c->cgc)) $faltando[] = 'CNPJ/CPF do cliente';
        foreach (['logradouro', 'numero', 'cidade', 'uf', 'codigo_ibge', 'cep'] as $k) {
            if ($c && empty($c->{$k})) $faltando[] = "{$k} do cliente";
        }
        if ($faltando) {
            throw new EmissaoException('Dados fiscais incompletos: ' . implode(', ', $faltando) . '.');
        }
    }

    private function tomador(FinInstallment $p): ?array
    {
        $c = $p->customer;
        return $c ? $c->only(['id', 'name', 'company_name', 'cgc', 'cep', 'logradouro', 'numero', 'complemento', 'bairro',
            'cidade', 'uf', 'codigo_ibge', 'inscricao_municipal', 'email_cobranca']) : null;
    }

    /** Discriminação a partir do modelo do contrato ({competencia} {contrato} {horas} {valor_liquido} {descricao}). */
    public function discriminacao(FinInstallment $p): string
    {
        $cfg = $p->contract ? FinContractBilling::firstWhere('contract_id', $p->contract_id) : null;
        $modelo = trim((string) ($cfg?->discriminacao ?? ''))
            ?: 'Prestação de serviços de consultoria em sistemas — {descricao}. Competência {competencia}.';
        $proj = $p->project;
        return strtr($modelo, [
            '{competencia}' => substr($p->competencia, 5, 2) . '/' . substr($p->competencia, 0, 4),
            '{contrato}' => trim(($proj?->code ? $proj->code . ' ' : '') . ($proj?->name ?? '')),
            '{horas}' => $p->horas !== null ? number_format((float) $p->horas, 2, ',', '.') : '',
            '{valor_liquido}' => 'R$ ' . number_format((float) $p->valor_liquido, 2, ',', '.'),
            '{descricao}' => (string) $p->descricao,
        ]);
    }
}
