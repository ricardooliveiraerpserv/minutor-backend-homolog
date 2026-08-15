<?php

namespace Tests\Feature;

use App\Console\Commands\SourceDocAnalyzeCommand;
use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\SourceCode\Exceptions\SourceIntegrationException;
use App\SourceCode\GithubAppAuth;
use Tests\TestCase;

/**
 * Bloco 3 — correção mínima de robustez/observabilidade do source-doc:analyze:
 *  (1) falha real do pipeline vira exit != 0 (não "aparência de sucesso");
 *  (2) diagnóstico de leitura por reason (source_not_found/auth/unavailable/timeout/resolution);
 *  (3) modo diagnóstico (sem --persist) explícito;
 *  (4) sanitização de segredos nas mensagens.
 * DB-free (a suíte do projeto é DB-free; happy path/no-persist validados no servidor end-to-end).
 */
class SourceDocAnalyzeCommandTest extends TestCase
{
    private function fakeAuth(bool $configured, array|\Throwable $tree): GithubAppAuth
    {
        return new class($configured, $tree) extends GithubAppAuth {
            private bool $cfg;
            /** @var array<string,string>|\Throwable */
            private $tree;
            public function __construct(bool $cfg, $tree)
            {
                parent::__construct();
                $this->cfg = $cfg;
                $this->tree = $tree;
            }
            public function isConfigured(): bool
            {
                return $this->cfg;
            }
            public function treeBlobShas(string $owner, string $repo, string $ref): array
            {
                if ($this->tree instanceof \Throwable) {
                    throw $this->tree;
                }
                return $this->tree;
            }
        };
    }

    /** (A) path ausente na árvore → source_not_found + mensagem operacional (case-sensitive). */
    public function test_read_failure_path_absent_is_source_not_found(): void
    {
        $cmd = new SourceDocAnalyzeCommand();
        $auth = $this->fakeAuth(true, ['src/OUTRO.prw' => 'aaa']);
        $reason = $cmd->classifyReadFailure($auth, 'erpserv-clientes', 'promax', 'main', 'ERPFIN01.prw');
        $this->assertSame('source_not_found', $reason);
        $msg = $cmd->readFailureMessage($reason, 'ERPFIN01.prw');
        $this->assertStringContainsString('ERPFIN01.prw', $msg);
        $this->assertStringContainsStringIgnoringCase('case-sensitive', $msg);
    }

    /** (A) classifica razões técnicas distintas sem transformar em source_not_found. */
    public function test_read_failure_classifies_technical_reasons(): void
    {
        $cmd = new SourceDocAnalyzeCommand();
        $this->assertSame('authentication_error', $cmd->classifyReadFailure($this->fakeAuth(true, new SourceIntegrationException('AUTHENTICATION_ERROR', 'x', 401)), 'o', 'r', 'main', 'p'));
        $this->assertSame('timeout', $cmd->classifyReadFailure($this->fakeAuth(true, new SourceIntegrationException('TIMEOUT', 'x', 504)), 'o', 'r', 'main', 'p'));
        $this->assertSame('github_unavailable', $cmd->classifyReadFailure($this->fakeAuth(true, new SourceIntegrationException('GITHUB_UNAVAILABLE', 'x', 502)), 'o', 'r', 'main', 'p'));
        $this->assertSame('github_unavailable', $cmd->classifyReadFailure($this->fakeAuth(false, []), 'o', 'r', 'main', 'p'));
        $this->assertSame('resolution_error', $cmd->classifyReadFailure($this->fakeAuth(true, new SourceIntegrationException('WEIRD_CODE', 'x', 500)), 'o', 'r', 'main', 'p'));
    }

    /** (A) árvore OK e path PRESENTE, mas leitura falhou → resolution_error (não source_not_found). */
    public function test_read_failure_path_present_is_resolution_error(): void
    {
        $cmd = new SourceDocAnalyzeCommand();
        $reason = $cmd->classifyReadFailure($this->fakeAuth(true, ['ERPFIN01.prw' => 'blob']), 'o', 'r', 'main', 'ERPFIN01.prw');
        $this->assertSame('resolution_error', $reason);
    }

    /** (B) pipeline terminou 'failed' → command deve reportar erro (não sucesso), sanitizado. */
    public function test_pipeline_failed_returns_sanitized_message(): void
    {
        $cmd = new SourceDocAnalyzeCommand();
        $doc = new SourceDoc(['analysis_error' => 'boom no analyzer ghp_ABC123def4567890 Bearer zzz.yyy']);
        $ver = new SourceDocVersion(['analysis_status' => 'failed']);
        $ver->setRelation('doc', $doc);
        $err = $cmd->pipelineFailed($ver);
        $this->assertNotNull($err, 'status failed deve produzir mensagem');
        $this->assertStringContainsString('boom no analyzer', $err);
        $this->assertStringNotContainsString('ghp_ABC123def4567890', $err);
        $this->assertStringNotContainsString('zzz.yyy', $err);
    }

    /** (C) happy path: versão concluída/analisando NÃO é falha → command segue para SUCCESS. */
    public function test_pipeline_ok_is_not_failure(): void
    {
        $cmd = new SourceDocAnalyzeCommand();
        foreach (['analyzing', 'partial', 'completed'] as $st) {
            $ver = new SourceDocVersion(['analysis_status' => $st]);
            $ver->setRelation('doc', new SourceDoc());
            $this->assertNull($cmd->pipelineFailed($ver), "status {$st} não é falha");
        }
    }

    /** (D) modo diagnóstico é explícito na constante usada na saída. */
    public function test_diagnostic_notice_is_explicit(): void
    {
        $this->assertStringContainsString('MODO DIAGNÓSTICO', SourceDocAnalyzeCommand::DIAG_NOTICE);
        $this->assertStringContainsStringIgnoringCase('será persistida', SourceDocAnalyzeCommand::DIAG_NOTICE);
    }

    /** (8) sanitização: nenhum token/credencial/chave vaza nas mensagens. */
    public function test_sanitize_error_masks_secrets(): void
    {
        $cmd = new SourceDocAnalyzeCommand();
        $raw = 'erro tok=ghp_ABC123def4567890 Authorization: Bearer zzz.yyy chave -----BEGIN PRIVATE KEY-----AAABBB-----END PRIVATE KEY-----';
        $out = $cmd->sanitizeError($raw);
        foreach (['ghp_ABC123def4567890', 'zzz.yyy', 'AAABBB'] as $leak) {
            $this->assertStringNotContainsString($leak, $out, "não pode vazar {$leak}");
        }
    }
}
