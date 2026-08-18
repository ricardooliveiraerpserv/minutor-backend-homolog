<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractContact;
use App\Models\ContractHoursAlert;
use App\Models\Project;
use App\Services\ContractHoursConsumptionAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alertas de consumo de horas — configuração geral (liga/desliga), destinatários
 * (contatos do contrato marcados), histórico e reenvio manual. Acessível tanto por
 * contrato quanto por projeto (a tela "Gestão de Contratos" = /gestao-projetos).
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

    // ───────────────────────── por contrato ─────────────────────────

    public function index(Contract $contract): JsonResponse
    {
        return response()->json($this->payloadFor($contract->project_id, $contract));
    }

    public function resend(Contract $contract, ContractHoursAlert $alert): JsonResponse
    {
        return $this->doResend($alert, (int) $contract->project_id, (int) $contract->id);
    }

    public function setContacts(Contract $contract, Request $request): JsonResponse
    {
        return response()->json($this->doSetContacts($contract, $request));
    }

    // ───────────────────────── por projeto (Gestão de Contratos) ─────────────────────────

    public function indexByProject(Project $project): JsonResponse
    {
        $contract = Contract::where('project_id', $project->id)->first();
        return response()->json($this->payloadFor($project->id, $contract, $project));
    }

    public function resendByProject(Project $project, ContractHoursAlert $alert): JsonResponse
    {
        return $this->doResend($alert, (int) $project->id, null);
    }

    public function setContactsByProject(Project $project, Request $request): JsonResponse
    {
        $contract = Contract::where('project_id', $project->id)->first();
        if (!$contract) {
            return response()->json(['message' => 'Contrato não encontrado para este projeto.'], 404);
        }
        return response()->json($this->doSetContacts($contract, $request));
    }

    // ───────────────────────── núcleo ─────────────────────────

    private function payloadFor(?int $projectId, ?Contract $contract, ?Project $project = null): array
    {
        $alerts = collect();
        if ($projectId || $contract) {
            $alerts = ContractHoursAlert::query()
                ->where(function ($q) use ($contract, $projectId) {
                    if ($contract) $q->orWhere('contract_id', $contract->id);
                    if ($projectId) $q->orWhere('project_id', $projectId);
                })
                ->orderByDesc('created_at')
                ->get();
        }

        $current = null;
        $proj = $project ?: ($projectId ? Project::find($projectId) : null);
        if ($proj) {
            $m = $this->service->metrics($proj);
            $current = [
                'available'  => round($m['available'], 1),
                'consumed'   => round($m['consumed'], 1),
                'approved'   => round($m['approved'], 1),
                'balance'    => round($m['balance'], 1),
                'percentual' => round($m['percentual'], 1),
                'basis'      => $m['basis'],
            ];
        }

        $contacts = [];
        if ($contract) {
            $contract->loadMissing('contacts');
            $contacts = $contract->contacts->map(fn ($c) => [
                'id'    => $c->id,
                'name'  => $c->name,
                'email' => $c->email,
                'cargo' => $c->cargo,
                'recebe_alerta_consumo' => (bool) $c->recebe_alerta_consumo,
            ])->values();
        }

        return [
            'enabled'     => $this->service->isEnabled(),
            'contract_id' => $contract->id ?? null,
            'current'     => $current,
            'contacts'    => $contacts,
            'alerts'      => $alerts,
        ];
    }

    private function doResend(ContractHoursAlert $alert, ?int $projectId, ?int $contractId): JsonResponse
    {
        $ok = ($projectId && (int) $alert->project_id === $projectId)
            || ($contractId && (int) $alert->contract_id === $contractId);
        if (!$ok) {
            return response()->json(['message' => 'Alerta não pertence a este contrato.'], 404);
        }
        $updated = $this->service->resend($alert);
        return response()->json([
            'message' => $updated->status === 'sent' ? 'Alerta reenviado.' : 'Não foi possível enviar — verifique os destinatários.',
            'alert'   => $updated,
        ]);
    }

    private function doSetContacts(Contract $contract, Request $request): array
    {
        $data = $request->validate([
            'contacts'                          => 'required|array',
            'contacts.*.id'                     => 'required|integer',
            'contacts.*.recebe_alerta_consumo'  => 'required|boolean',
        ]);

        $map = collect($data['contacts'])->pluck('recebe_alerta_consumo', 'id');
        ContractContact::where('contract_id', $contract->id)
            ->whereIn('id', $map->keys())
            ->get()
            ->each(function (ContractContact $c) use ($map) {
                $c->recebe_alerta_consumo = (bool) $map[$c->id];
                $c->save();
            });

        return $this->payloadFor($contract->project_id, $contract->fresh());
    }
}
