<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskMovideskStatusMap;
use App\Models\HelpDeskStatus;
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
            ->get(['id', 'helpdesk_status_id', 'movidesk_base_status', 'movidesk_status_text', 'is_outbound_default']);

        // Sub-status conhecidos do Movidesk (sugestões nos selects) — a partir do cache de tickets.
        $texts = DB::table('movidesk_tickets')
            ->whereNotNull('status')->where('status', '!=', '')
            ->select('base_status', 'status')
            ->distinct()->orderBy('base_status')->orderBy('status')->get()
            ->map(fn ($r) => ['base' => $r->base_status, 'text' => trim((string) $r->status)])
            ->filter(fn ($r) => $r['text'] !== '')->values();

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
}
