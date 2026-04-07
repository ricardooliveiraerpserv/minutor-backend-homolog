<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;

/**
 * Helper methods for standardized API responses
 */
trait ResponseHelpers
{
    private function notFoundResponse(string $message = 'Recurso não encontrado'): JsonResponse
    {
        return response()->json([
            'code' => 'RESOURCE_NOT_FOUND',
            'type' => 'error',
            'message' => $message,
            'detailMessage' => 'O recurso solicitado não existe ou foi removido'
        ], 404);
    }

    private function accessDeniedResponse(string $message = 'Acesso negado'): JsonResponse
    {
        return response()->json([
            'code' => 'ACCESS_DENIED',
            'type' => 'error',
            'message' => $message,
            'detailMessage' => 'Você não tem permissão para realizar esta ação'
        ], 403);
    }

    private function businessRuleResponse(string $code, string $message, string $detail): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'type' => 'error',
            'message' => $message,
            'detailMessage' => $detail
        ], 422);
    }

    private function serverErrorResponse(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'INTERNAL_ERROR',
            'type' => 'error',
            'message' => 'Erro interno do servidor',
            'detailMessage' => $message
        ], 500);
    }

    private function validationErrorResponse(array $errors): JsonResponse
    {
        return response()->json([
            'code' => 'VALIDATION_FAILED',
            'type' => 'error',
            'message' => 'Dados de validação inválidos',
            'detailMessage' => 'Um ou mais campos contêm valores inválidos',
            'details' => $errors
        ], 422);
    }
} 