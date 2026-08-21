<?php

namespace App\SourceCode\Gmud;

/**
 * Erro de PUBLICAÇÃO fixável pelo usuário (destino ambíguo, colisão, sem repo, pacote não pronto).
 * O controller converte em HTTP 422. Distinto de falhas de infra/Git (SourceIntegrationException).
 */
class GmudPublishException extends \RuntimeException
{
}
