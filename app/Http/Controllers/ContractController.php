<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractAttachment;
use App\Models\ContractContact;
use App\Models\ContractType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\ProjectContact;
use App\Models\ServiceType;
use App\Services\ProjectCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Contract::with(['customer:id,name', 'contractType:id,name', 'architect:id,name', 'project:id,code,name'])
            ->when($request->query('status'), fn($q) => $q->where('status', $request->query('status')))
            ->when($request->query('customer_id'), fn($q) => $q->where('customer_id', $request->query('customer_id')))
            ->when($request->query('search'), function ($q) use ($request) {
                $s = '%' . $request->query('search') . '%';
                $q->whereHas('customer', fn($c) => $c->where('name', 'ilike', $s));
            })
            ->orderBy('created_at', 'desc');

        return response()->json($query->paginate($request->query('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id'            => 'required|exists:customers,id',
            'categoria'              => 'required|in:projeto,sustentacao',
            'service_type_id'        => 'nullable|exists:service_types,id',
            'contract_type_id'       => 'nullable|exists:contract_types,id',
            'tipo_faturamento'       => 'nullable|in:on_demand,banco_horas_mensal,banco_horas_fixo,por_servico,saas',
            'cobra_despesa_cliente'  => 'boolean',
            'limite_despesa'         => 'nullable|numeric|min:0',
            'architect_id'           => 'nullable|exists:users,id',
            'tipo_alocacao'          => 'nullable|in:remoto,presencial,ambos',
            'horas_contratadas'      => 'required|integer|min:0',
            'valor_projeto'          => 'nullable|numeric|min:0',
            'valor_hora'             => 'nullable|numeric|min:0',
            'hora_adicional'         => 'nullable|numeric|min:0',
            'pct_horas_coordenador'  => 'nullable|numeric|min:0|max:100',
            'horas_consultor'        => 'nullable|integer|min:0',
            'expectativa_inicio'     => 'nullable|date',
            'condicao_pagamento'     => 'nullable|string',
            'executivo_conta_id'     => 'nullable|exists:users,id',
            'vendedor_id'            => 'nullable|exists:users,id',
            'observacoes'            => 'nullable|string',
            'project_code_preview'   => 'nullable|string|max:20',
            'contacts'               => 'nullable|array',
            'contacts.*.name'        => 'required|string',
            'contacts.*.cargo'       => 'nullable|string',
            'contacts.*.email'       => 'nullable|email',
            'contacts.*.phone'       => 'nullable|string',
        ]);

        $contract = DB::transaction(function () use ($validated, $request) {
            $data = collect($validated)->except('contacts')->merge([
                'created_by_id' => auth()->id(),
                'status'        => Contract::STATUS_RASCUNHO,
            ])->toArray();

            $contract = Contract::create($data);

            foreach ($validated['contacts'] ?? [] as $c) {
                ContractContact::create(array_merge($c, ['contract_id' => $contract->id]));
            }

            return $contract;
        });

        return response()->json($contract->load(['customer:id,name', 'contacts', 'attachments']), 201);
    }

    public function show(Contract $contract): JsonResponse
    {
        return response()->json(
            $contract->load(['customer:id,name', 'serviceType:id,name', 'contractType:id,name', 'architect:id,name', 'executivoConta:id,name', 'vendedor:id,name', 'contacts', 'attachments', 'project:id,code,name,status'])
        );
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        if ($contract->project_id) {
            return response()->json(['message' => 'Contrato com projeto gerado não pode ser editado.'], 422);
        }

        $validated = $request->validate([
            'customer_id'            => 'sometimes|exists:customers,id',
            'categoria'              => 'sometimes|in:projeto,sustentacao',
            'service_type_id'        => 'nullable|exists:service_types,id',
            'contract_type_id'       => 'nullable|exists:contract_types,id',
            'tipo_faturamento'       => 'nullable|in:on_demand,banco_horas_mensal,banco_horas_fixo,por_servico,saas',
            'cobra_despesa_cliente'  => 'boolean',
            'limite_despesa'         => 'nullable|numeric|min:0',
            'architect_id'           => 'nullable|exists:users,id',
            'tipo_alocacao'          => 'nullable|in:remoto,presencial,ambos',
            'horas_contratadas'      => 'sometimes|integer|min:0',
            'valor_projeto'          => 'nullable|numeric|min:0',
            'valor_hora'             => 'nullable|numeric|min:0',
            'hora_adicional'         => 'nullable|numeric|min:0',
            'pct_horas_coordenador'  => 'nullable|numeric|min:0|max:100',
            'horas_consultor'        => 'nullable|integer|min:0',
            'expectativa_inicio'     => 'nullable|date',
            'condicao_pagamento'     => 'nullable|string',
            'executivo_conta_id'     => 'nullable|exists:users,id',
            'vendedor_id'            => 'nullable|exists:users,id',
            'observacoes'            => 'nullable|string',
            'project_code_preview'   => 'nullable|string|max:20',
            'contacts'               => 'nullable|array',
            'contacts.*.id'          => 'nullable|exists:contract_contacts,id',
            'contacts.*.name'        => 'required|string',
            'contacts.*.cargo'       => 'nullable|string',
            'contacts.*.email'       => 'nullable|email',
            'contacts.*.phone'       => 'nullable|string',
        ]);

        DB::transaction(function () use ($contract, $validated) {
            $contract->update(collect($validated)->except('contacts')->toArray());

            if (array_key_exists('contacts', $validated)) {
                $contract->contacts()->delete();
                foreach ($validated['contacts'] ?? [] as $c) {
                    ContractContact::create(array_merge($c, ['contract_id' => $contract->id]));
                }
            }
        });

        return response()->json($contract->fresh()->load(['customer:id,name', 'contacts', 'attachments']));
    }

    public function destroy(Contract $contract): JsonResponse
    {
        if ($contract->project_id) {
            return response()->json(['message' => 'Contrato com projeto gerado não pode ser excluído.'], 422);
        }

        foreach ($contract->attachments as $att) {
            Storage::delete($att->path);
        }

        $contract->delete();
        return response()->json(null, 204);
    }

    public function updateStatus(Request $request, Contract $contract): JsonResponse
    {
        $request->validate(['status' => 'required|in:rascunho,aprovado,inicio_autorizado,ativo']);

        $newStatus = $request->input('status');

        if ($newStatus === Contract::STATUS_APROVADO && $contract->status !== Contract::STATUS_RASCUNHO) {
            return response()->json(['message' => 'Apenas contratos em Rascunho podem ser aprovados.'], 422);
        }
        if ($newStatus === Contract::STATUS_INICIO_AUTORIZADO && !in_array($contract->status, [Contract::STATUS_APROVADO, Contract::STATUS_RASCUNHO])) {
            return response()->json(['message' => 'Apenas contratos Aprovados podem ter início autorizado.'], 422);
        }

        $data = ['status' => $newStatus];

        if ($newStatus === Contract::STATUS_APROVADO) {
            $data['approved_by_id'] = auth()->id();
            $data['approved_at']    = now();
        }

        $contract->update($data);
        return response()->json($contract->fresh());
    }

    public function generateProject(Contract $contract): JsonResponse
    {
        if (!in_array($contract->status, [Contract::STATUS_INICIO_AUTORIZADO, Contract::STATUS_APROVADO])) {
            return response()->json(['message' => 'Contrato precisa estar Aprovado ou com Início Autorizado.'], 422);
        }

        if ($contract->project_id) {
            return response()->json(['message' => 'Projeto já gerado para este contrato.', 'project_id' => $contract->project_id], 422);
        }

        $contract->load(['customer', 'contacts', 'attachments']);

        $project = DB::transaction(function () use ($contract) {
            $codeService = new ProjectCodeService();
            $codeData    = $codeService->resolveForStore($contract->project_code_preview, $contract->customer, null);

            $project = Project::create(array_merge($codeData, [
                'name'                  => $contract->customer->name . ' — ' . now()->format('m/Y'),
                'customer_id'           => $contract->customer_id,
                'service_type_id'       => $contract->service_type_id,
                'contract_type_id'      => $contract->contract_type_id,
                'sold_hours'            => $contract->horas_contratadas,
                'project_value'         => $contract->valor_projeto,
                'hourly_rate'           => $contract->valor_hora,
                'additional_hourly_rate' => $contract->hora_adicional,
                'coordinator_hours'     => $contract->pct_horas_coordenador,
                'consultant_hours'      => $contract->horas_consultor,
                'start_date'            => $contract->expectativa_inicio,
                'status'                => Project::STATUS_AWAITING_START,
                'contract_id'           => $contract->id,
                'tipo_alocacao'         => $contract->tipo_alocacao,
                'architect_id'          => $contract->architect_id,
                'condicao_pagamento'    => $contract->condicao_pagamento,
                'observacoes_contrato'  => $contract->observacoes,
                'cobra_despesa_cliente' => $contract->cobra_despesa_cliente,
                'executivo_conta_id'    => $contract->executivo_conta_id,
                'vendedor_id'           => $contract->vendedor_id,
            ]));

            // Copiar contatos
            foreach ($contract->contacts as $c) {
                ProjectContact::create([
                    'project_id'          => $project->id,
                    'contract_contact_id' => $c->id,
                    'name'                => $c->name,
                    'cargo'               => $c->cargo,
                    'email'               => $c->email,
                    'phone'               => $c->phone,
                ]);
            }

            // Referenciar anexos (sem duplicar arquivo)
            foreach ($contract->attachments as $a) {
                ProjectAttachment::create([
                    'project_id'             => $project->id,
                    'contract_attachment_id' => $a->id,
                ]);
            }

            // Vincular arquiteto como coordenador do projeto
            if ($contract->architect_id) {
                $project->coordinators()->attach($contract->architect_id);
            }

            // Atualizar contrato
            $contract->update([
                'project_id'      => $project->id,
                'generated_at'    => now(),
                'generated_by_id' => auth()->id(),
                'status'          => Contract::STATUS_ATIVO,
            ]);

            return $project;
        });

        return response()->json([
            'project_id'   => $project->id,
            'project_code' => $project->code,
            'message'      => 'Projeto gerado com sucesso.',
        ]);
    }

    public function uploadAttachment(Request $request, Contract $contract): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480',
            'type' => 'required|in:proposta,contrato,logo',
        ]);

        $file = $request->file('file');
        $path = $file->store("contracts/{$contract->id}/attachments");

        $attachment = ContractAttachment::create([
            'contract_id'    => $contract->id,
            'type'           => $request->input('type'),
            'path'           => $path,
            'original_name'  => $file->getClientOriginalName(),
            'mime_type'      => $file->getMimeType(),
            'size'           => $file->getSize(),
            'uploaded_by_id' => auth()->id(),
        ]);

        return response()->json($attachment, 201);
    }

    public function downloadAttachment(Contract $contract, ContractAttachment $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_if($attachment->contract_id !== $contract->id, 404);
        abort_unless(Storage::exists($attachment->path), 404, 'Arquivo não encontrado.');

        return Storage::download($attachment->path, $attachment->original_name);
    }

    public function deleteAttachment(Contract $contract, ContractAttachment $attachment): JsonResponse
    {
        abort_if($attachment->contract_id !== $contract->id, 404);

        Storage::delete($attachment->path);
        $attachment->delete();

        return response()->json(null, 204);
    }
}
