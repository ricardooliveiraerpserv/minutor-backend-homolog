<?php

namespace App\Services\Financeiro\Billing;

use Illuminate\Support\Facades\Cache;

/**
 * Gateway SIMULADO: não fala com banco nenhum. Gera nosso número, linha digitável e PIX
 * fictícios (determinísticos pelo seuNumero) e guarda o estado em cache para permitir
 * "marcar como pago" e testar a baixa ponta a ponta na DEV2 enquanto as credenciais do
 * Banco Inter não chegam. Nunca deve ser usado em produção (ver BillingGatewayFactory).
 */
class FakeBillingGateway implements BillingGateway
{
    private const TTL = 60 * 60 * 24 * 120;

    public function nome(): string
    {
        return 'fake';
    }

    public function registrar(ChargeRequest $request): ChargeResult
    {
        $codigo = 'FAKE-' . substr(hash('sha256', $request->seuNumero), 0, 20);

        $existente = Cache::get($this->key($codigo));
        if ($existente) {
            return $this->hidratar($existente); // idempotente
        }

        $nosso = str_pad((string) (hexdec(substr(hash('crc32b', $request->seuNumero), 0, 7)) % 10000000000), 11, '0', STR_PAD_LEFT);
        $centavos = str_pad(str_replace('.', '', number_format((float) $request->valor, 2, '.', '')), 10, '0', STR_PAD_LEFT);
        $fator = str_pad((string) max(1000, $request->vencimento->diffInDays(\Carbon\Carbon::create(2025, 2, 22)) + 1000), 4, '0', STR_PAD_LEFT);

        $estado = [
            'codigoSolicitacao' => $codigo,
            'situacao' => ChargeResult::REGISTRADA,
            'nossoNumero' => $nosso,
            'linhaDigitavel' => "00000.00000 {$nosso[0]}{$nosso[1]}{$nosso[2]}{$nosso[3]}{$nosso[4]}.000000 00000.000000 0 {$fator}{$centavos}",
            'codigoBarras' => '0000' . $fator . $centavos . str_pad($nosso, 25, '0', STR_PAD_LEFT),
            'pixCopiaECola' => '00020126SIMULADO-MINUTOR-' . $codigo . '5204000053039865406' . $request->valor . '6304FAKE',
            'valor' => $request->valor,
            'valorPago' => null,
            'dataPagamento' => null,
        ];
        Cache::put($this->key($codigo), $estado, self::TTL);

        return $this->hidratar($estado);
    }

    public function consultar(string $codigoSolicitacao): ChargeResult
    {
        $estado = Cache::get($this->key($codigoSolicitacao));
        if (! $estado) {
            throw new \RuntimeException('Cobrança simulada não encontrada.');
        }
        return $this->hidratar($estado);
    }

    public function cancelar(string $codigoSolicitacao, string $motivo): ChargeResult
    {
        return $this->mudar($codigoSolicitacao, ['situacao' => ChargeResult::CANCELADA]);
    }

    /** Só existe no simulado: imita o webhook de pagamento do banco. */
    public function simularPagamento(string $codigoSolicitacao, ?string $valorPago = null, ?string $data = null): ChargeResult
    {
        $estado = Cache::get($this->key($codigoSolicitacao));
        if (! $estado) {
            throw new \RuntimeException('Cobrança simulada não encontrada.');
        }
        if ($estado['situacao'] === ChargeResult::CANCELADA) {
            throw new \RuntimeException('Cobrança cancelada não pode ser paga.');
        }
        return $this->mudar($codigoSolicitacao, [
            'situacao' => ChargeResult::PAGA,
            'valorPago' => $valorPago ?? $estado['valor'],
            'dataPagamento' => $data ?? now()->toDateString(),
        ]);
    }

    private function mudar(string $codigo, array $campos): ChargeResult
    {
        $estado = Cache::get($this->key($codigo));
        if (! $estado) {
            throw new \RuntimeException('Cobrança simulada não encontrada.');
        }
        $estado = array_merge($estado, $campos);
        Cache::put($this->key($codigo), $estado, self::TTL);
        return $this->hidratar($estado);
    }

    private function hidratar(array $e): ChargeResult
    {
        return new ChargeResult(
            codigoSolicitacao: $e['codigoSolicitacao'],
            situacao: $e['situacao'],
            nossoNumero: $e['nossoNumero'],
            linhaDigitavel: $e['linhaDigitavel'],
            codigoBarras: $e['codigoBarras'],
            pixCopiaECola: $e['pixCopiaECola'],
            valorPago: $e['valorPago'],
            dataPagamento: $e['dataPagamento'],
            simulado: true,
        );
    }

    private function key(string $codigo): string
    {
        return 'fin:fake-billing:' . $codigo;
    }
}
