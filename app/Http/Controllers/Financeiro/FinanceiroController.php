<?php

namespace App\Http\Controllers\Financeiro;

use App\Http\Controllers\Controller;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

/**
 * Base do módulo Financeiro. Acesso: admin ou administrativo. Todos os cadastros são da
 * EMPRESA ATIVA (CompanyContext); sem empresa resolvida a requisição é recusada, para
 * nunca gravar dado financeiro sem dono.
 */
abstract class FinanceiroController extends Controller
{
    protected function autorizar(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && ($u->isAdmin() || $u->isAdministrativo()), 403, 'Acesso restrito ao Financeiro.');
    }

    /**
     * Query do model JÁ filtrada pela empresa ativa. Filtro explícito (não depende do
     * CompanyScope, que só age com MULTIEMPRESA_SCOPING ligado).
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    protected function daEmpresa(string $model, Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $m = new $model;
        return $model::query()->where($m->getTable() . '.company_id', $this->empresaId($request));
    }

    protected function empresaId(Request $request): int
    {
        $id = app(CompanyContext::class)->id();
        abort_unless($id, 422, 'Selecione a empresa ativa antes de usar o Financeiro.');
        return (int) $id;
    }
}
