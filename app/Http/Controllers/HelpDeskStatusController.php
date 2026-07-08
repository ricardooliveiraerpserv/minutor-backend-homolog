<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Help Desk — Cadastro do workflow de status (configurável). */
class HelpDeskStatusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $statuses = HelpDeskStatus::query()
            ->when(!$request->boolean('all'), fn ($q) => $q->where('active', true))
            ->orderBy('sort_order')->get();
        return response()->json(['data' => $statuses]);
    }

    private function rules(bool $creating): array
    {
        return [
            'key'         => ($creating ? 'required' : 'sometimes') . '|string|max:40',
            'label'       => ($creating ? 'required' : 'sometimes') . '|string|max:60',
            'color'       => 'nullable|string|max:16',
            'sort_order'  => 'nullable|integer',
            'is_default'  => 'nullable|boolean',
            'is_open'     => 'nullable|boolean',
            'is_resolved' => 'nullable|boolean',
            'is_terminal' => 'nullable|boolean',
            'sla_paused'  => 'nullable|boolean',
            'active'      => 'nullable|boolean',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $v['key'] = Str::slug($v['key'], '_');
        return DB::transaction(function () use ($v) {
            if (!empty($v['is_default'])) HelpDeskStatus::where('is_default', true)->update(['is_default' => false]);
            return response()->json(['data' => HelpDeskStatus::create($v)], 201);
        });
    }

    public function update(Request $request, HelpDeskStatus $status): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        if (isset($v['key'])) $v['key'] = Str::slug($v['key'], '_');
        return DB::transaction(function () use ($v, $status) {
            if (!empty($v['is_default'])) HelpDeskStatus::where('id', '!=', $status->id)->where('is_default', true)->update(['is_default' => false]);
            $status->update($v);
            return response()->json(['data' => $status->fresh()]);
        });
    }

    public function destroy(HelpDeskStatus $status): JsonResponse
    {
        // Não remove status em uso por algum chamado (preserva integridade da timeline/relatórios).
        abort_if($status->tickets()->exists(), 422, 'Status em uso por chamados — desative em vez de excluir.');
        $status->delete();
        return response()->json(null, 204);
    }
}
