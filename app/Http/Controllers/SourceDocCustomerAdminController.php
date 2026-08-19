<?php

namespace App\Http\Controllers;

use App\Models\SourceDocCustomerSetting;
use App\Models\SourceDocSourceRequest;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Central de Fontes — administração por empresa: detentor de fonte, ocultar da Central e
 * solicitações de fonte. Escopo por cliente respeitado (deny-by-default). Sem IA/motor.
 */
class SourceDocCustomerAdminController extends Controller
{
    public function __construct(private SourceDocCustomerScope $scope)
    {
    }

    /** PUT /source-docs/customers/{customer}/settings {own_source?, hidden?} — detentor / ocultar. */
    public function updateSettings(Request $request, int $customer): JsonResponse
    {
        if (! $this->scope->canAccessCustomerId($request->user(), $customer)) {
            return response()->json(['message' => 'Cliente fora do seu escopo.'], 404);
        }
        $data = $request->validate([
            'own_source' => ['sometimes', 'boolean'],
            'hidden' => ['sometimes', 'boolean'],
        ]);
        if ($data === []) {
            return response()->json(['message' => 'Nada para atualizar.'], 422);
        }
        $setting = SourceDocCustomerSetting::query()->firstOrNew(['customer_id' => $customer]);
        foreach ($data as $k => $v) {
            $setting->{$k} = (bool) $v;
        }
        $setting->updated_by = $request->user()?->id;
        $setting->save();

        return response()->json(['data' => [
            'customer_id' => $customer,
            'own_source' => (bool) $setting->own_source,
            'hidden' => (bool) $setting->hidden,
        ]]);
    }

    /** POST /source-docs/source-requests {customer_id?, repository?, note} — registra solicitação. */
    public function storeRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'repository' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        if (empty($data['customer_id']) && empty($data['repository']) && empty($data['note'])) {
            return response()->json(['message' => 'Informe ao menos a empresa, o repositório ou uma observação.'], 422);
        }
        if (! empty($data['customer_id']) && ! $this->scope->canAccessCustomerId($request->user(), (int) $data['customer_id'])) {
            return response()->json(['message' => 'Cliente fora do seu escopo.'], 404);
        }

        $req = SourceDocSourceRequest::create([
            'customer_id' => $data['customer_id'] ?? null,
            'repository' => $data['repository'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'open',
            'requested_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $req], 201);
    }

    /** GET /source-docs/source-requests?status= — lista (gestão). */
    public function listRequests(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'open');
        $q = SourceDocSourceRequest::query()
            ->leftJoin('customers', 'customers.id', '=', 'source_doc_source_requests.customer_id')
            ->when($status !== 'all', fn ($qq) => $qq->where('source_doc_source_requests.status', $status))
            ->orderByDesc('source_doc_source_requests.created_at')
            ->limit(200)
            ->get(['source_doc_source_requests.*', 'customers.name as customer_name']);

        return response()->json(['data' => $q]);
    }
}
