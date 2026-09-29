<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\HelpDeskMovideskStatusMap;
use App\Models\HelpDeskStatus;
use App\Models\MovideskOrganization;
use App\Models\SystemSetting;
use App\Services\MovideskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tela de VÍNCULO de status HD ↔ Movidesk (de-para), por empresa (empresa ativa do admin).
 *
 * - Entrada  (Movidesk → HD): cada (baseStatus + sub-status) do Movidesk aponta para um status HD.
 * - Saída    (HD → Movidesk): cada status HD aponta para um (baseStatus + sub-status) do Movidesk.
 *
 * A SAÍDA é usada apenas na Fase 2 (hoje NÃO escrevemos no Movidesk); configurar aqui já deixa pronto.
 */
class HelpDeskMovideskStatusMapController extends Controller
{
    /**
     * Base de cada status do Movidesk (o endpoint /statuses NÃO devolve baseStatus).
     * Fonte: observado nos tickets + cadastro de status do Movidesk (Promax). Chave = nome minúsculo/trim.
     * Usado como fallback quando o par (nome→base) não foi visto em nenhum ticket ainda.
     */
    private const BASE_BY_NAME_FALLBACK = [
        'agendado' => 'Stopped', 'aguardando' => 'Stopped', 'cancelado' => 'Canceled',
        'comercial' => 'Stopped', 'em atendimento' => 'InAttendance', 'encerrado' => 'Closed',
        'fechado' => 'Closed', 'novo' => 'New', 'pausado' => 'Stopped',
        'pendente aprovação' => 'Stopped', 'pendente cliente' => 'Stopped',
        'pendente terceiros' => 'Stopped', 'pendente totvs' => 'Stopped',
        'resolvido' => 'Resolved', 'solução emergencial' => 'InAttendance',
    ];

    /** Status base canônicos do Movidesk (fixos na API deles). */
    private const MOVIDESK_BASE = ['New', 'InAttendance', 'Stopped', 'Resolved', 'Closed', 'Canceled'];

    public function index(Request $request): JsonResponse
    {
        // Escopo de empresa ativa via BelongsToCompany no HelpDeskStatus.
        $statuses = HelpDeskStatus::query()->orderBy('sort_order')->get(['id', 'key', 'label', 'color']);
        $companyId = $statuses->first()->company_id ?? null;
        // (company_id não está no select acima; pega de forma segura)
        $companyId = HelpDeskStatus::query()->value('company_id');

        $rows = HelpDeskMovideskStatusMap::query()
            ->when($companyId === null, fn ($q) => $q->whereNull('company_id'))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->get(['id', 'helpdesk_status_id', 'movidesk_base_status', 'movidesk_status_text', 'is_outbound_default', 'movidesk_justification']);

        // Base conhecida por nome de sub-status (a partir do cache de tickets) — usada p/ auto-preencher a base.
        $baseByName = [];
        DB::table('movidesk_tickets')
            ->whereNotNull('status')->where('status', '!=', '')
            ->select('base_status', 'status')->distinct()->get()
            ->each(function ($r) use (&$baseByName) {
                $name = mb_strtolower(trim((string) $r->status));
                if ($name !== '' && !isset($baseByName[$name]) && $r->base_status) {
                    $baseByName[$name] = (string) $r->base_status;
                }
            });

        // Catálogo COMPLETO de status do Movidesk (todos os configurados, não só os já vistos).
        // Base de cada um: 1º observada nos tickets; senão o fallback do cadastro Movidesk.
        $catalog = $this->statusCatalog(false);
        $texts = collect($catalog)
            ->map(function ($name) use ($baseByName) {
                $key = mb_strtolower(trim($name));
                return ['base' => $baseByName[$key] ?? (self::BASE_BY_NAME_FALLBACK[$key] ?? ''), 'text' => trim($name)];
            })
            ->values();

        // Garante que sub-status já presentes no de-para (mas fora do catálogo atual) não sumam do select.
        $known = $texts->pluck('text')->map(fn ($t) => mb_strtolower($t))->flip();
        foreach ($rows as $r) {
            $t = trim((string) $r->movidesk_status_text);
            if ($t !== '' && !$known->has(mb_strtolower($t))) {
                $texts->push(['base' => (string) $r->movidesk_base_status, 'text' => $t]);
                $known->put(mb_strtolower($t), true);
            }
        }
        $texts = $texts->sortBy('text', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json([
            'data' => [
                'company_id'   => $companyId,
                'statuses'     => $statuses,
                'base_statuses'=> self::MOVIDESK_BASE,
                'movidesk_texts' => $texts,
                'inbound'      => $rows->where('is_outbound_default', false)->values(),
                'outbound'     => $rows->where('is_outbound_default', true)->values(),
            ],
        ]);
    }

    /**
     * Catálogo de status do Movidesk com cache em SystemSetting (evita bater na API a cada carga).
     * $force = busca ao vivo no Movidesk e regrava o cache.
     * @return string[]
     */
    private function statusCatalog(bool $force): array
    {
        if (!$force) {
            $cached = SystemSetting::get('movidesk_status_catalog', null);
            if (is_string($cached)) $cached = json_decode($cached, true);
            if (is_array($cached) && $cached) return $cached;
        }
        $names = app(MovideskService::class)->fetchStatuses();
        if ($names) {
            SystemSetting::set('movidesk_status_catalog', $names, 'json', 'movidesk', 'Catálogo de status do Movidesk (cache)');
            return $names;
        }
        // Falha na API: usa o último cache, se houver.
        $cached = SystemSetting::get('movidesk_status_catalog', null);
        if (is_string($cached)) $cached = json_decode($cached, true);
        return is_array($cached) ? $cached : [];
    }

    /** Botão "Atualizar do Movidesk": força a releitura do catálogo de status e devolve a tela. */
    public function refreshCatalog(Request $request): JsonResponse
    {
        $this->statusCatalog(true);
        return $this->index($request);
    }

    /**
     * Salva (substitui) todo o de-para da empresa ativa.
     * Payload: { inbound: [{movidesk_base_status, movidesk_status_text|null, helpdesk_status_id}],
     *            outbound:[{helpdesk_status_id, movidesk_base_status, movidesk_status_text|null}] }
     */
    public function save(Request $request): JsonResponse
    {
        $v = $request->validate([
            'inbound'   => 'array',
            'inbound.*.movidesk_base_status' => 'required|string|max:40',
            'inbound.*.movidesk_status_text' => 'nullable|string|max:120',
            'inbound.*.helpdesk_status_id'   => 'required|integer',
            'outbound'  => 'array',
            'outbound.*.helpdesk_status_id'   => 'required|integer',
            'outbound.*.movidesk_base_status' => 'required|string|max:40',
            'outbound.*.movidesk_status_text' => 'nullable|string|max:120',
            'outbound.*.movidesk_justification' => 'nullable|string|max:255',
        ]);

        // Ids de status válidos para a empresa ativa (evita gravar status de outra empresa).
        $validIds = HelpDeskStatus::query()->pluck('id')->all();
        $companyId = HelpDeskStatus::query()->value('company_id');

        $now = now();
        $rows = [];

        foreach (($v['inbound'] ?? []) as $r) {
            if (!in_array((int) $r['helpdesk_status_id'], $validIds, true)) continue;
            $rows[] = [
                'company_id' => $companyId,
                'helpdesk_status_id' => (int) $r['helpdesk_status_id'],
                'movidesk_base_status' => trim($r['movidesk_base_status']),
                'movidesk_status_text' => isset($r['movidesk_status_text']) && trim((string) $r['movidesk_status_text']) !== '' ? trim($r['movidesk_status_text']) : null,
                'movidesk_justification' => null,
                'is_outbound_default' => false,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (($v['outbound'] ?? []) as $r) {
            if (!in_array((int) $r['helpdesk_status_id'], $validIds, true)) continue;
            $rows[] = [
                'company_id' => $companyId,
                'helpdesk_status_id' => (int) $r['helpdesk_status_id'],
                'movidesk_base_status' => trim($r['movidesk_base_status']),
                'movidesk_status_text' => isset($r['movidesk_status_text']) && trim((string) $r['movidesk_status_text']) !== '' ? trim($r['movidesk_status_text']) : null,
                'movidesk_justification' => isset($r['movidesk_justification']) && trim((string) $r['movidesk_justification']) !== '' ? trim($r['movidesk_justification']) : null,
                'is_outbound_default' => true,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($companyId, $rows) {
            HelpDeskMovideskStatusMap::query()
                ->when($companyId === null, fn ($q) => $q->whereNull('company_id'))
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->delete();
            if ($rows) HelpDeskMovideskStatusMap::query()->insert($rows);
        });

        return $this->index($request);
    }

    // ── VÍNCULO DE CLIENTE (organização Movidesk → cliente Minutor) ──────────────
    /**
     * Lista as organizações do Movidesk com o cliente Minutor vinculado (se houver) +
     * a lista de clientes para o seletor. É o que resolve o `customer_id` dos chamados
     * importados (org do chamado → cliente).
     */
    public function customersIndex(): JsonResponse
    {
        // Vínculo DEDICADO do Help Desk (hd_customer_id) — separado do customer_id compartilhado.
        $orgs = MovideskOrganization::query()
            ->leftJoin('customers', 'customers.id', '=', 'movidesk_organizations.hd_customer_id')
            ->orderBy('movidesk_organizations.name')
            ->get([
                'movidesk_organizations.id',
                'movidesk_organizations.movidesk_id',
                'movidesk_organizations.name',
                'movidesk_organizations.cnpj',
                'movidesk_organizations.hd_customer_id as customer_id',
                'customers.name as customer_name',
            ]);

        $customers = Customer::query()->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => [
            'organizations' => $orgs,
            'customers'     => $customers,
        ]]);
    }

    /**
     * Salva os vínculos alterados. Payload: { links: [{id, customer_id|null}] }.
     * customer_id null = desvincular.
     */
    public function customersSave(Request $request): JsonResponse
    {
        $v = $request->validate([
            'links'               => 'required|array',
            'links.*.id'          => 'required|integer|exists:movidesk_organizations,id',
            'links.*.customer_id' => 'nullable|integer|exists:customers,id',
        ]);

        DB::transaction(function () use ($v) {
            foreach ($v['links'] as $l) {
                $org = MovideskOrganization::find((int) $l['id']);
                if (!$org) continue;
                $newCustomer = $l['customer_id'] !== null ? (int) $l['customer_id'] : null;
                if ($newCustomer) {
                    // Ao VINCULAR: carimba o momento do vínculo (só se ainda não vinculada) — o import
                    // passa a trazer apenas chamados criados a partir daqui.
                    $org->hd_customer_id = $newCustomer;
                    if (!$org->hd_linked_at) $org->hd_linked_at = now();
                } else {
                    $org->hd_customer_id = null;
                    $org->hd_linked_at = null;
                }
                $org->save();
            }
        });

        return $this->customersIndex();
    }
}
