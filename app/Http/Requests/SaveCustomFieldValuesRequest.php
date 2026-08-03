<?php

namespace App\Http\Requests;

use App\Models\CustomField;
use Illuminate\Foundation\Http\FormRequest;

class SaveCustomFieldValuesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // A autorização será feita no controller baseado no contexto
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // A rota traz o contexto em minúsculo (customers/opportunities/...); os campos são gravados com o
        // nome do contexto (Customer/Opportunity/...). Mapeia p/ a validação por-campo funcionar de fato.
        $map = ['projects' => 'Project', 'timesheets' => 'Timesheet', 'expenses' => 'Expense', 'customers' => 'Customer', 'opportunities' => 'Opportunity', 'contacts' => 'Contact', 'products' => 'Product', 'tasks' => 'Task', 'proposals' => 'Proposal'];
        $context = $map[$this->route('context')] ?? $this->route('context');
        $rules = ['values' => ['required', 'array']];

        // Buscar todos os campos customizados do contexto
        $customFields = CustomField::forContext($context)->get();

        foreach ($customFields as $field) {
            $fieldRules = [];

            // Se o campo é obrigatório
            if ($field->required) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            // Validação baseada no tipo
            switch ($field->type) {
                case 'text':
                    $fieldRules[] = 'string';
                    $fieldRules[] = 'max:1000';
                    break;
                case 'number':
                    $fieldRules[] = 'numeric';
                    break;
                case 'boolean':
                    $fieldRules[] = 'boolean';
                    break;
                case 'date':
                    $fieldRules[] = 'date';
                    break;
                case 'select':
                    if ($field->options && is_array($field->options)) {
                        $fieldRules[] = 'in:' . implode(',', $field->options);
                    }
                    break;
            }

            $rules["values.{$field->key}"] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'values.required' => 'Os valores dos campos customizados são obrigatórios.',
            'values.array' => 'Os valores devem ser enviados como um objeto.',
            'values.*.required' => 'Este campo é obrigatório.',
            'values.*.string' => 'Este campo deve ser um texto.',
            'values.*.numeric' => 'Este campo deve ser um número.',
            'values.*.boolean' => 'Este campo deve ser verdadeiro ou falso.',
            'values.*.date' => 'Este campo deve ser uma data válida.',
            'values.*.in' => 'O valor selecionado não é válido.',
        ];
    }
}

