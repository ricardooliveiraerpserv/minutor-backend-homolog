<?php

namespace App\Http\Controllers;

use App\SourceCode\SourceDocImpactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Central de Fontes — C4b. Análise de IMPACTO (read-model, SEM IA).
 *
 * Gate de PERMISSÃO: source_docs.view (impacto dentro do próprio accessibleCustomerIds()).
 * Gate de ESCOPO/CROSS: dentro do SourceDocImpactService (C4a como fonte da verdade):
 *   cross=true exige global (admin/view_all) OU view_cross_customer; cliente externo nunca cruza.
 * Sanitização de integration/risk acontece no serviço, antes da serialização.
 */
class SourceDocImpactController extends Controller
{
    public function __construct(private SourceDocImpactService $svc)
    {
    }

    /** GET /source-docs/impact?entity=&name=&table=&access=&cross=&page=&per_page= */
    public function impact(Request $request): JsonResponse
    {
        $entity = (string) $request->query('entity', '');
        if (! in_array($entity, SourceDocImpactService::ENTITIES, true)) {
            return response()->json([
                'message' => 'Parâmetro entity inválido.', 'allowed' => SourceDocImpactService::ENTITIES,
            ], 422);
        }

        $name = trim((string) $request->query('name', ''));
        if ($name === '') {
            return response()->json(['message' => 'Parâmetro name é obrigatório.'], 422);
        }

        $access = (string) $request->query('access', 'any');
        if (! in_array($access, ['read', 'write', 'any'], true)) {
            $access = 'any';
        }

        $result = $this->svc->impact($request->user(), [
            'entity'   => $entity,
            'name'     => $name,
            'table'    => $request->filled('table') ? trim((string) $request->query('table')) : null,
            'access'   => $access,
            'cross'    => $request->boolean('cross'),
            'customer_id' => $request->filled('customer_id') ? (int) $request->query('customer_id') : null,
            'page'     => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 30),
        ]);

        // Auditoria de USO CROSS-CUSTOMER sensível (não loga impacto normal; baixo volume).
        // Sanitizado: sem valor bruto de integração/segredo (name só p/ field/table/function).
        if (($result['query']['cross'] ?? false) === true) {
            Log::channel(config('logging.default'))->info('source_docs.impact cross-customer', [
                'actor_user_id' => $request->user()?->id,
                'entity'        => $entity,
                'name'          => $entity === 'integration' ? '[sanitized]' : $name,
                'clientes'      => $result['summary']['clientes'] ?? null,
            ]);
        }

        return response()->json($result);
    }
}
