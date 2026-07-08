<?php

namespace App\Http\Controllers;

use App\Services\OperationsCenterService;
use App\Services\OperationsDiagnostics;
use Illuminate\Http\JsonResponse;

/**
 * Central de Operações do Help Desk — torre de controle do coordenador.
 *
 * Compõe o NÚCLEO DE DADOS ({@see OperationsCenterService}) com os DIAGNÓSTICOS
 * ({@see OperationsDiagnostics}, regras → estrutura, IA-swappable). A resposta é a tela
 * inteira: atenções priorizadas + tendências (frases) no topo, depois equipe, filas,
 * clientes em risco e sessões. Tudo acionável; zero indicador sem ação.
 */
class HelpDeskOpsController extends Controller
{
    public function index(OperationsCenterService $center, OperationsDiagnostics $diag): JsonResponse
    {
        $blocos = $center->build();
        $diagnostico = $diag->analyze($blocos);

        return response()->json(['data' => [
            'atencoes'   => $diagnostico['atencoes'],
            'tendencias' => $diagnostico['tendencias'],
            'equipe'     => $blocos['equipe'],
            'filas'      => $blocos['filas'],
            'clientes'   => $blocos['clientes'],
            'sessoes'    => $blocos['sessoes'],
            'gerado_em'  => now()->toIso8601String(),
        ]]);
    }
}
