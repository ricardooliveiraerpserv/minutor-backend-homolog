<?php

namespace App\Http\Controllers;

use App\Models\EnvOptionList;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Cadastro das listas de opções dos formulários do Cofre (metadados, NÃO segredos —
 * por isso vive fora do cofre, sem destravar). Leitura: qualquer interno. Escrita: admin do cofre.
 */
class EnvOptionListController extends Controller
{
    private function internal(Request $request): User
    {
        $user = $request->user();
        abort_if($user === null || $user->customer_id !== null, 403);
        return $user;
    }

    private function admin(Request $request): User
    {
        $user = $this->internal($request);
        abort_unless($user->hasAccess('environments.admin'), 403);
        return $user;
    }

    private function seedIfEmpty(string $key): void
    {
        if (! in_array($key, EnvOptionList::LISTS, true)) return;
        if (EnvOptionList::where('list_key', $key)->exists()) return;
        foreach (EnvOptionList::DEFAULTS[$key] ?? [] as $i => [$value, $label]) {
            EnvOptionList::create(['list_key' => $key, 'value' => $value, 'label' => $label, 'sort' => $i]);
        }
    }

    private function rows(string $key)
    {
        $this->seedIfEmpty($key);
        return EnvOptionList::where('list_key', $key)->orderBy('sort')->orderBy('label')->get(['id', 'value', 'label', 'sort']);
    }

    public function index(Request $request): JsonResponse
    {
        $this->internal($request);
        $key = $request->query('list_key');
        if (is_string($key) && $key !== '') {
            return response()->json($this->rows($key));
        }
        $out = [];
        foreach (EnvOptionList::LISTS as $k) $out[$k] = $this->rows($k);
        return response()->json($out);
    }

    public function store(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'list_key' => ['required', Rule::in(EnvOptionList::LISTS)],
            'label'    => ['required', 'string', 'max:120'],
            'value'    => ['nullable', 'string', 'max:60', 'regex:/^[\w.\-+]+$/u'],
        ]);
        $value = $data['value'] ?? Str::slug($data['label'], '_');
        if ($value === '') $value = 'opt_' . substr(md5($data['label']), 0, 8);

        if (EnvOptionList::where('list_key', $data['list_key'])->where('value', $value)->exists()) {
            return response()->json(['message' => 'Já existe uma opção com esse valor.'], 409);
        }
        $max = (int) EnvOptionList::where('list_key', $data['list_key'])->max('sort');
        $row = EnvOptionList::create([
            'list_key' => $data['list_key'], 'value' => $value, 'label' => $data['label'],
            'sort' => $max + 1, 'created_by' => $request->user()->id,
        ]);

        return response()->json($row->only(['id', 'value', 'label', 'sort']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->admin($request);
        $row = EnvOptionList::findOrFail($id);
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'sort'  => ['sometimes', 'integer'],
        ]);
        $row->fill($data)->save();

        return response()->json(['updated' => true]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->admin($request);
        EnvOptionList::where('id', $id)->delete();

        return response()->json(['deleted' => true]);
    }
}
