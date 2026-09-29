<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Help Desk — CRUD de formulários vinculados a status (construtor de formulários). */
class HelpDeskFormController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $forms = HelpDeskForm::query()
            ->when(!$request->boolean('all'), fn ($q) => $q->where('active', true))
            ->with(['fields', 'status:id,key,label'])
            ->orderBy('name')->get();
        return response()->json(['data' => $forms]);
    }

    public function show(HelpDeskForm $form): JsonResponse
    {
        return response()->json(['data' => $form->load(['fields', 'status:id,key,label'])]);
    }

    private function rules(bool $creating): array
    {
        return [
            'name'      => ($creating ? 'required' : 'sometimes') . '|string|max:140',
            'status_id' => 'nullable|exists:helpdesk_statuses,id',
            'title'     => 'nullable|string|max:200',
            'subtitle'  => 'nullable|string|max:200',
            'intro'     => 'nullable|string',
            'show_logo' => 'nullable|boolean',
            'active'    => 'nullable|boolean',
            'fields'                => 'nullable|array',
            'fields.*.key'          => 'required|string|max:60',
            'fields.*.ftype'        => 'required|in:title,section,text,richtext,checkbox,date,time,user',
            'fields.*.label'        => 'required|string|max:200',
            'fields.*.hint'         => 'nullable|string',
            'fields.*.required'     => 'nullable|boolean',
            'fields.*.min_chars'    => 'nullable|integer|min:0',
            'fields.*.rule'         => 'nullable|array',
            'fields.*.rule.when'    => 'nullable|string|max:60',
            'fields.*.rule.value'   => 'nullable|string|max:200',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $fields = $v['fields'] ?? []; unset($v['fields']);
        return DB::transaction(function () use ($v, $fields) {
            $form = HelpDeskForm::create($v);
            $this->syncFields($form, $fields);
            return response()->json(['data' => $form->load(['fields', 'status:id,key,label'])], 201);
        });
    }

    public function update(Request $request, HelpDeskForm $form): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        $fields = $v['fields'] ?? null; unset($v['fields']);
        return DB::transaction(function () use ($v, $fields, $form) {
            $form->update($v);
            if ($fields !== null) $this->syncFields($form, $fields);
            return response()->json(['data' => $form->fresh()->load(['fields', 'status:id,key,label'])]);
        });
    }

    public function destroy(HelpDeskForm $form): JsonResponse
    {
        $form->delete();
        return response()->json(null, 204);
    }

    /** Substitui a lista de campos do formulário na ordem enviada. */
    private function syncFields(HelpDeskForm $form, array $fields): void
    {
        // Só grava a regra vinda do construtor se tiver um checkbox de gatilho (`when`); senão null.
        // (require_attachment/regras avançadas são setadas fora do construtor e preservadas via LOCK.)
        $mapRule = fn (array $f) => !empty($f['rule']['when'] ?? null)
            ? ['when' => $f['rule']['when'], 'value' => $f['rule']['value'] ?? 'não se aplica'] : null;

        // Formulário TRAVADO: NÃO altera/remove campos existentes — só ANEXA os de chave inédita.
        // Assim, editar o form no construtor não apaga as regras já configuradas.
        if ($form->locked) {
            $existing = $form->fields()->pluck('key')->all();
            $order = (int) $form->fields()->max('order_index');
            foreach (array_values($fields) as $f) {
                if (in_array($f['key'], $existing, true)) continue; // campo travado — ignora qualquer mudança
                $form->fields()->create([
                    'order_index' => ++$order,
                    'key'         => $f['key'],
                    'ftype'       => $f['ftype'],
                    'label'       => $f['label'],
                    'hint'        => $f['hint'] ?? null,
                    'required'    => (bool) ($f['required'] ?? false),
                    'min_chars'   => $f['min_chars'] ?? null,
                    'rule'        => $mapRule($f),
                ]);
            }
            return;
        }

        $form->fields()->delete();
        foreach (array_values($fields) as $i => $f) {
            $form->fields()->create([
                'order_index' => $i,
                'key'         => $f['key'],
                'ftype'       => $f['ftype'],
                'label'       => $f['label'],
                'hint'        => $f['hint'] ?? null,
                'required'    => (bool) ($f['required'] ?? false),
                'min_chars'   => $f['min_chars'] ?? null,
                'rule'        => $mapRule($f),
            ]);
        }
    }
}
