<?php

namespace App\Http\Controllers;

use App\Http\Traits\ListCacheable;
use App\Models\Contract;
use App\Models\ContractHourMultiplier;
use App\Services\ContractHourMultiplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * CRUD das regras de multiplicação de horas faturáveis ao cliente (por contrato).
 * Admin/contracts.manage. NÃO aplica nada no cálculo — isso é feito pelo
 * ContractHourMultiplierService nos pontos de fechamento do lado cliente.
 *
 * Multi-faixa (27/08): um contrato pode ter VÁRIAS faixas ativas, cada uma com seu
 * período [start_date, end_date] e sua alíquota — desde que os períodos NÃO se
 * sobreponham. `sync` é o caminho do editor multi-faixa; store/update seguem
 * existindo (edição unitária) e também validam a não-sobreposição.
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
            ->orderBy('start_date')
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

    /** GET /contract-hour-multipliers/faixas?contract_id=X — as faixas de um contrato (pro editor multi-faixa). */
    public function faixas(Request $request): JsonResponse
    {
        $contractId = (int) $request->query('contract_id');
        if (!$contractId) return response()->json(['items' => []]);

        $items = ContractHourMultiplier::query()
            ->where('contract_id', $contractId)
            ->where('active', true)
            ->orderBy('start_date')
            ->get()
            ->map(fn ($r) => $this->row($r));

        return response()->json(['items' => $items]);
    }

    /**
     * POST /contract-hour-multipliers/sync — sincroniza TODAS as faixas de UM contrato
     * numa tacada. Body: { contract_id, faixas: [{id?, percent, start_date, end_date?, reason?}] }.
     * Valida que as faixas NÃO se sobrepõem, faz upsert das enviadas, remove (soft) as
     * que sumiram e recomputa 1x.
     */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contract_id'         => 'required|integer|exists:contracts,id',
            'faixas'              => 'present|array',
            'faixas.*.id'         => 'nullable|integer',
            'faixas.*.percent'    => 'required|numeric|min:0|max:1000',
            'faixas.*.start_date' => 'required|date',
            'faixas.*.end_date'   => 'nullable|date|after_or_equal:faixas.*.start_date',
            'faixas.*.reason'     => 'nullable|string|max:500',
            'faixas.*.users'      => 'nullable|array',            // apontadores a quem a faixa se aplica (vazio = todos)
            'faixas.*.users.*'    => 'integer|exists:users,id',
        ]);

        $contract = Contract::findOrFail($data['contract_id']);

        // Projetos Fechados não entram no multiplicador — nem o excedente.
        $rootProject = \App\Models\Project::query()->with('contractType:id,code,name')->find($contract->project_id);
        if ($rootProject && $rootProject->isClosedContract()) {
            return response()->json(['message' => 'Projetos Fechados não entram no multiplicador de horas (nem o excedente).'], 422);
        }

        $faixas = array_map(function ($f) {
            $users = array_values(array_unique(array_map('intval', $f['users'] ?? [])));
            return [
                'id'         => $f['id'] ?? null,
                'percent'    => (float) $f['percent'],
                'start_date' => Carbon::parse($f['start_date'])->format('Y-m-d'),
                'end_date'   => !empty($f['end_date']) ? Carbon::parse($f['end_date'])->format('Y-m-d') : null,
                'reason'     => $f['reason'] ?? null,
                'users'      => $users ?: null,   // vazio = todos (NULL no banco)
            ];
        }, $data['faixas']);

        $this->assertNoOverlap($faixas);

        DB::transaction(function () use ($contract, $faixas) {
            $existing = ContractHourMultiplier::query()
                ->where('contract_id', $contract->id)->whereNull('deleted_at')->get()->keyBy('id');
            $keepIds = [];

            foreach ($faixas as $f) {
                $payload = [
                    'contract_id' => $contract->id,
                    'customer_id' => $contract->customer_id,
                    'percent'     => $f['percent'],
                    'start_date'  => $f['start_date'],
                    'end_date'    => $f['end_date'],
                    'active'      => true,
                    'reason'      => $f['reason'],
                    'users'       => $f['users'],
                ];
                if (!empty($f['id']) && $existing->has($f['id'])) {
                    $existing[$f['id']]->update($payload);
                    $keepIds[] = (int) $f['id'];
                } else {
                    $payload['created_by_id'] = Auth::id();
                    $keepIds[] = (int) ContractHourMultiplier::create($payload)->id;
                }
            }

            // Faixas ATIVAS que sumiram do payload → soft-delete. (As inativas ficam
            // intactas — o editor multi-faixa só governa o conjunto ativo do contrato.)
            ContractHourMultiplier::query()
                ->where('contract_id', $contract->id)->where('active', true)->whereNull('deleted_at')
                ->when($keepIds, fn ($q) => $q->whereNotIn('id', $keepIds))
                ->delete();
        });

        $this->afterRuleChange((int) $contract->id);

        return $this->faixas(new Request(['contract_id' => $contract->id]));
    }

    /** POST /contract-hour-multipliers — cria uma faixa (valida não-sobreposição). */
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

        if (($data['active'] ?? true)) {
            $this->assertNoDbOverlap(
                (int) $data['contract_id'],
                $data['start_date'],
                $data['end_date'] ?? null
            );
        }

        $rule = ContractHourMultiplier::create($data);

        $this->afterRuleChange((int) $rule->contract_id);

        return response()->json($this->row($rule->fresh(['customer', 'contract', 'createdBy'])), 201);
    }

    /** PUT /contract-hour-multipliers/{multiplier} */
    public function update(Request $request, ContractHourMultiplier $multiplier): JsonResponse
    {
        $data = $this->validated($request, $multiplier);

        if (($data['active'] ?? $multiplier->active)) {
            $this->assertNoDbOverlap(
                (int) $multiplier->contract_id,
                $data['start_date'] ?? optional($multiplier->start_date)->format('Y-m-d'),
                array_key_exists('end_date', $data) ? $data['end_date'] : optional($multiplier->end_date)->format('Y-m-d'),
                (int) $multiplier->id
            );
        }

        $multiplier->update($data);

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

    /**
     * Valida que as faixas do payload não se sobrepõem entre si.
     * end_date null = "sem fim" (infinito). Datas já normalizadas Y-m-d (comparação lexicográfica).
     */
    private function assertNoOverlap(array $faixas): void
    {
        $n = count($faixas);
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                $A = $faixas[$a];
                $B = $faixas[$b];
                // Sobrepõem se A.start <= B.end && B.start <= A.end (fim null = +∞).
                $aStartLeBEnd = ($B['end_date'] === null) || ($A['start_date'] <= $B['end_date']);
                $bStartLeAEnd = ($A['end_date'] === null) || ($B['start_date'] <= $A['end_date']);
                if ($aStartLeBEnd && $bStartLeAEnd) {
                    throw ValidationException::withMessages([
                        'faixas' => ['As faixas de datas não podem se sobrepor. Ajuste os períodos (faixa ' . ($a + 1) . ' e faixa ' . ($b + 1) . ').'],
                    ]);
                }
            }
        }
    }

    /** Valida que [start,end] não sobrepõe outra faixa ATIVA do contrato (exceto $exceptId). */
    private function assertNoDbOverlap(int $contractId, ?string $start, ?string $end, ?int $exceptId = null): void
    {
        if (!$start) return;
        $start = Carbon::parse($start)->format('Y-m-d');
        $end   = $end ? Carbon::parse($end)->format('Y-m-d') : null;

        $rows = ContractHourMultiplier::query()
            ->where('contract_id', $contractId)
            ->where('active', true)
            ->whereNull('deleted_at')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->get(['id', 'start_date', 'end_date']);

        foreach ($rows as $r) {
            $es = $r->start_date->format('Y-m-d');
            $ee = $r->end_date?->format('Y-m-d');
            $startLeEE = ($ee === null) || ($start <= $ee);
            $esLeEnd   = ($end === null) || ($es <= $end);
            if ($startLeEE && $esLeEnd) {
                throw ValidationException::withMessages([
                    'start_date' => ['Este período se sobrepõe a outra faixa ativa do contrato. Ajuste as datas.'],
                ]);
            }
        }
    }

    private function validated(Request $request, ?ContractHourMultiplier $existing = null): array
    {
        $rules = [
            'percent'    => 'required|numeric|min:0|max:1000',
            'start_date' => 'required|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'active'     => 'sometimes|boolean',
            'reason'     => 'nullable|string|max:500',
            'users'      => 'nullable|array',
            'users.*'    => 'integer|exists:users,id',
        ];
        // contract_id só é obrigatório/editável na criação (a regra pertence a 1 contrato).
        if (!$existing) {
            $rules['contract_id'] = 'required|integer|exists:contracts,id';
        }
        $data = $request->validate($rules);
        // Normaliza users: vazio = todos (NULL no banco).
        if (array_key_exists('users', $data)) {
            $u = array_values(array_unique(array_map('intval', $data['users'] ?? [])));
            $data['users'] = $u ?: null;
        }
        return $data;
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
            'users'         => array_map('intval', $r->users ?? []),          // apontadores da faixa (vazio = todos)
            'users_label'   => $this->usersLabel($r->users ?? []),            // nomes p/ exibição
            'created_by'    => $r->createdBy?->name,
            'created_at'    => optional($r->created_at)->toIso8601String(),
        ];
    }

    /** cache por request: id => nome (p/ rotular as faixas escopadas sem N+1). */
    private array $userNameCache = [];

    private function usersLabel(?array $ids): ?string
    {
        $ids = array_values(array_unique(array_map('intval', $ids ?? [])));
        if (!$ids) return null; // vazio = todos
        $missing = array_values(array_diff($ids, array_keys($this->userNameCache)));
        if ($missing) {
            foreach (\App\Models\User::query()->whereIn('id', $missing)->pluck('name', 'id') as $id => $name) {
                $this->userNameCache[(int) $id] = $name;
            }
        }
        $names = array_map(fn ($id) => $this->userNameCache[$id] ?? "#$id", $ids);
        return implode(', ', $names);
    }

    /**
     * GET /contract-hour-multipliers/apontadores — usuários que podem apontar horas
     * (não-clientes, ativos), pro multi-select de escopo por usuário no editor de faixas.
     */
    public function apontadores(Request $request): JsonResponse
    {
        $items = \App\Models\User::query()
            ->where('type', '!=', 'cliente')
            ->when(Schema::hasColumn('users', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'label' => $u->name]);

        return response()->json(['items' => $items]);
    }
}
