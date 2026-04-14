<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomFieldRequest extends FormRequest
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
        return [
            'context' => ['required', Rule::in(['Project', 'Timesheet', 'Expense', 'Customer'])],
            'label' => ['required', 'string', 'max:255'],
            'key' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9_]+$/', // Apenas lowercase, números e underscore
                Rule::unique('custom_fields', 'key')->where(function ($query) {
                    return $query->where('context', $this->context);
                }),
            ],
            'type' => ['required', Rule::in(['text', 'number', 'boolean', 'date', 'select'])],
            'required' => ['boolean'],
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
            'context.required' => 'O contexto é obrigatório.',
            'context.in' => 'O contexto deve ser Project, Timesheet, Expense ou Customer.',
            'label.required' => 'O label é obrigatório.',
            'key.required' => 'A chave é obrigatória.',
            'key.regex' => 'A chave deve conter apenas letras minúsculas, números e underscore.',
            'key.unique' => 'Já existe um campo com esta chave neste contexto.',
            'type.required' => 'O tipo é obrigatório.',
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

