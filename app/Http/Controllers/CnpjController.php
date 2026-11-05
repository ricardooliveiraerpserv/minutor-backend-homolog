<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

/**
 * Consulta pública de CNPJ p/ auto-preencher a Razão Social no cadastro de cliente.
 * Proxy no backend (evita CORS/CSP no navegador). Só leitura.
 * Fonte 1: BrasilAPI (rápida, sem rate limit). Fonte 2 (fallback): ReceitaWS — usada quando
 * a BrasilAPI está indisponível p/ aquele CNPJ (acontece de forma intermitente).
 */
class CnpjController extends Controller
{
    public function show(string $cnpj): JsonResponse
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);
        if (strlen($cnpj) !== 14) {
            return response()->json(['error' => 'CNPJ deve ter 14 dígitos.'], 422);
        }

        $razao = null;
        $fantasia = null;

        // 1) BrasilAPI
        try {
            $r = Http::timeout(8)->acceptJson()->get("https://brasilapi.com.br/api/cnpj/v1/{$cnpj}");
            if ($r->successful()) {
                $d = (array) $r->json();
                $razao = $d['razao_social'] ?? null;
                $fantasia = $d['nome_fantasia'] ?? null;
            }
        } catch (\Throwable $e) {
            // tenta o fallback abaixo
        }

        // 2) Fallback ReceitaWS (status=OK → nome = razão social, fantasia = nome fantasia)
        if (!$razao) {
            try {
                $r = Http::timeout(12)->acceptJson()->get("https://receitaws.com.br/v1/cnpj/{$cnpj}");
                if ($r->successful()) {
                    $d = (array) $r->json();
                    if (strtoupper((string) ($d['status'] ?? '')) === 'OK') {
                        $razao = $d['nome'] ?? null;
                        $fantasia = $fantasia ?: ($d['fantasia'] ?? null);
                    }
                }
            } catch (\Throwable $e) {
                // ignora — abaixo retorna "não encontrado"
            }
        }

        if (!$razao) {
            return response()->json(['error' => 'CNPJ não encontrado ou serviço indisponível.'], 404);
        }

        return response()->json([
            'cnpj'          => $cnpj,
            'razao_social'  => $razao,
            'nome_fantasia' => $fantasia,
        ]);
    }
}
