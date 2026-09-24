<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskTicketJustification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Help Desk — Cadastro de Justificativas de ticket (vinculadas a um status). CRUD de gestão. */
class HelpDeskTicketJustificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = HelpDeskTicketJustification::query()
            ->with('status:id,key,label,color')
            ->when(!$request->boolean('all'), fn ($q) => $q->where('active', true))
            ->when($request->filled('status_id'), fn ($q) => $q->where('status_id', $request->status_id))
            ->orderBy('sort_order')->orderBy('name')
            ->get();
        return response()->json(['data' => $items]);
    }

    private function rules(bool $creating): array
    {
        return [
            'status_id'    => ($creating ? 'required' : 'sometimes') . '|exists:helpdesk_statuses,id',
            'name'         => ($creating ? 'required' : 'sometimes') . '|string|max:160',
            'availability' => 'nullable|in:public_and_internal,public,internal',
            'active'       => 'nullable|boolean',
            'sort_order'   => 'nullable|integer',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $j = HelpDeskTicketJustification::create($v);
        return response()->json(['data' => $j->load('status:id,key,label,color')], 201);
    }

    public function update(Request $request, HelpDeskTicketJustification $justification): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        $justification->update($v);
        return response()->json(['data' => $justification->fresh()->load('status:id,key,label,color')]);
    }

    public function destroy(HelpDeskTicketJustification $justification): JsonResponse
    {
        $justification->delete(); // soft delete
        return response()->json(null, 204);
    }
}
