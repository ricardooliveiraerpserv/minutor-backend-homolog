<?php

namespace App\Http\Controllers;

use App\Models\ClientSourceRepo;
use App\Models\SourceDocInventorySettings;
use App\SourceCode\Inventory\InventorySettingsResolver;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Fase B — Config·Prosight: allowlist de extensões elegíveis para inventário, por escopo (global/empresa/repo).
 * INDEPENDENTE do custo de IA (usa InventorySettingsResolver, nunca CostSettingsResolver). Escopo por cliente
 * revalidado no servidor (SourceDocCustomerScope, anti-IDOR — autoridade pela entidade real). NULL = herda;
 * [] = override explícito (nenhuma extensão). Origem distinta: repo|customer|global|system_default.
 */
class SourceDocInventorySettingsController extends Controller
{
    public function __construct(
        private InventorySettingsResolver $resolver,
        private SourceDocCustomerScope $scope,
    ) {
    }

    /** Anti-IDOR: customer da entidade real (override=customer_id; repo→client_source_repos.customer_id). */
    private function denyScope(Request $request, string $scopeType, int $scopeId): ?JsonResponse
    {
        if ($scopeType === 'global') {
            return null; // ação de sistema; governada pela permissão da rota
        }
        $customerId = (int) ($scopeType === 'repo'
            ? (ClientSourceRepo::whereKey($scopeId)->value('customer_id') ?? 0)
            : $scopeId);
        if (! $this->scope->canAccessCustomerId($request->user(), $customerId)) {
            return response()->json(['message' => 'Sem acesso a esta empresa.'], 403);
        }
        return null;
    }

    /** GET /source-docs/inventory-settings/resolve?customer_id=&source_repo_id= — efetiva + origem + override próprio. */
    public function resolve(Request $request): JsonResponse
    {
        $cid = $request->filled('customer_id') ? (int) $request->query('customer_id') : null;
        $rid = $request->filled('source_repo_id') ? (int) $request->query('source_repo_id') : null;
        if ($cid && ($deny = $this->denyScope($request, 'customer', $cid))) {
            return $deny;
        }
        if (! $cid && $rid && ($deny = $this->denyScope($request, 'repo', $rid))) {
            return $deny;
        }
        $eff = $this->resolver->resolve($cid, $rid);
        // override PRÓPRIO neste nível exato (pode ser NULL = sem override, ou [] = explícito):
        $own = $rid ? $this->resolver->ownRow('repo', $rid) : ($cid ? $this->resolver->ownRow('customer', $cid) : $this->resolver->ownRow('global', 0));
        $level = $rid ? 'repo' : ($cid ? 'customer' : 'global');
        return response()->json(['data' => [
            'level' => $level,
            'scope_id' => $rid ?: ($cid ?: 0),
            'extensions' => $eff['extensions'],       // lista efetiva
            'origin' => $eff['origin'],                // repo|customer|global|system_default
            'has_own_override' => $own !== null && $own->inventory_extensions !== null,
            'own' => ($own && $own->inventory_extensions !== null) ? $own->inventory_extensions : null,
            'system_default' => $this->resolver->defaultExtensions(),
        ]]);
    }

    /** PUT /source-docs/inventory-settings — grava override (scope_type/scope_id + extensions[]). [] = explícito. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => ['required', Rule::in(SourceDocInventorySettings::SCOPES)],
            'scope_id' => ['required', 'integer', 'min:0'],
            'extensions' => ['present', 'array'], // array tipado — NUNCA CSV; [] permitido (override explícito)
            'extensions.*' => ['string'],
        ]);
        $scopeType = $data['scope_type'];
        $scopeId = $scopeType === 'global' ? 0 : (int) $data['scope_id'];

        if ($deny = $this->denyScope($request, $scopeType, $scopeId)) {
            return $deny;
        }

        // Validação estrita: normaliza e REJEITA extensão inválida (não descarta silenciosamente).
        $norm = [];
        foreach ($data['extensions'] as $raw) {
            $x = ltrim(strtolower(trim((string) $raw)), '.');
            if ($x === '' || ! preg_match('/^[a-z0-9]{1,10}$/', $x)) {
                return response()->json(['message' => 'Extensão inválida.', 'errors' => ['extensions' => "Valor inválido: '{$raw}'. Use apenas letras/números (ex.: prw, tlpp), sem ponto."]], 422);
            }
            $norm[$x] = true;
        }
        $norm = array_keys($norm); // dedup, preserva [] (nenhuma extensão)

        $row = SourceDocInventorySettings::query()->updateOrCreate(
            ['scope_type' => $scopeType, 'scope_id' => $scopeId],
            ['inventory_extensions' => $norm, 'updated_by' => $request->user()?->id],
        );
        Log::channel(config('logging.default'))->info('source_docs.inventory_settings.update', [
            'actor_user_id' => $request->user()?->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId, 'extensions' => $norm,
        ]);

        return response()->json(['data' => ['saved' => $row->only(['id', 'scope_type', 'scope_id']), 'extensions' => $norm]]);
    }

    /** DELETE /source-docs/inventory-settings?scope_type=&scope_id= — remove o override → volta a herdar. Nunca global. */
    public function destroy(Request $request): JsonResponse
    {
        $scopeType = (string) $request->query('scope_type');
        $scopeId = (int) $request->query('scope_id');
        if (! in_array($scopeType, ['customer', 'repo'], true)) {
            return response()->json(['message' => 'Só é possível remover override de empresa/repositório.'], 422);
        }
        if ($deny = $this->denyScope($request, $scopeType, $scopeId)) {
            return $deny;
        }
        $n = SourceDocInventorySettings::query()->where('scope_type', $scopeType)->where('scope_id', $scopeId)->delete();
        Log::channel(config('logging.default'))->info('source_docs.inventory_settings.delete', [
            'actor_user_id' => $request->user()?->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
        ]);
        return response()->json(['data' => ['removed' => $n > 0, 'scope_type' => $scopeType, 'scope_id' => $scopeId]]);
    }
}
