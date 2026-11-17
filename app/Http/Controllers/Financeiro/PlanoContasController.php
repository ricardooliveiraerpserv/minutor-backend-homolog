<?php

namespace App\Http\Controllers\Financeiro;

use App\Models\Financeiro\FinChartAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Plano de contas da empresa ativa (com grupo da DRE). */
class PlanoContasController extends FinanceiroController
{
    public function index(Request $request): JsonResponse
    {
        $this->autorizar($request);
        return response()->json($this->daEmpresa(FinChartAccount::class, $request)->orderBy('codigo')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $empresa = $this->empresaId($request);
        $data = $this->validar($request, $empresa);
        $conta = new FinChartAccount($data);
        $conta->company_id = $empresa;
        $conta->save();
        return response()->json($conta, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->autorizar($request);
        $empresa = $this->empresaId($request);
        $conta = $this->daEmpresa(FinChartAccount::class, $request)->findOrFail($id);
        $data = $this->validar($request, $empresa, $conta);
        abort_if(isset($data['parent_id']) && (int) $data['parent_id'] === $conta->id, 422, 'Uma conta não pode ser filha dela mesma.');
        $conta->update($data);
        return response()->json($conta->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->autorizar($request);
        $conta = $this->daEmpresa(FinChartAccount::class, $request)->findOrFail($id);
        abort_if($conta->children()->exists(), 422, 'Remova ou mova as subcontas antes.');
        // Quando existirem títulos (F4/F5), inativar em vez de excluir conta usada.
        $conta->delete();
        return response()->json(['ok' => true]);
    }

    /** Cria a estrutura padrão sugerida — só se a empresa ainda não tiver plano. */
    public function aplicarPadrao(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $empresa = $this->empresaId($request);
        abort_if($this->daEmpresa(FinChartAccount::class, $request)->exists(), 422, 'Esta empresa já tem plano de contas.');

        DB::transaction(function () use ($empresa) {
            $ids = [];
            foreach (FinChartAccount::PADRAO as [$codigo, $nome, $natureza, $grupo, $aceita]) {
                $pai = str_contains($codigo, '.') ? ($ids[strtok($codigo, '.')] ?? null) : null;
                $c = new FinChartAccount([
                    'codigo' => $codigo, 'nome' => $nome, 'natureza' => $natureza, 'grupo_dre' => $grupo,
                    'aceita_lancamento' => $aceita, 'parent_id' => $pai, 'ativo' => true,
                ]);
                $c->company_id = $empresa;
                $c->save();
                $ids[$codigo] = $c->id;
            }
        });

        return response()->json($this->daEmpresa(FinChartAccount::class, $request)->orderBy("codigo")->get(), 201);
    }

    private function validar(Request $request, int $empresa, ?FinChartAccount $conta = null): array
    {
        $req = $conta ? 'sometimes' : 'required';
        return $request->validate([
            'codigo' => [$req, 'string', 'max:30',
                Rule::unique('fin_chart_accounts', 'codigo')->where('company_id', $empresa)->ignore($conta?->id)],
            'nome' => "$req|string|max:255",
            'natureza' => [$req, Rule::in(FinChartAccount::NATUREZAS)],
            'grupo_dre' => ['nullable', Rule::in(FinChartAccount::GRUPOS_DRE)],
            'parent_id' => ['nullable', 'integer', Rule::exists('fin_chart_accounts', 'id')->where('company_id', $empresa)],
            'aceita_lancamento' => 'sometimes|boolean',
            'ativo' => 'sometimes|boolean',
        ]);
    }
}
