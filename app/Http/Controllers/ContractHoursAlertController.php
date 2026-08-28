<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractAlertExtraEmail;
use App\Models\ContractContact;
use App\Models\ContractHoursAlert;
use App\Models\CustomerContact;
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

    public function sendManual(Contract $contract): JsonResponse
    {
        if (!$contract->project_id) {
            return response()->json(['message' => 'Contrato sem projeto vinculado.'], 422);
        }
        $project = Project::find($contract->project_id);
        $alert = $this->service->sendManual($project);
        return response()->json([
            'message' => $alert->status === 'sent' ? 'Alerta enviado.' : 'Não foi possível enviar — verifique os destinatários.',
            'alert'   => $alert,
        ] + $this->payloadFor($contract->project_id, $contract));
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

    public function sendManualByProject(Project $project): JsonResponse
    {
        $alert = $this->service->sendManual($project);
        $contract = Contract::where('project_id', $project->id)->first();
        return response()->json([
            'message' => $alert->status === 'sent' ? 'Alerta enviado.' : 'Não foi possível enviar — verifique os destinatários.',
            'alert'   => $alert,
        ] + $this->payloadFor($project->id, $contract, $project));
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
        $preview = null;
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
            $preview = $this->service->preview($proj);
        }

        $contacts = [];
        $extraEmails = [];
        $customerContacts = [];
        if ($contract) {
            $contract->loadMissing(['contacts', 'alertExtraEmails']);
            $contacts = $contract->contacts->map(fn ($c) => [
                'id'    => $c->id,
                'name'  => $c->name,
                'email' => $c->email,
                'cargo' => $c->cargo,
                'recebe_alerta_consumo' => (bool) $c->recebe_alerta_consumo,
            ])->values();

            // E-mails avulsos (destinatários adicionais deste contrato).
            $extraEmails = $contract->alertExtraEmails
                ->map(fn ($x) => ['id' => $x->id, 'email' => $x->email])
                ->values();

            // Contatos do CLIENTE disponíveis para importar (copiar): os que têm e-mail e
            // ainda NÃO estão no contrato (dedup por e-mail normalizado). Fonte = registro
            // de contatos do cliente, que agrega os contatos de todos os projetos dele.
            if ($contract->customer_id) {
                $onContract = $contract->contacts
                    ->pluck('email')->filter()
                    ->map(fn ($e) => mb_strtolower(trim($e)))->flip();
                $customerContacts = CustomerContact::where('customer_id', $contract->customer_id)
                    ->whereNotNull('email')->orderBy('name')->get()
                    ->filter(fn ($cc) => $cc->email && !$onContract->has(mb_strtolower(trim($cc->email))))
                    ->map(fn ($cc) => ['id' => $cc->id, 'name' => $cc->name, 'email' => $cc->email, 'cargo' => $cc->cargo])
                    ->values();
            }
        }

        return [
            'enabled'           => $this->service->isEnabled(),
            'contract_id'       => $contract->id ?? null,
            'current'           => $current,
            'preview'           => $preview,
            'contacts'          => $contacts,
            'extra_emails'      => $extraEmails,
            'customer_contacts' => $customerContacts,
            'alerts'            => $alerts,
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
            'contacts'                          => 'nullable|array',
            'contacts.*.id'                     => 'required|integer',
            'contacts.*.recebe_alerta_consumo'  => 'required|boolean',
            'add_customer_contacts'             => 'nullable|array',
            'add_customer_contacts.*'           => 'integer',
            'extra_emails'                      => 'nullable|array',
            'extra_emails.*'                    => 'email',
        ]);

        // 1) Toggle da flag nos contatos JÁ existentes do contrato (comportamento original).
        if (!empty($data['contacts'])) {
            $map = collect($data['contacts'])->pluck('recebe_alerta_consumo', 'id');
            ContractContact::where('contract_id', $contract->id)
                ->whereIn('id', $map->keys())
                ->get()
                ->each(function (ContractContact $c) use ($map) {
                    $c->recebe_alerta_consumo = (bool) $map[$c->id];
                    $c->save();
                });
        }

        // 2) Importar contatos do CLIENTE → COPIAR snapshot para contract_contacts (recebe=true).
        //    Anti-IDOR: só contatos do MESMO customer do contrato (ids de outro cliente são
        //    ignorados). Dedup por e-mail normalizado contra os contatos já do contrato.
        //    A cópia é dona do contrato: mudanças no contato global do cliente não a afetam.
        if (!empty($data['add_customer_contacts']) && $contract->customer_id) {
            $existing = array_flip(
                ContractContact::where('contract_id', $contract->id)
                    ->pluck('email')->filter()->map(fn ($e) => mb_strtolower(trim($e)))->all()
            );
            CustomerContact::where('customer_id', $contract->customer_id)
                ->whereIn('id', $data['add_customer_contacts'])
                ->get()
                ->each(function (CustomerContact $cc) use ($contract, &$existing) {
                    $norm = mb_strtolower(trim((string) $cc->email));
                    if ($norm === '' || isset($existing[$norm])) return; // sem e-mail ou já existe
                    ContractContact::create([
                        'contract_id'           => $contract->id,
                        'name'                  => $cc->name,
                        'cargo'                 => $cc->cargo,
                        'email'                 => $cc->email,
                        'phone'                 => $cc->phone,
                        'recebe_alerta_consumo' => true,
                    ]);
                    $existing[$norm] = true;
                });
        }

        // 3) Sincronizar e-mails avulsos (destinatário adicional; NÃO vira contato).
        //    Presença da chave = substituição total: cria os novos, remove os que saíram.
        if (array_key_exists('extra_emails', $data)) {
            $wanted = collect($data['extra_emails'] ?? [])
                ->map(fn ($e) => ['raw' => trim((string) $e), 'norm' => mb_strtolower(trim((string) $e))])
                ->filter(fn ($e) => $e['norm'] !== '')
                ->unique('norm')->values();
            $wantedNorms = $wanted->pluck('norm')->all();

            $del = ContractAlertExtraEmail::where('contract_id', $contract->id);
            if (!empty($wantedNorms)) $del->whereNotIn('normalized_email', $wantedNorms);
            $del->delete();

            foreach ($wanted as $e) {
                ContractAlertExtraEmail::firstOrCreate(
                    ['contract_id' => $contract->id, 'normalized_email' => $e['norm']],
                    ['email' => $e['raw']]
                );
            }
        }

        return $this->payloadFor($contract->project_id, $contract->fresh());
    }
}
