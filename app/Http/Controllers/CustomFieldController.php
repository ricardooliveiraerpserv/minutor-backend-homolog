<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCustomFieldValuesRequest;
use App\Http\Requests\StoreCustomFieldRequest;
use App\Http\Requests\UpdateCustomFieldRequest;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * @OA\Tag(
 *     name="Custom Fields",
 *     description="Gerenciamento de campos customizados"
 * )
 */
class CustomFieldController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/custom-fields",
     *     summary="Listar campos customizados",
     *     tags={"Custom Fields"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="context",
     *         in="query",
     *         description="Contexto (Project, Timesheet, Expense, Customer)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"Project", "Timesheet", "Expense", "Customer"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de campos customizados"
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CustomField::class);

        $query = CustomField::with('creator:id,name,email');

        // Filtrar por contexto se fornecido
        if ($request->has('context')) {
            $query->forContext($request->context);
        }

        $fields = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'hasNext' => false,
            'items' => $fields
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/custom-fields",
     *     summary="Criar campo customizado",
     *     tags={"Custom Fields"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"context","label","key","type"},
     *             @OA\Property(property="context", type="string", enum={"Project", "Timesheet", "Expense", "Customer"}),
     *             @OA\Property(property="label", type="string"),
     *             @OA\Property(property="key", type="string"),
     *             @OA\Property(property="type", type="string", enum={"text", "number", "boolean", "date", "select"}),
     *             @OA\Property(property="required", type="boolean"),
     *             @OA\Property(property="options", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Campo criado com sucesso"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Sem permissão"
     *     )
     * )
     */
    public function store(StoreCustomFieldRequest $request): JsonResponse
    {
        Gate::authorize('create', CustomField::class);

        $customField = CustomField::create([
            'context' => $request->context,
            'label' => $request->label,
            'key' => $request->key,
            'type' => $request->type,
            'required' => $request->boolean('required', false),
            'options' => $request->options,
            'created_by' => auth()->id(),
        ]);

        $customField->load('creator:id,name,email');

        return response()->json($customField, 201);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/custom-fields/{id}",
     *     summary="Buscar campo customizado",
     *     tags={"Custom Fields"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campo customizado"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campo não encontrado"
     *     )
     * )
     */
    public function show(CustomField $customField): JsonResponse
    {
        Gate::authorize('view', $customField);

        $customField->load('creator:id,name,email');

        return response()->json($customField);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/custom-fields/{id}",
     *     summary="Atualizar campo customizado",
     *     tags={"Custom Fields"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="label", type="string"),
     *             @OA\Property(property="key", type="string"),
     *             @OA\Property(property="type", type="string"),
     *             @OA\Property(property="required", type="boolean"),
     *             @OA\Property(property="options", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campo atualizado"
     *     )
     * )
     */
    public function update(UpdateCustomFieldRequest $request, CustomField $customField): JsonResponse
    {
        Gate::authorize('update', $customField);

        $customField->update($request->validated());

        $customField->load('creator:id,name,email');

        return response()->json($customField);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/custom-fields/{id}",
     *     summary="Deletar campo customizado",
     *     tags={"Custom Fields"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Campo deletado"
     *     )
     * )
     */
    public function destroy(CustomField $customField): JsonResponse
    {
        Gate::authorize('delete', $customField);

        $customField->delete();

        return response()->json(null, 204);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/{context}/{entityId}/custom-field-values",
     *     summary="Buscar valores de campos customizados de uma entidade",
     *     tags={"Custom Fields"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="context",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", enum={"projects", "timesheets", "expenses", "customers"})
     *     ),
     *     @OA\Parameter(
     *         name="entityId",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Valores dos campos customizados"
     *     )
     * )
     */
    public function getValues(string $context, int $entityId): JsonResponse
    {
        $contextMap = [
            'projects' => 'Project',
            'timesheets' => 'Timesheet',
            'expenses' => 'Expense',
            'customers' => 'Customer',
        ];

        $contextName = $contextMap[$context] ?? null;

        if (!$contextName) {
            return response()->json([
                'error' => 'Invalid context',
                'message' => 'O contexto deve ser projects, timesheets, expenses ou customers.'
            ], 400);
        }

        // Buscar todos os campos customizados do contexto
        $customFields = CustomField::forContext($contextName)->get();

        // Buscar valores salvos
        $values = CustomFieldValue::whereIn('custom_field_id', $customFields->pluck('id'))
            ->where('entity_id', $entityId)
            ->get()
            ->keyBy('custom_field_id');

        // Montar resposta com campos e valores
        $response = $customFields->map(function ($field) use ($values) {
            $value = $values->get($field->id);

            return [
                'field' => $field,
                'value' => $value ? $value->value : null,
            ];
        });

        return response()->json([
            'hasNext' => false,
            'items' => $response
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/{context}/{entityId}/custom-field-values",
     *     summary="Salvar valores de campos customizados",
     *     tags={"Custom Fields"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="context",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", enum={"projects", "timesheets", "expenses", "customers"})
     *     ),
     *     @OA\Parameter(
     *         name="entityId",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="values",
     *                 type="object",
     *                 @OA\AdditionalProperties(type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Valores salvos com sucesso"
     *     )
     * )
     */
    public function saveValues(SaveCustomFieldValuesRequest $request, string $context, int $entityId): JsonResponse
    {
        $contextMap = [
            'projects' => 'Project',
            'timesheets' => 'Timesheet',
            'expenses' => 'Expense',
            'customers' => 'Customer',
        ];

        $contextName = $contextMap[$context] ?? null;

        if (!$contextName) {
            return response()->json([
                'error' => 'Invalid context',
                'message' => 'O contexto deve ser projects, timesheets, expenses ou customers.'
            ], 400);
        }

        DB::beginTransaction();

        try {
            $values = $request->values;

            // Buscar todos os campos customizados do contexto
            $customFields = CustomField::forContext($contextName)->get()->keyBy('key');

            foreach ($values as $key => $value) {
                $customField = $customFields->get($key);

                if (!$customField) {
                    continue; // Ignorar campos que não existem
                }

                // Buscar ou criar o valor
                CustomFieldValue::updateOrCreate(
                    [
                        'custom_field_id' => $customField->id,
                        'entity_id' => $entityId,
                    ],
                    [
                        'value' => $value,
                    ]
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Valores salvos com sucesso.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Save failed',
                'message' => 'Erro ao salvar valores: ' . $e->getMessage()
            ], 500);
        }
    }
}

