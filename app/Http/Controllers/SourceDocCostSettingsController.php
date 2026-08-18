<?php

namespace App\Http\Controllers;

use App\Models\SourceDocAiSettings;
use App\SourceCode\Cost\CostSettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Central de Fontes — Frente A. Configuração administrativa dos limites de IA (sem deploy).
 * INTERNO ERPSERV apenas (gate de permissão nas rotas; sem escopo de cliente). Governança C4a.
 */
class SourceDocCostSettingsController extends Controller
{
    public function __construct(private CostSettingsResolver $resolver)
    {
    }

    /** GET /source-docs/cost-settings — config global vigente + overrides por customer/repo. */
    public function index(): JsonResponse
    {
        $overrides = SourceDocAiSettings::query()
            ->whereIn('scope_type', ['customer', 'repo'])
            ->orderBy('scope_type')->orderBy('scope_id')
            ->get(['id', 'scope_type', 'scope_id', 'automatic_cost_limit_usd', 'safety_margin_percent', 'max_semantic_step_usd', 'approval_required_above_limit', 'max_approved_cost_usd', 'approval_mandatory_above_usd', 'updated_by', 'updated_at']);

        return response()->json([
            'data' => [
                'global' => $this->resolver->global()->toArray(),
                'overrides' => $overrides,
            ],
        ]);
    }

    /** PUT /source-docs/cost-settings — grava (global por padrão, ou override por scope_type+scope_id). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => ['sometimes', Rule::in(SourceDocAiSettings::SCOPES)],
            'scope_id' => ['sometimes', 'integer', 'min:0'],
            'automatic_cost_limit_usd' => ['required', 'numeric', 'gt:0', 'lte:1000'],
            'safety_margin_percent' => ['required', 'numeric', 'min:0', 'max:90'],
            'max_semantic_step_usd' => ['required', 'numeric', 'gt:0'],
            'approval_required_above_limit' => ['required', 'boolean'],
            'max_approved_cost_usd' => ['required', 'numeric', 'gte:0'],
            'approval_mandatory_above_usd' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Invariantes de negócio (coerência dos tetos).
        $auto = (float) $data['automatic_cost_limit_usd'];
        $step = (float) $data['max_semantic_step_usd'];
        $maxApproved = (float) $data['max_approved_cost_usd'];
        $mand = $data['approval_mandatory_above_usd'] ?? null;
        $errors = [];
        if ($step > $auto) {
            $errors['max_semantic_step_usd'] = 'O máximo por passo não pode exceder o limite automático por fonte.';
        }
        if ($maxApproved < $auto) {
            $errors['max_approved_cost_usd'] = 'O teto aprovável deve ser ≥ ao limite automático por fonte.';
        }
        if ($mand !== null && (float) $mand > $maxApproved) {
            $errors['approval_mandatory_above_usd'] = 'A obrigatoriedade de aprovação não pode exceder o teto aprovável.';
        }
        if ($errors) {
            return response()->json(['message' => 'Configuração inconsistente.', 'errors' => $errors], 422);
        }

        $scopeType = $data['scope_type'] ?? 'global';
        $scopeId = $scopeType === 'global' ? 0 : (int) ($data['scope_id'] ?? 0);

        $row = SourceDocAiSettings::query()->updateOrCreate(
            ['scope_type' => $scopeType, 'scope_id' => $scopeId],
            [
                'automatic_cost_limit_usd' => $auto,
                'safety_margin_percent' => (float) $data['safety_margin_percent'],
                'max_semantic_step_usd' => $step,
                'approval_required_above_limit' => (bool) $data['approval_required_above_limit'],
                'max_approved_cost_usd' => $maxApproved,
                'approval_mandatory_above_usd' => $mand,
                'updated_by' => $request->user()?->id,
            ]
        );

        return response()->json([
            'data' => [
                'saved' => $row->only(['id', 'scope_type', 'scope_id']),
                'global' => $this->resolver->global()->toArray(),
            ],
        ]);
    }
}
