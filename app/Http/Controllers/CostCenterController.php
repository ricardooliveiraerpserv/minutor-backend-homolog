<?php

namespace App\Http\Controllers;

use App\Exports\CostCenterTemplateExport;
use App\Imports\CostCenterImport;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectCostCenterAllocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CostCenterController extends Controller
{
    // ── Cadastro de centro de custo por CLIENTE ─────────────────────────────

    public function index(Customer $customer): JsonResponse
    {
        $rows = $customer->costCenters()->orderBy('code')->get(['id', 'code', 'description', 'active']);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $v = $request->validate([
            'code'        => 'required|string|max:60',
            'description' => 'required|string|max:255',
            'active'      => 'boolean',
        ]);

        if ($customer->costCenters()->where('code', $v['code'])->exists()) {
            return response()->json(['message' => 'Já existe um centro de custo com esse código para este cliente.'], 422);
        }

        $cc = $customer->costCenters()->create([
            'code'        => $v['code'],
            'description' => $v['description'],
            'active'      => $v['active'] ?? true,
        ]);

        return response()->json(['data' => $cc->only(['id', 'code', 'description', 'active'])], 201);
    }

    public function update(Request $request, CostCenter $costCenter): JsonResponse
    {
        $v = $request->validate([
            'code'        => 'required|string|max:60',
            'description' => 'required|string|max:255',
            'active'      => 'boolean',
        ]);

        $dup = CostCenter::where('customer_id', $costCenter->customer_id)
            ->where('code', $v['code'])->where('id', '!=', $costCenter->id)->exists();
        if ($dup) {
            return response()->json(['message' => 'Já existe um centro de custo com esse código para este cliente.'], 422);
        }

        $costCenter->update($v);

        return response()->json(['data' => $costCenter->only(['id', 'code', 'description', 'active'])]);
    }

    public function destroy(CostCenter $costCenter): JsonResponse
    {
        $costCenter->allocations()->delete();   // remove os rateios que apontam p/ este CC
        $costCenter->delete();

        return response()->json(['message' => 'Centro de custo excluído.']);
    }

    /** Modelo (.xlsx) de importação: colunas `codigo`, `descricao`. */
    public function template(): BinaryFileResponse
    {
        return Excel::download(new CostCenterTemplateExport, 'modelo_centro_custo.xlsx');
    }

    /** Importa centros de custo do cliente via Excel/CSV (`codigo`, `descricao`) — upsert por código. */
    public function import(Request $request, Customer $customer): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt']);

        $import = new CostCenterImport;
        Excel::import($import, $request->file('file'));

        $criados = 0;
        $atualizados = 0;
        $ignorados = 0;

        foreach ($import->rows as $row) {
            $code = trim((string) ($row['codigo'] ?? $row['code'] ?? ''));
            $desc = trim((string) ($row['descricao'] ?? $row['description'] ?? $row['descrição'] ?? ''));
            if ($code === '' || $desc === '') { $ignorados++; continue; }

            $existing = $customer->costCenters()->where('code', $code)->first();
            if ($existing) {
                $existing->update(['description' => $desc]);
                $atualizados++;
            } else {
                $customer->costCenters()->create(['code' => $code, 'description' => $desc, 'active' => true]);
                $criados++;
            }
        }

        return response()->json([
            'message'     => "Importação concluída: {$criados} criado(s), {$atualizados} atualizado(s), {$ignorados} ignorado(s).",
            'criados'     => $criados,
            'atualizados' => $atualizados,
            'ignorados'   => $ignorados,
        ]);
    }

    // ── Rateio dentro do PROJETO ────────────────────────────────────────────

    /** Estado do rateio de um projeto: valor total, centros de custo do cliente e linhas atuais. */
    public function rateio(Project $project): JsonResponse
    {
        $total = round($project->calculateTotalProjectValue(), 2);

        $centers = $project->customer_id
            ? CostCenter::where('customer_id', $project->customer_id)->where('active', true)
                ->orderBy('code')->get(['id', 'code', 'description'])
            : collect();

        $allocs = ProjectCostCenterAllocation::where('project_id', $project->id)
            ->orderBy('position')->orderBy('id')->get(['cost_center_id', 'percentual']);

        return response()->json([
            'project_total' => $total,
            'cost_centers'  => $centers,
            'allocations'   => $allocs->map(fn ($a) => [
                'cost_center_id' => (int) $a->cost_center_id,
                'percentual'     => (float) $a->percentual,
                'valor'          => round($total * (float) $a->percentual / 100, 2),
            ]),
        ]);
    }

    /** Salva o rateio do projeto. Regra: soma dos percentuais = 100% (ou lista vazia p/ limpar). */
    public function saveRateio(Request $request, Project $project): JsonResponse
    {
        $v = $request->validate([
            'allocations'                  => 'present|array',
            'allocations.*.cost_center_id' => 'required|integer',
            'allocations.*.percentual'     => 'required|numeric|min:0|max:100',
        ]);

        $allocs = $v['allocations'];

        if (count($allocs) > 0) {
            // Todos os centros de custo têm que ser do cliente do projeto.
            $ccIds = collect($allocs)->pluck('cost_center_id')->unique();
            $ok = CostCenter::whereIn('id', $ccIds)->where('customer_id', $project->customer_id)->count();
            if ($ok !== $ccIds->count()) {
                return response()->json(['message' => 'Há centro(s) de custo inválido(s) para este cliente.'], 422);
            }

            $sum = round(collect($allocs)->sum(fn ($a) => (float) $a['percentual']), 2);
            if (abs($sum - 100.0) > 0.01) {
                return response()->json(['message' => "A soma dos percentuais deve ser 100%. Atual: {$sum}%."], 422);
            }
        }

        DB::transaction(function () use ($project, $allocs) {
            ProjectCostCenterAllocation::where('project_id', $project->id)->delete();
            foreach (array_values($allocs) as $i => $a) {
                ProjectCostCenterAllocation::create([
                    'project_id'     => $project->id,
                    'cost_center_id' => (int) $a['cost_center_id'],
                    'percentual'     => round((float) $a['percentual'], 2),
                    'position'       => $i,
                ]);
            }
        });

        return response()->json(['message' => 'Rateio salvo.']);
    }
}
