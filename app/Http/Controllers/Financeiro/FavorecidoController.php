<?php

namespace App\Http\Controllers\Financeiro;

use App\Models\Financeiro\FinPayee;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Favorecidos de contas a pagar (consultores, cooperativa, parceiros, diretoria, fornecedores). */
class FavorecidoController extends FinanceiroController
{
    public function index(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $q = $this->daEmpresa(FinPayee::class, $request)->with(['user:id,name,email', 'partner:id,name'])->orderBy('nome');
        if ($tipo = $request->query('tipo')) {
            $q->where('tipo', $tipo);
        }
        if ($busca = trim((string) $request->query('busca'))) {
            $dig = preg_replace('/\D/', '', $busca);
            $q->where(fn ($w) => $w->where('nome', 'ilike', "%{$busca}%")
                ->when($dig !== '', fn ($x) => $x->orWhere('documento', 'like', "%{$dig}%")));
        }
        return response()->json($q->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $empresa = $this->empresaId($request);
        $f = new FinPayee($this->validar($request, $empresa));
        $f->company_id = $empresa;
        $f->save();
        return response()->json($f->load(['user:id,name,email', 'partner:id,name']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->autorizar($request);
        $empresa = $this->empresaId($request);
        $f = $this->daEmpresa(FinPayee::class, $request)->findOrFail($id);
        $f->update($this->validar($request, $empresa, $f));
        return response()->json($f->fresh()->load(['user:id,name,email', 'partner:id,name']));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->autorizar($request);
        $this->daEmpresa(FinPayee::class, $request)->findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Pré-cadastra como favorecido "consultor" todo usuário ativo da empresa com CPF que
     * ainda não tem favorecido. Idempotente; não sobrescreve nada já cadastrado.
     */
    public function importarConsultores(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $empresa = $this->empresaId($request);

        $jaTem = $this->daEmpresa(FinPayee::class, $request)->whereNotNull('user_id')->pluck('user_id')->all();
        $usuarios = User::query()
            ->whereHas('companies', fn ($c) => $c->where('companies.id', $empresa))
            ->whereIn('type', ['consultor', 'coordenador'])
            ->where(fn ($q) => $q->whereNull('enabled')->orWhere('enabled', true))
            ->whereNotNull('cpf')->where('cpf', '!=', '')
            ->whereNotIn('id', $jaTem)
            ->get(['id', 'name', 'email', 'cpf']);

        $criados = 0;
        foreach ($usuarios as $u) {
            $f = new FinPayee([
                'tipo' => 'consultor', 'user_id' => $u->id, 'nome' => $u->name,
                'documento' => $u->cpf, 'email' => $u->email, 'ativo' => true,
            ]);
            $f->company_id = $empresa;
            $f->save();
            $criados++;
        }

        return response()->json(['criados' => $criados]);
    }

    private function validar(Request $request, int $empresa, ?FinPayee $f = null): array
    {
        $req = $f ? 'sometimes' : 'required';
        $data = $request->validate([
            'tipo' => [$req, Rule::in(FinPayee::TIPOS)],
            'nome' => "$req|string|max:255",
            'user_id' => ['nullable', 'integer', Rule::exists((new User)->getTable(), 'id')],
            'partner_id' => ['nullable', 'integer', Rule::exists((new Partner)->getTable(), 'id')],
            'documento' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'pix_tipo' => ['nullable', Rule::in(FinPayee::PIX_TIPOS)],
            'pix_chave' => 'nullable|string|max:140',
            'banco_codigo' => 'nullable|string|max:5',
            'agencia' => 'nullable|string|max:10',
            'conta' => 'nullable|string|max:20',
            'chart_account_id' => ['nullable', 'integer', Rule::exists('fin_chart_accounts', 'id')->where('company_id', $empresa)],
            'ativo' => 'sometimes|boolean',
        ]);

        if (! empty($data['documento'])) {
            $dig = preg_replace('/\D/', '', $data['documento']);
            abort_unless(in_array(strlen($dig), [11, 14], true), 422, 'CPF/CNPJ deve ter 11 ou 14 dígitos.');
        }
        return $data;
    }
}
