<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractHoursAlert;
use App\Services\ContractHoursConsumptionAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alertas de consumo de horas — configuração geral (liga/desliga), histórico por
 * contrato e reenvio manual (Gestão de Contratos).
 */
class ContractHoursAlertController extends Controller
{
    public function __construct(private ContractHoursConsumptionAlertService $service) {}

    /** Liga/desliga geral do envio automático. */
    public function settings(): JsonResponse
    {
        return response()->json(['enabled' => $this->service->isEnabled()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $this->service->setEnabled((bool) $data['enabled']);
        return response()->json(['enabled' => $this->service->isEnabled()]);
    }

    /** Histórico de alertas de um contrato (do projeto vinculado). */
    public function index(Contract $contract): JsonResponse
    {
        $projectId = $contract->project_id;

        $query = ContractHoursAlert::query()
            ->where(function ($q) use ($contract, $projectId) {
                $q->where('contract_id', $contract->id);
                if ($projectId) {
                    $q->orWhere('project_id', $projectId);
                }
            })
            ->orderByDesc('created_at');

        // Métricas atuais do contrato (para a tela mostrar o estado em tempo real).
        $current = null;
        if ($projectId && $contract->project) {
            $m = $this->service->metrics($contract->project);
            $current = [
                'available'  => round($m['available'], 1),
                'consumed'   => round($m['consumed'], 1),
                'approved'   => round($m['approved'], 1),
                'balance'    => round($m['balance'], 1),
                'percentual' => round($m['percentual'], 1),
                'basis'      => $m['basis'],
            ];
        }

        return response()->json([
            'enabled' => $this->service->isEnabled(),
            'current' => $current,
            'alerts'  => $query->get(),
        ]);
    }

    /** Reenvio manual de um alerta que falhou / ficou sem destinatário. */
    public function resend(Contract $contract, ContractHoursAlert $alert): JsonResponse
    {
        if ((int) $alert->project_id !== (int) $contract->project_id && (int) $alert->contract_id !== (int) $contract->id) {
            return response()->json(['message' => 'Alerta não pertence a este contrato.'], 404);
        }

        $updated = $this->service->resend($alert);

        return response()->json([
            'message' => $updated->status === 'sent' ? 'Alerta reenviado.' : 'Não foi possível enviar — verifique os destinatários.',
            'alert'   => $updated,
        ]);
    }
}
