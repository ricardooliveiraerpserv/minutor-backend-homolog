<?php

namespace App\Http\Controllers;

use App\Models\CustomerContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CustomerContact::with('customer:id,name')
            ->when($request->query('customer_id'), fn($q) => $q->where('customer_id', $request->query('customer_id')))
            ->orderBy('name');

        if ($request->query('customer_id')) {
            // Sem paginação quando filtrando por cliente (usado em selects de contratos/projetos)
            return response()->json($query->get());
        }

        return response()->json($query->paginate($request->query('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'name'        => 'required|string|max:255',
            'cargo'       => 'nullable|string|max:255',
            'email'       => 'nullable|email|max:255',
            'phone'       => 'nullable|string|max:50',
        ]);

        $contact = CustomerContact::create($validated);
        return response()->json($contact->load('customer:id,name'), 201);
    }

    public function update(Request $request, CustomerContact $customerContact): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'sometimes|exists:customers,id',
            'name'        => 'sometimes|required|string|max:255',
            'cargo'       => 'nullable|string|max:255',
            'email'       => 'nullable|email|max:255',
            'phone'       => 'nullable|string|max:50',
        ]);

        $customerContact->update($validated);
        return response()->json($customerContact->fresh()->load('customer:id,name'));
    }

    public function destroy(CustomerContact $customerContact): JsonResponse
    {
        $customerContact->delete();
        return response()->json(null, 204);
    }
}
