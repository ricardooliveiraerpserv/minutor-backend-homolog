<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Help Desk — Cadastro de tags de classificação. */
class HelpDeskTagController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => HelpDeskTag::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name'  => 'required|string|max:80|unique:helpdesk_tags,name',
            'color' => 'nullable|string|max:16',
        ]);
        return response()->json(['data' => HelpDeskTag::create($v)], 201);
    }

    public function destroy(HelpDeskTag $tag): JsonResponse
    {
        $tag->delete();
        return response()->json(null, 204);
    }
}
