<?php

namespace App\SourceCode\Exceptions;

use RuntimeException;

/**
 * Falha ao falar com o serviço CodeAnalysis (A1).
 * $unavailable=true → indisponibilidade/timeout/5xx (não foi possível criar/consultar o job);
 * $unavailable=false → erro de contrato/4xx (requisição inválida).
 */
class CodeAnalysisException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'codeanalysis_error',
        public readonly bool $unavailable = false,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function unavailable(string $message, string $code = 'service_unavailable', ?int $status = null): self
    {
        return new self($message, $code, true, $status);
    }

    public static function badRequest(string $message, string $code = 'bad_request', ?int $status = null): self
    {
        return new self($message, $code, false, $status);
    }
}
