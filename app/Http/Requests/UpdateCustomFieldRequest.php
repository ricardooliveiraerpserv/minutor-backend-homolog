<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomFieldRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Tentar pegar o customField da rota (pode ser o modelo ou o ID)
        $customField = $this->route('customField');

        // Se for um modelo, pegar o ID e o context
        if ($customField instanceof \App\Models\CustomField) {
            $customFieldId = $customField->id;
            $context = $this->context ?? $customField->context;
        } else {
            // Se for apenas o ID, buscar o modelo
            $customFieldId = $customField;
            $context = $this->context;

            // Se não temos context na requisição, buscar do banco
            if (!$context && $customFieldId) {
                $model = \App\Models\CustomField::find($customFieldId);
                $context = $model ? $model->context : null;
            }
        }

        return [
            'context' => ['sometimes', Rule::in(['Project', 'Timesheet', 'Expense', 'Customer'])],
            'label' => ['sometimes', 'string', 'max:255'],
            'key' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('custom_fields', 'key')
                    ->where(function ($query) use ($context) {
                        if ($context) {
                            return $query->where('context', $context);
                        }
                        return $query;
                    })
                    ->ignore($customFieldId),
            ],
            'type' => ['sometimes', Rule::in(['text', 'number', 'boolean', 'date', 'select'])],
            'required' => ['sometimes', 'boolean'],
            'options' => [
                'nullable',
                'array',
                Rule::requiredIf($this->type === 'select'),
            ],
            'options.*' => ['required_with:options', 'string'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'context.in' => 'O contexto deve ser Project, Timesheet, Expense ou Customer.',
            'label.string' => 'O label deve ser uma string.',
            'key.regex' => 'A chave deve conter apenas letras minúsculas, números e underscore.',
            'key.unique' => 'Já existe um campo com esta chave neste contexto.',
            'type.in' => 'O tipo deve ser text, number, boolean, date ou select.',
            'options.required' => 'As opções são obrigatórias para campos do tipo select.',
            'options.array' => 'As opções devem ser um array.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Converter o key para slug automaticamente
        if ($this->has('key')) {
            $this->merge([
                'key' => \Illuminate\Support\Str::slug($this->key, '_'),
            ]);
        }
    }
}

