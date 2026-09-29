<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskDepartment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD de departamentos do Help Desk (escopo por cliente) + vínculo pessoa↔departamento.
 * Gated por block.cliente na rota (só interno gerencia).
 */
class HelpDeskDepartmentController extends Controller
{
    /** Lista departamentos de um cliente (obrigatório customer_id). */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['customer_id' => 'required|integer|exists:customers,id']);
        $deps = HelpDeskDepartment::where('customer_id', $v['customer_id'])
            ->orderBy('name')
            ->get(['id', 'customer_id', 'name', 'active']);
        return response()->json(['data' => $deps]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'name'        => 'required|string|max:120',
            'active'      => 'sometimes|boolean',
        ]);

        $exists = HelpDeskDepartment::where('customer_id', $v['customer_id'])
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($v['name']))])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Já existe um departamento com esse nome para este cliente.'], 422);
        }

        $dep = HelpDeskDepartment::create([
            'customer_id' => $v['customer_id'],
            'name'        => trim($v['name']),
            'active'      => $v['active'] ?? true,
            'company_id'  => Auth::user()?->current_company_id,
        ]);
        return response()->json(['data' => $dep], 201);
    }

    public function update(Request $request, HelpDeskDepartment $department): JsonResponse
    {
        $v = $request->validate([
            'name'   => 'sometimes|required|string|max:120',
            'active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('name', $v)) {
            $dup = HelpDeskDepartment::where('customer_id', $department->customer_id)
                ->where('id', '!=', $department->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim($v['name']))])
                ->exists();
            if ($dup) {
                return response()->json(['message' => 'Já existe um departamento com esse nome para este cliente.'], 422);
            }
            $department->name = trim($v['name']);
        }
        if (array_key_exists('active', $v)) {
            $department->active = $v['active'];
        }
        $department->save();
        return response()->json(['data' => $department]);
    }

    public function destroy(HelpDeskDepartment $department): JsonResponse
    {
        // Desvincula as pessoas antes de remover (a FK é nullOnDelete, mas soft-delete
        // não dispara o cascade do banco — limpa explicitamente).
        User::where('helpdesk_department_id', $department->id)->update(['helpdesk_department_id' => null]);
        $department->delete();
        return response()->json(['message' => 'Departamento removido.']);
    }

    /** Define (ou remove) o departamento de uma pessoa (usuário cliente). */
    public function setPersonDepartment(Request $request, User $user): JsonResponse
    {
        $v = $request->validate(['helpdesk_department_id' => 'nullable|integer|exists:helpdesk_departments,id']);

        if (!empty($v['helpdesk_department_id'])) {
            $dep = HelpDeskDepartment::find($v['helpdesk_department_id']);
            // O departamento tem que ser do MESMO cliente da pessoa.
            abort_if($user->customer_id === null || (int) $dep->customer_id !== (int) $user->customer_id,
                422, 'Departamento não pertence ao cliente desta pessoa.');
        }

        $user->helpdesk_department_id = $v['helpdesk_department_id'] ?? null; // fora do mass-assign
        $user->save();
        return response()->json(['data' => ['id' => $user->id, 'helpdesk_department_id' => $user->helpdesk_department_id]]);
    }
}
