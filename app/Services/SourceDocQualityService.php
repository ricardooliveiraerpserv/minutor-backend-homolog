<?php

namespace App\Services;

use App\SourceCode\Exceptions\CodeAnalysisException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente server-to-server da API JSON do CodeAnalysis (contrato entregue no Gate A1).
 *
 * O Minutor é a autoridade de negócio; este serviço só repassa dados TÉCNICOS (filename + conteúdo)
 * e lê o resultado técnico. NÃO envia source_doc_id/customer/usuário como verdade — apenas um
 * `context` opaco (correlação). O token NUNCA é logado. Sem retry cego no POST de criação (o job
 * poderia duplicar); o A1 ainda deduplica por blob, mas não confiamos nisso para reenviar.
 */
class SourceDocQualityService
{
    public function __construct(
        private string $baseUrl,
        private ?string $token,
        private int $timeout = 120,
        private bool $enabled = false,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim((string) config('services.codeanalysis.base_url', ''), '/'),
            token: config('services.codeanalysis.token'),
            timeout: (int) config('services.codeanalysis.timeout', 120),
            enabled: (bool) config('services.codeanalysis.enabled', false),
        );
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->baseUrl !== '' && (string) $this->token !== '';
    }

    private function client()
    {
        return Http::withToken((string) $this->token)
            ->acceptJson()
            ->timeout($this->timeout);
    }

    /**
     * Cria uma análise no CodeAnalysis. Retorna o corpo decodificado (job_id, status,
     * source_blob_sha, engine, reused, ...). Sem retry (evita job duplicado).
     *
     * @param  array<string,mixed>  $context  opaco — apenas ecoado pelo serviço
     * @throws CodeAnalysisException
     */
    public function analyze(string $filename, string $content, array $context = [], bool $force = false): array
    {
        if (! $this->enabled()) {
            throw CodeAnalysisException::unavailable('Serviço de qualidade desabilitado/não configurado.', 'disabled');
        }

        try {
            $res = $this->client()->post("{$this->baseUrl}/api/v1/analyses", [
                'filename' => $filename,
                'content'  => $content,
                'context'  => $context,
                'reuse'    => ! $force,
                'force'    => $force,
            ]);
        } catch (ConnectionException $e) {
            // timeout / DNS / conexão recusada → indisponível (nenhum job criado)
            Log::warning('[CodeAnalysis] indisponível no POST /analyses', ['error' => $e->getMessage()]);
            throw CodeAnalysisException::unavailable('CodeAnalysis indisponível.', 'connection_error');
        }

        if ($res->serverError()) {
            Log::warning('[CodeAnalysis] 5xx no POST /analyses', ['status' => $res->status()]);
            throw CodeAnalysisException::unavailable('CodeAnalysis retornou erro.', 'upstream_5xx', $res->status());
        }
        if ($res->clientError()) {
            throw CodeAnalysisException::badRequest(
                (string) ($res->json('message') ?? 'Requisição inválida ao CodeAnalysis.'),
                (string) ($res->json('error') ?? 'bad_request'),
                $res->status(),
            );
        }

        $body = $res->json();
        if (! is_array($body) || ! isset($body['job_id'])) {
            throw CodeAnalysisException::unavailable('Resposta inválida do CodeAnalysis (sem job_id).', 'invalid_response');
        }
        return $body;
    }

    /**
     * Consulta o estado/resultado de um job. Retorna o corpo decodificado, ou null se 404.
     * @throws CodeAnalysisException em indisponibilidade/5xx.
     */
    public function getJob(string $jobId): ?array
    {
        if (! $this->enabled()) {
            throw CodeAnalysisException::unavailable('Serviço de qualidade desabilitado/não configurado.', 'disabled');
        }

        try {
            $res = $this->client()->get("{$this->baseUrl}/api/v1/analyses/{$jobId}");
        } catch (ConnectionException $e) {
            throw CodeAnalysisException::unavailable('CodeAnalysis indisponível.', 'connection_error');
        }

        if ($res->status() === 404) {
            return null;
        }
        if ($res->serverError()) {
            throw CodeAnalysisException::unavailable('CodeAnalysis retornou erro.', 'upstream_5xx', $res->status());
        }
        $body = $res->json();
        return is_array($body) ? $body : null;
    }
}
