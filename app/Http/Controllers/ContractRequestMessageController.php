<?php

namespace App\Http\Controllers;

use App\Models\ContractRequest;
use App\Models\ContractRequestMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractRequestMessageController extends Controller
{
    public function index(Request $request, ContractRequest $contractRequest): JsonResponse
    {
        $user = auth()->user();

        // Clientes só veem suas próprias requisições
        if ($user->isCliente() && $user->customer_id !== $contractRequest->customer_id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $messages = $contractRequest->messages()
            ->with('author:id,name')
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
    }

    public function store(Request $request, ContractRequest $contractRequest): JsonResponse
    {
        $user = auth()->user();

        if ($user->isCliente() && $user->customer_id !== $contractRequest->customer_id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $request->validate(['message' => 'required|string|max:2000']);

        $msg = ContractRequestMessage::create([
            'contract_request_id' => $contractRequest->id,
            'user_id'             => $user->id,
            'message'             => $request->input('message'),
        ]);

        $msg->load('author:id,name');

        return response()->json($msg, 201);
    }
}
