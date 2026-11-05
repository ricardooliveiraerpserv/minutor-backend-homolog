<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

/**
 * Consulta pública de CNPJ (BrasilAPI) p/ auto-preencher a Razão Social no cadastro de cliente.
 * Proxy no backend p/ evitar CORS/CSP no navegador. Só leitura.
 */
class CnpjController extends Controller
{
    public function show(string $cnpj): JsonResponse
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);
        if (strlen($cnpj) !== 14) {
            return response()->json(['error' => 'CNPJ deve ter 14 dígitos.'], 422);
        }

        try {
            $resp = Http::timeout(8)->acceptJson()->get("https://brasilapi.com.br/api/cnpj/v1/{$cnpj}");
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Não foi possível consultar o CNPJ agora.'], 502);
        }

        if ($resp->status() === 404) {
            return response()->json(['error' => 'CNPJ não encontrado.'], 404);
        }
        if (!$resp->successful()) {
            return response()->json(['error' => 'Não foi possível consultar o CNPJ agora.'], 502);
        }

        $d = (array) $resp->json();
        return response()->json([
            'cnpj'          => $cnpj,
            'razao_social'  => $d['razao_social']  ?? ($d['nome'] ?? null),
            'nome_fantasia' => $d['nome_fantasia'] ?? null,
        ]);
    }
}
