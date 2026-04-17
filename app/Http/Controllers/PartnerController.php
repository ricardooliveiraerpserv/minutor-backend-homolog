<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PartnerController extends Controller
{
    /** GET /partners */
    public function index(Request $request): JsonResponse
    {
        $query = Partner::query();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->get('search') . '%');
        }

        if ($request->filled('active')) {
            $query->where('active', filter_var($request->get('active'), FILTER_VALIDATE_BOOLEAN));
        }

        $pageSize = min((int) $request->get('pageSize', 20), 200);

        if ($pageSize === -1) {
            $items = $query->orderBy('name')->get();
            return response()->json(['items' => $items, 'hasNext' => false]);
        }

        $paginator = $query->orderBy('name')->paginate($pageSize);

        return response()->json([
            'items'   => $paginator->items(),
            'hasNext' => $paginator->hasMorePages(),
            'total'   => $paginator->total(),
        ]);
    }

    /** GET /partners/{partner} */
    public function show(Partner $partner): JsonResponse
    {
        return response()->json($partner);
    }

    /** POST /partners */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'document'     => 'nullable|string|max:20',
            'email'        => 'nullable|email|max:255',
            'phone'        => 'nullable|string|max:20',
            'active'       => 'boolean',
            'pricing_type' => 'required|in:fixed,variable',
            'hourly_rate'  => 'nullable|numeric|min:0|max:999999.99',
        ]);

        $partner = Partner::create($data);

        return response()->json($partner, 201);
    }

    /** PUT /partners/{partner} */
    public function update(Request $request, Partner $partner): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'sometimes|required|string|max:255',
            'document'     => 'nullable|string|max:20',
            'email'        => 'nullable|email|max:255',
            'phone'        => 'nullable|string|max:20',
            'active'       => 'boolean',
            'pricing_type' => 'sometimes|required|in:fixed,variable',
            'hourly_rate'  => 'nullable|numeric|min:0|max:999999.99',
        ]);

        $partner->update($data);

        return response()->json($partner);
    }

    /** DELETE /partners/{partner} */
    public function destroy(Partner $partner): JsonResponse
    {
        $partner->delete();

        return response()->json(null, 204);
    }
}
