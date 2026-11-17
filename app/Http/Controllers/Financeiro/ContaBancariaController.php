<?php

namespace App\Http\Controllers\Financeiro;

use App\Models\Financeiro\FinAuditLog;
use App\Models\Financeiro\FinBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Contas bancárias da empresa ativa + credenciais do gateway de cobrança (cifradas). */
class ContaBancariaController extends FinanceiroController
{
    public function index(Request $request): JsonResponse
    {
        $this->autorizar($request);
        return response()->json($this->daEmpresa(FinBankAccount::class, $request)->orderByDesc('ativo')->orderBy('nome')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $conta = new FinBankAccount($this->validar($request));
        $conta->company_id = $this->empresaId($request);
        $conta->save();
        return response()->json($conta, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->autorizar($request);
        $conta = $this->daEmpresa(FinBankAccount::class, $request)->findOrFail($id);
        $conta->update($this->validar($request, parcial: true));
        return response()->json($conta->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->autorizar($request);
        $conta = $this->daEmpresa(FinBankAccount::class, $request)->findOrFail($id);
        // F1 ainda não tem movimentos; quando houver (F4/F6), bloquear exclusão de conta movimentada.
        $conta->delete();
        return response()->json(['ok' => true]);
    }

    /** Grava (ou limpa) as credenciais do gateway. Nunca devolve o conteúdo. */
    public function credenciais(Request $request, int $id): JsonResponse
    {
        $this->autorizar($request);
        $conta = $this->daEmpresa(FinBankAccount::class, $request)->findOrFail($id);
        $data = $request->validate([
            'credenciais' => 'nullable|array',
            'credenciais.*' => 'nullable|string|max:20000',
        ]);

        $conta->gateway_credenciais = $data['credenciais'] ?: null;
        $conta->save();

        FinAuditLog::registrar($request, $conta->company_id, empty($data['credenciais']) ? 'gateway.credenciais.removidas' : 'gateway.credenciais.gravadas', $conta, [
            'gateway' => $conta->gateway,
            'campos' => array_keys($data['credenciais'] ?? []), // só os NOMES dos campos
        ]);

        return response()->json($conta->fresh());
    }

    private function validar(Request $request, bool $parcial = false): array
    {
        $req = $parcial ? 'sometimes' : 'required';
        return $request->validate([
            'nome' => "$req|string|max:120",
            'banco_codigo' => 'nullable|string|max:5',
            'agencia' => 'nullable|string|max:10',
            'conta' => 'nullable|string|max:20',
            'tipo' => ['sometimes', Rule::in(FinBankAccount::TIPOS)],
            'gateway' => ['sometimes', Rule::in(FinBankAccount::GATEWAYS)],
            'saldo_inicial' => 'sometimes|numeric',
            'saldo_inicial_data' => 'nullable|date',
            'ativo' => 'sometimes|boolean',
        ]);
    }
}
