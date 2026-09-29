<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskAssociationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Help Desk — Regras de Associação (domínio → organização/perfil). CRUD. */
class HelpDeskAssociationRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = HelpDeskAssociationRule::query()
            ->with(['customer:id,name', 'accessProfile:id,name'])
            ->when($request->filled('search'), fn ($q) => $q->where('domain', 'ilike', '%' . HelpDeskAssociationRule::normalize($request->search) . '%'))
            ->orderBy('domain')->limit(1000)->get();
        return response()->json(['data' => $rows]);
    }

    private function rules(bool $creating): array
    {
        return [
            'domain'            => ($creating ? 'required' : 'sometimes') . '|string|max:190',
            'customer_id'       => 'nullable|exists:customers,id',
            'access_profile_id' => 'nullable|exists:helpdesk_access_profiles,id',
            'classification'    => 'nullable|string|max:60',
            'enabled'           => 'nullable|boolean',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $v['domain'] = HelpDeskAssociationRule::normalize($v['domain']);
        abort_if(HelpDeskAssociationRule::where('domain', $v['domain'])->exists(), 422, 'Já existe regra para este domínio.');
        $r = HelpDeskAssociationRule::create($v);
        return response()->json(['data' => $r->load(['customer:id,name', 'accessProfile:id,name'])], 201);
    }

    public function update(Request $request, HelpDeskAssociationRule $associationRule): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        if (isset($v['domain'])) {
            $v['domain'] = HelpDeskAssociationRule::normalize($v['domain']);
            abort_if(HelpDeskAssociationRule::where('domain', $v['domain'])->where('id', '!=', $associationRule->id)->exists(), 422, 'Já existe regra para este domínio.');
        }
        $associationRule->update($v);
        return response()->json(['data' => $associationRule->fresh()->load(['customer:id,name', 'accessProfile:id,name'])]);
    }

    public function destroy(HelpDeskAssociationRule $associationRule): JsonResponse
    {
        $associationRule->delete();
        return response()->json(null, 204);
    }
}
