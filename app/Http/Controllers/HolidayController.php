<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Holiday::query();

        if ($request->filled('year')) {
            $query->whereYear('date', $request->year);
        }
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $holidays = $query->orderBy('date')->get();
        return response()->json(['items' => $holidays]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date'  => 'required|date',
            'name'  => 'required|string|max:255',
            'type'  => 'nullable|in:national,state,municipal,optional',
            'state' => 'nullable|string|size:2',
            'active'=> 'nullable|boolean',
        ]);

        $holiday = Holiday::create($validated);
        return response()->json($holiday, 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $validated = $request->validate([
            'date'  => 'sometimes|date',
            'name'  => 'sometimes|string|max:255',
            'type'  => 'sometimes|in:national,state,municipal,optional',
            'state' => 'nullable|string|size:2',
            'active'=> 'nullable|boolean',
        ]);

        $holiday->update($validated);
        return response()->json($holiday);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();
        return response()->json(null, 204);
    }
}
