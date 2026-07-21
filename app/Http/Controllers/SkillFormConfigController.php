<?php

namespace App\Http\Controllers;

use App\Models\SkillFormConfig;
use App\Services\SkillSurveyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Rotina de Configuração de Formulários: o admin edita os campos cadastrais de
 * cada tipo (internal/partner/candidate). Gate de rota: competencias.manage.
 */
class SkillFormConfigController extends Controller
{
    public function __construct(private readonly SkillSurveyService $service)
    {
    }

    private const TYPES = ['internal', 'partner', 'candidate'];

    /** Catálogo de tipos de campo disponíveis no editor. */
    public const FIELD_TYPES = [
        ['value' => 'text',     'label' => 'Texto'],
        ['value' => 'email',    'label' => 'E-mail (validado)'],
        ['value' => 'phone',    'label' => 'Celular (validado)'],
        ['value' => 'cpf',      'label' => 'CPF (validado)'],
        ['value' => 'cep',      'label' => 'CEP + endereço automático'],
        ['value' => 'money',    'label' => 'Valor (R$)'],
        ['value' => 'select',   'label' => 'Lista de opções'],
        ['value' => 'boolean',  'label' => 'Sim / Não (checkbox)'],
        ['value' => 'file',     'label' => 'Anexo (PDF/DOC)'],
        ['value' => 'textarea', 'label' => 'Texto longo'],
    ];

    public function index(): JsonResponse
    {
        $configs = [];
        foreach (self::TYPES as $t) {
            $configs[$t] = $this->service->schemaFor($t);
        }

        return response()->json([
            'configs' => $configs,
            'field_types' => self::FIELD_TYPES,
            'types' => [
                ['value' => 'internal', 'label' => 'Colaboradores Internos'],
                ['value' => 'partner', 'label' => 'Parceiros'],
                ['value' => 'candidate', 'label' => 'Banco de Talentos'],
            ],
        ]);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        $allowed = array_column(self::FIELD_TYPES, 'value');
        $data = $request->validate([
            'fields' => 'required|array|min:1',
            'fields.*.key' => 'nullable|string|max:60',
            'fields.*.label' => 'required|string|max:120',
            'fields.*.type' => ['required', 'string', 'in:' . implode(',', $allowed)],
            'fields.*.required' => 'boolean',
            'fields.*.options' => 'array',
            'fields.*.options.*' => 'string|max:120',
            'fields.*.require_unless' => 'nullable|string|max:60',
            'fields.*.auto' => 'boolean',
        ]);

        // Normaliza: key vira slug; mantém só os atributos conhecidos.
        $seen = [];
        $fields = [];
        foreach ($data['fields'] as $f) {
            $key = $this->slug(($f['key'] ?? '') ?: $f['label']);
            if ($key === '' || isset($seen[$key])) {
                $key = $key . '_' . count($fields);
            }
            $seen[$key] = true;
            $field = ['key' => $key, 'label' => trim($f['label']), 'type' => $f['type']];
            if (! empty($f['required'])) {
                $field['required'] = true;
            }
            if ($f['type'] === 'select' && ! empty($f['options'])) {
                $field['options'] = array_values(array_filter(array_map('trim', $f['options']), fn ($o) => $o !== ''));
            }
            if (! empty($f['require_unless'])) {
                $field['require_unless'] = $this->slug($f['require_unless']);
            }
            if (! empty($f['auto'])) {
                $field['auto'] = true;
            }
            $fields[] = $field;
        }

        SkillFormConfig::updateOrCreate(['type' => $type], ['fields' => $fields]);

        return response()->json(['type' => $type, 'fields' => $fields]);
    }

    /** Restaura o formulário do tipo para o padrão do sistema. */
    public function reset(string $type): JsonResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $default = SkillSurveyService::CADASTRAL_SCHEMA[$type] ?? [];
        SkillFormConfig::updateOrCreate(['type' => $type], ['fields' => $default]);

        return response()->json(['type' => $type, 'fields' => $default]);
    }

    private function slug(string $v): string
    {
        return Str::slug($v, '_'); // transliteração multibyte-safe (acentos → ascii)
    }
}
