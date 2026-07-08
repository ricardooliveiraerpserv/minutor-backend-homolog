<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskTicket;
use App\Models\Playbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Help Desk — Playbooks de Atendimento: lista para execução + cadastro (motor de padronização). */
class HelpDeskPlaybookController extends Controller
{
    /** Lista de playbooks ATIVOS para o botão "Executar Playbook" (atendimento). */
    public function index(): JsonResponse
    {
        $pbs = Playbook::forScope('help_desk')->where('active', true)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'category', 'color', 'icon', 'actions'])
            ->map(fn (Playbook $p) => [
                'id'             => $p->id,
                'name'           => $p->name,
                'category'       => $p->category,
                'color'          => $p->color,
                'icon'           => $p->icon,
                'start_finalize' => (bool) ($p->actions['start_finalize'] ?? false),
            ]);
        return response()->json(['data' => $pbs]);
    }

    /** Cadastro completo (gestão). */
    public function adminIndex(): JsonResponse
    {
        return response()->json(['data' => Playbook::forScope('help_desk')->orderBy('sort_order')->orderBy('name')->get()]);
    }

    private function rules(bool $creating): array
    {
        return [
            'name'        => ($creating ? 'required' : 'sometimes') . '|string|max:120',
            'category'    => 'nullable|string|max:80',
            'color'       => 'nullable|string|max:16',
            'icon'        => 'nullable|string|max:40',
            'active'      => 'nullable|boolean',
            'sort_order'  => 'nullable|integer',
            'actions'                       => 'nullable|array',
            'actions.reply'                 => 'nullable|string',
            'actions.internal_comment'      => 'nullable|string',
            'actions.status_id'             => 'nullable|exists:helpdesk_statuses,id',
            'actions.priority'              => 'nullable|in:' . implode(',', HelpDeskTicket::PRIORITIES),
            'actions.team_id'               => 'nullable|exists:helpdesk_teams,id',
            'actions.assignee_id'           => 'nullable|exists:users,id',
            'actions.checklist'             => 'nullable|array',
            'actions.checklist.*'           => 'string',
            'actions.start_finalize'        => 'nullable|boolean',
            'actions.finalize_status_id'    => 'nullable|exists:helpdesk_statuses,id',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $v['scope'] = 'help_desk';
        $v['actions'] = $this->sanitizeActions($v['actions'] ?? []);
        return response()->json(['data' => Playbook::create($v)], 201);
    }

    public function update(Request $request, Playbook $playbook): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        if (array_key_exists('actions', $v)) $v['actions'] = $this->sanitizeActions($v['actions'] ?? []);
        $playbook->update($v);
        return response()->json(['data' => $playbook->fresh()]);
    }

    public function destroy(Playbook $playbook): JsonResponse
    {
        $playbook->delete();
        return response()->json(null, 204);
    }

    /** Mantém só chaves conhecidas e remove vazias (o executor só aplica o configurado). */
    private function sanitizeActions(array $a): array
    {
        $out = [];
        foreach (['reply', 'internal_comment', 'priority', 'status_id', 'team_id', 'assignee_id', 'finalize_status_id'] as $k) {
            if (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null) $out[$k] = $a[$k];
        }
        if (!empty($a['checklist'])) {
            $out['checklist'] = array_values(array_filter(array_map('trim', (array) $a['checklist']), fn ($x) => $x !== ''));
        }
        if (!empty($a['start_finalize'])) $out['start_finalize'] = true;
        return $out;
    }
}
