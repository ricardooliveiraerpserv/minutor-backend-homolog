<?php

namespace App\Http\Controllers;

use App\Http\Traits\ListCacheable;
use App\Models\Contract;
use App\Models\ContractHourMultiplier;
use App\Services\ContractHourMultiplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * CRUD das regras de multiplicação de horas faturáveis ao cliente (por contrato).
 * Admin/contracts.manage. NÃO aplica nada no cálculo — isso é feito pelo
 * ContractHourMultiplierService nos pontos de fechamento do lado cliente.
 */
class ContractHourMultiplierController extends Controller
{
    use ListCacheable;

    /**
     * Recalcula os timesheets do contrato E invalida o cache das listagens que
     * mostram horas faturáveis (a listagem /projects é cacheada 60s no servidor —
     * sem isto, mudar a regra só reflete na tela após o TTL). Ponto único.
     */
    private function afterRuleChange(int $contractId): void
    {
        app(ContractHourMultiplierService::class)->recomputeContract($contractId);
        // Listagens cacheadas 60s que exibem horas faturáveis — invalidar todas.
        $this->invalidateListCache('projects');
        $this->invalidateListCache('timesheets');
    }

    /** GET /contract-hour-multipliers — lista as regras (com nomes de cliente/contrato). */
    public function index(Request $request): JsonResponse
    {
        $q = ContractHourMultiplier::query()
            ->with(['customer:id,name', 'contract:id,project_name,project_code_preview,customer_id', 'createdBy:id,name'])
            ->when($request->filled('customer_id'), fn ($w) => $w->where('customer_id', (int) $request->query('customer_id')))
            ->when($request->filled('contract_id'), fn ($w) => $w->where('contract_id', (int) $request->query('contract_id')))
            ->when($request->boolean('only_active'), fn ($w) => $w->where('active', true))
            ->orderByDesc('active')
            ->orderByDesc('id');

        return response()->json(['items' => $q->get()->map(fn ($r) => $this->row($r))]);
    }

    /** GET /contract-hour-multipliers/contracts?customer_id=X — contratos do cliente pro dropdown. */
    public function contracts(Request $request): JsonResponse
    {
        $customerId = (int) $request->query('customer_id');
        if (!$customerId) return response()->json(['items' => []]);

        $items = Contract::query()
            ->where('customer_id', $customerId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get(['id', 'project_name', 'project_code_preview'])
            ->map(fn ($c) => [
                'id'    => $c->id,
                'label' => trim(($c->project_code_preview ? $c->project_code_preview . ' · ' : '') . ($c->project_name ?? "Contrato #{$c->id}")),
            ]);

        return response()->json(['items' => $items]);
    }

    /** POST /contract-hour-multipliers — cria uma regra (encerra a ativa anterior do contrato). */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $contract = Contract::findOrFail($data['contract_id']);

        // Projetos Fechados (escopo/valor fixo) não entram no multiplicador — nem o excedente.
        $rootProject = \App\Models\Project::query()->with('contractType:id,code,name')->find($contract->project_id);
        if ($rootProject && $rootProject->isClosedContract()) {
            return response()->json(['message' => 'Projetos Fechados não entram no multiplicador de horas (nem o excedente).'], 422);
        }

        $data['customer_id'] = $contract->customer_id;
        $data['created_by_id'] = Auth::id();

        $rule = DB::transaction(function () use ($data) {
            if (($data['active'] ?? true)) {
                // Trava: só 1 ativa por contrato — desativa a anterior antes de criar.
                ContractHourMultiplier::where('contract_id', $data['contract_id'])
                    ->where('active', true)
                    ->update(['active' => false]);
            }
            return ContractHourMultiplier::create($data);
        });

        $this->afterRuleChange((int) $rule->contract_id);

        return response()->json($this->row($rule->fresh(['customer', 'contract', 'createdBy'])), 201);
    }

    /** PUT /contract-hour-multipliers/{multiplier} */
    public function update(Request $request, ContractHourMultiplier $multiplier): JsonResponse
    {
        $data = $this->validated($request, $multiplier);

        DB::transaction(function () use ($data, $multiplier) {
            if (($data['active'] ?? $multiplier->active)) {
                ContractHourMultiplier::where('contract_id', $multiplier->contract_id)
                    ->where('active', true)
                    ->where('id', '!=', $multiplier->id)
                    ->update(['active' => false]);
            }
            $multiplier->update($data);
        });

        $this->afterRuleChange((int) $multiplier->contract_id);

        return response()->json($this->row($multiplier->fresh(['customer', 'contract', 'createdBy'])));
    }

    /** DELETE /contract-hour-multipliers/{multiplier} — remove a regra (soft delete). */
    public function destroy(ContractHourMultiplier $multiplier): JsonResponse
    {
        $contractId = (int) $multiplier->contract_id;
        $multiplier->delete();
        $this->afterRuleChange($contractId);
        return response()->json(['ok' => true]);
    }

    // ---- helpers ----

    private function validated(Request $request, ?ContractHourMultiplier $existing = null): array
    {
        $rules = [
            'percent'    => 'required|numeric|min:0|max:1000',
            'start_date' => 'required|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'active'     => 'sometimes|boolean',
            'reason'     => 'nullable|string|max:500',
        ];
        // contract_id só é obrigatório/editável na criação (a regra pertence a 1 contrato).
        if (!$existing) {
            $rules['contract_id'] = 'required|integer|exists:contracts,id';
        }
        return $request->validate($rules);
    }

    private function row(ContractHourMultiplier $r): array
    {
        return [
            'id'            => $r->id,
            'contract_id'   => $r->contract_id,
            'customer_id'   => $r->customer_id,
            'customer_name' => $r->customer?->name,
            'contract_label'=> $r->contract
                ? trim((($r->contract->project_code_preview ? $r->contract->project_code_preview . ' · ' : '')) . ($r->contract->project_name ?? "Contrato #{$r->contract_id}"))
                : "Contrato #{$r->contract_id}",
            'percent'       => (float) $r->percent,
            'factor'        => $r->factor(),
            'start_date'    => optional($r->start_date)->format('Y-m-d'),
            'end_date'      => optional($r->end_date)->format('Y-m-d'),
            'active'        => (bool) $r->active,
            'reason'        => $r->reason,
            'created_by'    => $r->createdBy?->name,
            'created_at'    => optional($r->created_at)->toIso8601String(),
        ];
    }
}
