<?php

namespace App\Console\Commands;

use App\Models\SourceDocVersion;
use App\Models\SystemSetting;
use App\SourceCode\Analyzer\AdvplAnalyzer;
use App\SourceCode\Analyzer\SecretSanitizer;
use App\SourceCode\Analyzer\SourceDiff;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use App\SourceCode\Exceptions\SourceIntegrationException;
use App\SourceCode\GithubAppAuth;
use Illuminate\Console\Command;

/**
 * Fase 1 — valida a extração determinística num fonte REAL do repositório (sem IA).
 * Busca o conteúdo pela GitHub App (read-only), roda sanitizador + AdvplAnalyzer (+ diff se
 * --parent), grava o deterministic_json em system_settings 'diag_analyze' e imprime.
 *
 *   php artisan source-doc:analyze erpserv-clientes jng main FTENVNFE.PRW
 *   php artisan source-doc:analyze erpserv-clientes jng main FTENVNFE.PRW --parent=<sha>
 */
class SourceDocAnalyzeCommand extends Command
{
    protected $signature = 'source-doc:analyze {owner} {repo} {path} {--branch=main} {--ref=} {--parent=} {--semantic} {--persist}';
    protected $description = 'Fase 1/2/3: extração determinística (+ semântica com --semantic) (+ pipeline/persistência com --persist).';

    public function handle(GithubAppAuth $auth, AdvplAnalyzer $analyzer, SecretSanitizer $sanitizer, SourceDiff $differ, SourceDocSemanticAnalyzer $semantic): int
    {
        // --persist: valida o pipeline completo (Fase 3) persistindo source_docs + source_doc_versions.
        if ($this->option('persist')) {
            return $this->persist($auth);
        }
        $owner = $this->argument('owner');
        $repo = $this->argument('repo');
        $path = $this->argument('path');
        $branch = (string) $this->option('branch');

        // Deixa explícito que ESTE modo (sem --persist) NÃO grava documentação — evita
        // interpretar a ausência de source_docs como falha.
        $this->warn(self::DIAG_NOTICE);

        $code = $auth->getFileContent($owner, $repo, $branch, $path);
        if ($code === null) {
            $reason = $this->classifyReadFailure($auth, $owner, $repo, $branch, $path);
            $this->error('[' . $reason . '] ' . $this->readFailureMessage($reason, $path));
            return self::FAILURE;
        }

        $sec = $sanitizer->scan($code);
        $det = $analyzer->analyze($code, ['path' => $path, 'filename' => basename($path)]);
        $det['security_findings'] = $sec['findings'];

        $diff = null;
        if ($parent = $this->option('parent')) {
            $oldCode = $auth->getFileContent($owner, $repo, $parent, $path);
            $oldDet = $oldCode !== null ? $analyzer->analyze($oldCode, ['path' => $path]) : null;
            $diff = $differ->compare($oldDet, $det, $oldCode, $code);
        } else {
            $diff = $differ->compare(null, $det, null, $code); // criação
        }

        $payload = ['deterministic' => $det, 'diff' => $diff];

        // Camada 2 (opcional) — usa o CÓDIGO MASCARADO (nunca o cru) + fatos + diff.
        if ($this->option('semantic')) {
            $payload['semantic'] = $semantic->analyze($det, $sec['masked'], $diff);
        }

        SystemSetting::set('diag_analyze', json_encode($payload, JSON_UNESCAPED_UNICODE), 'string', 'diag');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    /** Roda o pipeline (Fase 3) num fonte real e persiste, para validação. */
    private function persist(GithubAppAuth $auth): int
    {
        $owner = $this->argument('owner');
        $repoName = $this->argument('repo');
        $path = $this->argument('path');
        $branch = (string) $this->option('branch');

        $repo = \App\Models\ClientSourceRepo::where('owner', $owner)->where('repository', $repoName)->where('active', true)->first();
        if (!$repo) {
            $this->error("ClientSourceRepo não encontrado para {$owner}/{$repoName}");
            return self::FAILURE;
        }
        // --ref: documenta o CONTEÚDO/BLOB num ref histórico (commit/tag), mantendo o branch do doc.
        // Serve p/ validar "documentalmente antigo" (blob antigo × HEAD atual) sem alterar o Git.
        $ref = (string) ($this->option('ref') ?: $branch);
        $fetched = $auth->getFileWithSha($owner, $repoName, $ref, $path);
        if ($fetched === null) {
            $reason = $this->classifyReadFailure($auth, $owner, $repoName, $ref, $path);
            $this->error('[' . $reason . '] ' . $this->readFailureMessage($reason, $path));
            return self::FAILURE;
        }
        $newCode = $fetched['content'];
        // source_commit_sha = o ref documentado (histórico quando --ref); branch do doc segue --branch.
        $sha = $this->option('ref') ? $ref : $auth->getBranchHeadSha($owner, $repoName, $branch);

        $ver = app(\App\SourceCode\SourceDocPipeline::class)->processFile([
            'customer_id' => $repo->customer_id, 'source_repo_id' => $repo->id,
            'owner' => $owner, 'repository' => $repoName, 'branch' => $branch, 'path' => $path,
            'tipo' => $repo->tipo, 'new_code' => $newCode, 'old_code' => null,
            'source_commit_sha' => $sha, 'source_blob_sha' => $fetched['blob_sha'] ?? null, 'parent_source_commit_sha' => null,
            'gmud_id' => null, 'ticket_number' => 'TESTE-F3', 'responsible_user_id' => null, 'responsavel' => 'Validação Fase 3',
        ], $this->option('semantic'));

        $doc = $ver->doc;

        // Robustez: o pipeline captura exceções e grava status=failed (+ analysis_error + Log::warning).
        // O command NÃO pode terminar com aparência de sucesso nesse caso.
        if (($err = $this->pipelineFailed($ver)) !== null) {
            $this->error("Falha ao documentar {$path} (doc #{$doc->id}, versão #{$ver->id}): {$err}");
            return self::FAILURE;
        }

        $this->info(json_encode([
            'source_doc_id' => $doc->id, 'version_id' => $ver->id,
            'doc_status' => $doc->analysis_status, 'version_status' => $ver->analysis_status,
            'current_version_id' => $doc->current_version_id, 'current_source_sha' => $doc->current_source_sha,
            'has_deterministic' => !empty($ver->deterministic_json), 'has_semantic' => !empty($ver->semantic_json),
            'has_documentation_json' => !empty($doc->documentation_json),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }

    /** Aviso do modo diagnóstico (sem --persist). */
    public const DIAG_NOTICE = 'MODO DIAGNÓSTICO — nenhuma documentação será persistida (use --persist para gravar).';

    /**
     * Classifica a razão de uma falha de LEITURA do fonte em um reason machine-readable
     * (mesmo vocabulário do SourceDocStatusResolver). Não altera o GithubAppAuth: usa a árvore
     * (que lança exceção classificada) só no caminho de erro. NÃO adivinha/corrige o path.
     */
    public function classifyReadFailure(GithubAppAuth $auth, string $owner, string $repo, string $ref, string $path): string
    {
        if (! $auth->isConfigured()) {
            return 'github_unavailable';
        }
        try {
            $tree = $auth->treeBlobShas($owner, $repo, $ref);
        } catch (SourceIntegrationException $e) {
            return match ($e->errorCode) {
                'TIMEOUT'                                                     => 'timeout',
                'AUTHENTICATION_ERROR'                                        => 'authentication_error',
                'REPO_NOT_FOUND', 'REPO_NOT_AUTHORIZED', 'BRANCH_NOT_FOUND',
                'GITHUB_UNAVAILABLE', 'NOT_CONFIGURED',
                'APP_NOT_CONFIGURED', 'APP_NOT_INSTALLED'                     => 'github_unavailable',
                default                                                       => 'resolution_error',
            };
        } catch (\Throwable) {
            return 'resolution_error';
        }
        // Árvore OK (repo/branch/auth resolvem) → o problema é o PATH (inexistente ou case).
        return isset($tree[ltrim($path, '/')]) ? 'resolution_error' : 'source_not_found';
    }

    /** Mensagem operacional sanitizada por reason. Nunca afirma que É case; sugere verificar. */
    public function readFailureMessage(string $reason, string $path): string
    {
        return match ($reason) {
            'source_not_found'     => "Fonte não encontrado no caminho informado ({$path}). Verifique nome, caminho e uso de maiúsculas/minúsculas (o path no GitHub é case-sensitive).",
            'authentication_error' => 'Falha de autenticação ao acessar o repositório no GitHub.',
            'timeout'              => 'Tempo excedido ao acessar o GitHub. Tente novamente.',
            'github_unavailable'   => 'GitHub indisponível ou repositório/branch inacessível.',
            default                => 'Não foi possível resolver o fonte no GitHub (erro técnico).',
        };
    }

    /** Retorna a mensagem de erro SANITIZADA quando a versão terminou 'failed'; null caso contrário. */
    public function pipelineFailed(SourceDocVersion $ver): ?string
    {
        if ($ver->analysis_status !== 'failed') {
            return null;
        }
        $doc = $ver->doc;
        return $this->sanitizeError((string) ($doc?->analysis_error ?: 'falha desconhecida no pipeline'));
    }

    /** Remove credenciais/segredos antes de imprimir mensagens técnicas (token/Bearer/Authorization/chave). */
    public function sanitizeError(string $msg): string
    {
        $msg = (string) preg_replace('/\b(gh[posru]|github_pat)_[A-Za-z0-9_]+/', '[REDACTED_TOKEN]', $msg);
        $msg = (string) preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [REDACTED]', $msg);
        $msg = (string) preg_replace('/Authorization\s*:\s*\S+/i', 'Authorization: [REDACTED]', $msg);
        $msg = (string) preg_replace('/-----BEGIN[^-]*PRIVATE KEY-----.*?-----END[^-]*PRIVATE KEY-----/s', '[REDACTED_KEY]', $msg);
        return mb_substr($msg, 0, 500);
    }
}
