<?php

namespace App\Http\Controllers;

use App\Models\CrmOpportunity;
use App\Models\Customer;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Busca global do CRM (lupa) — retorna principalmente NEGOCIAÇÕES (oportunidades)
 * e EMPRESAS cujo nome casa com o termo. Reusa `customers` (empresa única).
 * Visibilidade: gestor vê tudo; não-gestor vê só o que é seu (oportunidade como
 * responsável / empresa como executivo da conta).
 */
class CrmSearchController extends Controller
{
    use \App\Http\Traits\FiltersByActiveCompany;

    public function index(Request $request): JsonResponse
    {
        $u = auth()->user();
        $perms = $u ? PermissionService::for($u) : [];
        abort_unless(in_array('*', $perms, true) || in_array('crm.view', $perms, true), 403, 'Sem acesso à busca do CRM.');
        $podeVerTodos = in_array('*', $perms, true) || in_array('crm.opportunities.view_all', $perms, true) || in_array('crm.manage', $perms, true);

        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => ['negocios' => [], 'empresas' => []]]);
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $cid = $this->activeCompanyId();

        // ── NEGOCIAÇÕES (oportunidades) ──
        $negocios = CrmOpportunity::query()
            ->whereNull('deleted_at')
            ->when($cid, fn ($qq) => $qq->where('crm_opportunities.company_id', $cid))
            ->when(!$podeVerTodos, fn ($qq) => $qq->where('responsavel_id', $u->id))
            ->where(function ($w) use ($like) {
                $w->where('title', 'ilike', $like)
                  ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', $like));
            })
            ->with([
                'customer:id,name',
                'pipeline:id,name',
                'stage:id,name',
                'responsavel:id,name',
            ])
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn ($o) => [
                'id'          => $o->id,
                'title'       => $o->title,
                'empresa'     => $o->customer?->name,
                'valor'       => $o->valor !== null ? (float) $o->valor : null,
                'status'      => $o->status,          // aberto | ganho | perdido | parado
                'pipeline'    => $o->pipeline?->name,
                'stage'       => $o->stage?->name,
                'responsavel' => $o->responsavel?->name,
            ])->values();

        // ── EMPRESAS ──
        $empresas = Customer::query()
            ->whereNull('deleted_at')
            ->when(!$podeVerTodos, fn ($qq) => $qq->where('executive_id', $u->id))
            ->where('name', 'ilike', $like)
            ->with(['crmProfile:customer_id,segment,region', 'executive:id,name'])
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'id'         => $c->id,
                'name'       => $c->name,
                'crm_status' => $c->crm_status,
                'segmento'   => $c->crmProfile?->segment,
                'regiao'     => $c->crmProfile?->region,
                'executivo'  => $c->executive?->name,
            ])->values();

        return response()->json(['data' => ['negocios' => $negocios, 'empresas' => $empresas]]);
    }
}
