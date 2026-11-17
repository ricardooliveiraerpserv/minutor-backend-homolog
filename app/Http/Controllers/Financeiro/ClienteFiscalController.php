<?php

namespace App\Http\Controllers\Financeiro;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dados fiscais do cliente (tomador da NFS-e). Endpoint próprio do Financeiro para não
 * mexer no CRUD de clientes existente. Customer é compartilhado entre empresas.
 */
class ClienteFiscalController extends FinanceiroController
{
    private const CAMPOS = [
        'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'codigo_ibge',
        'inscricao_municipal', 'inscricao_estadual', 'regime_tributario', 'email_cobranca',
    ];

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->autorizar($request);
        return response()->json($this->payload($customer));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->autorizar($request);
        $data = $request->validate([
            'cep' => 'nullable|string|max:9',
            'logradouro' => 'nullable|string|max:255',
            'numero' => 'nullable|string|max:20',
            'complemento' => 'nullable|string|max:255',
            'bairro' => 'nullable|string|max:255',
            'cidade' => 'nullable|string|max:255',
            'uf' => 'nullable|string|size:2',
            'codigo_ibge' => 'nullable|digits:7',
            'inscricao_municipal' => 'nullable|string|max:30',
            'inscricao_estadual' => 'nullable|string|max:30',
            'regime_tributario' => ['nullable', Rule::in(['simples', 'lucro_presumido', 'lucro_real', 'mei', 'isento', 'outro'])],
            'email_cobranca' => 'nullable|string|max:2000',
        ]);

        if (! empty($data['email_cobranca'])) {
            foreach (preg_split('/[;,\s]+/', $data['email_cobranca'], -1, PREG_SPLIT_NO_EMPTY) as $e) {
                abort_unless(filter_var($e, FILTER_VALIDATE_EMAIL), 422, "E-mail de cobrança inválido: {$e}");
            }
        }
        if (! empty($data['uf'])) {
            $data['uf'] = strtoupper($data['uf']);
        }

        $customer->forceFill($data)->save(); // forceFill: campos não estão no $fillable do CRUD geral
        return response()->json($this->payload($customer->fresh()));
    }

    private function payload(Customer $c): array
    {
        $fiscal = $c->only(self::CAMPOS);
        $faltando = array_values(array_filter(
            ['cep', 'logradouro', 'numero', 'cidade', 'uf', 'codigo_ibge'],
            fn ($k) => empty($c->{$k})
        ));
        return [
            'id' => $c->id, 'name' => $c->name, 'company_name' => $c->company_name, 'cgc' => $c->cgc,
            'fiscal' => $fiscal,
            'pronto_para_nfse' => ! empty($c->cgc) && $faltando === [],
            'campos_faltando' => $faltando,
        ];
    }
}
