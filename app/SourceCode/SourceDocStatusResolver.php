<?php

namespace App\SourceCode;

use App\Models\SourceDoc;
use App\SourceCode\Exceptions\SourceIntegrationException;
use Illuminate\Support\Facades\Cache;

/**
 * Bloco 3 — REGRA DE VERDADE (ponto ÚNICO). Responde "a documentação representa exatamente o
 * arquivo que está atualmente no Git?" comparando:
 *
 *     source_blob_sha DOCUMENTADO  ×  blob_sha ATUAL do MESMO path (git blob SHA)
 *
 * NUNCA pelo commit HEAD do branch (isso gerava falso-positivo: um commit em qualquer arquivo
 * marcava TODOS os docs do repo como desatualizados).
 *
 * Falha técnica JAMAIS vira DESATUALIZADA → sempre NAO_VALIDADO com reason machine-readable.
 *
 * Anti-N+1: a árvore recursiva do HEAD (path→blob_sha) é buscada UMA vez por (owner,repo,branch),
 * cacheada 5–15min, e a comparação é local. 1000 fontes do mesmo repo = 1 resolução da árvore.
 */
class SourceDocStatusResolver
{
    public const STATUS_UPDATED    = 'ATUALIZADA';
    public const STATUS_OUTDATED   = 'DESATUALIZADA';
    public const STATUS_UNVERIFIED = 'NAO_VALIDADO';

    // reasons (só presentes em NAO_VALIDADO)
    public const R_GITHUB_UNAVAILABLE    = 'github_unavailable';
    public const R_REPOSITORY_INACTIVE   = 'repository_inactive';
    public const R_REPOSITORY_NOT_FOUND  = 'repository_not_found';
    public const R_SOURCE_NOT_FOUND      = 'source_not_found';
    public const R_AUTHENTICATION_ERROR  = 'authentication_error';
    public const R_TIMEOUT               = 'timeout';
    public const R_MISSING_DOCUMENTED    = 'missing_documented_sha';
    public const R_RESOLUTION_ERROR      = 'resolution_error';

    public function __construct(private GithubAppAuth $auth)
    {
    }

    private function cacheTtl(): int
    {
        // 5–15 min (default 10). Configurável via SOURCE_DOC_STATUS_CACHE_SECONDS.
        return (int) config('services.source_doc.status_cache_seconds', 600);
    }

    /**
     * Resolve o status de UMA doc viva.
     * @return array{status:string,documented_blob_sha:?string,current_blob_sha:?string,source_commit_sha:?string,checked_at:string,reason:?string,message:string}
     */
    public function resolve(SourceDoc $doc, bool $forceRefresh = false): array
    {
        // Curto-circuitos por-doc (não dependem do Git): repo inativo / sem SHA documentado.
        $pre = $this->preChecks($doc);
        if ($pre !== null) {
            return $pre;
        }
        $tree = $this->treeFor($doc->owner, $doc->repository, $doc->branch, $forceRefresh);
        return $this->compareWithTree($doc, $tree);
    }

    /**
     * Resolve VÁRIAS docs sem N+1: agrupa por (owner,repo,branch), busca cada árvore 1x, compara local.
     * @param  iterable<SourceDoc>  $docs
     * @return array<int,array> doc_id → resultado
     */
    public function resolveMany(iterable $docs, bool $forceRefresh = false): array
    {
        $groups = [];
        $out = [];
        foreach ($docs as $doc) {
            $groups[$doc->owner . "\0" . $doc->repository . "\0" . $doc->branch][] = $doc;
        }
        foreach ($groups as $key => $group) {
            [$owner, $repo, $branch] = explode("\0", $key);
            // Só toca no Git se algum doc do grupo passou dos pré-checks (evita árvore p/ repo inativo).
            $needTree = false;
            foreach ($group as $doc) {
                if ($this->preChecks($doc) === null) { $needTree = true; break; }
            }
            $tree = $needTree
                ? $this->treeFor($owner, $repo, $branch, $forceRefresh)
                : ['ok' => false, 'map' => [], 'reason' => null, 'message' => ''];
            foreach ($group as $doc) {
                $pre = $this->preChecks($doc);
                $out[$doc->id] = $pre ?? $this->compareWithTree($doc, $tree);
            }
        }
        return $out;
    }

    /** Pré-checks por-doc que dispensam o Git; null = precisa comparar com a árvore. */
    private function preChecks(SourceDoc $doc): ?array
    {
        $repo = $doc->relationLoaded('sourceRepo') ? $doc->sourceRepo : $doc->sourceRepo()->first();
        if ($repo && ! $repo->active) {
            return $this->result($doc, self::STATUS_UNVERIFIED, self::R_REPOSITORY_INACTIVE, null, 'Repositório desativado no Minutor.');
        }
        if (empty($this->documentedSha($doc))) {
            return $this->result($doc, self::STATUS_UNVERIFIED, self::R_MISSING_DOCUMENTED, null, 'Versão vigente sem source_blob_sha documentado.');
        }
        return null;
    }

    private function compareWithTree(SourceDoc $doc, array $tree): array
    {
        if (! $tree['ok']) {
            return $this->result($doc, self::STATUS_UNVERIFIED, $tree['reason'], null, $tree['message']);
        }
        $documented = $this->documentedSha($doc);
        $current = $tree['map'][$doc->path] ?? null;
        if ($current === null) {
            return $this->result($doc, self::STATUS_UNVERIFIED, self::R_SOURCE_NOT_FOUND, null, 'Arquivo não está na árvore atual do repositório (removido/movido).');
        }
        if ($documented === $current) {
            return $this->result($doc, self::STATUS_UPDATED, null, $current, 'Documentação corresponde ao arquivo atual (blob idêntico).');
        }
        return $this->result($doc, self::STATUS_OUTDATED, null, $current, 'O conteúdo do arquivo mudou desde a versão documentada (blob difere).');
    }

    private function documentedSha(SourceDoc $doc): ?string
    {
        $ver = $doc->relationLoaded('currentVersion') ? $doc->currentVersion : $doc->currentVersion()->first();
        return $ver?->source_blob_sha;
    }

    private function result(SourceDoc $doc, string $status, ?string $reason, ?string $current, string $message): array
    {
        $ver = $doc->relationLoaded('currentVersion') ? $doc->currentVersion : $doc->currentVersion()->first();
        return [
            'status'              => $status,
            'documented_blob_sha' => $ver?->source_blob_sha,
            'current_blob_sha'    => $current,
            'source_commit_sha'   => $ver?->source_commit_sha,
            'checked_at'          => now()->toIso8601String(),
            'reason'              => $reason,
            'message'             => $message,
        ];
    }

    /**
     * Árvore (path→blob_sha) do HEAD do branch, cacheada. forceRefresh ignora o cache.
     * SÓ cacheia sucesso. Falha técnica → ['ok'=>false, reason machine-readable] (nunca lança).
     * @return array{ok:bool,map:array<string,string>,reason:?string,message:string}
     */
    private function treeFor(string $owner, string $repo, string $branch, bool $forceRefresh): array
    {
        $key = "srcdoc:tree:{$owner}/{$repo}@{$branch}";
        if ($forceRefresh) {
            Cache::forget($key);
        }
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return ['ok' => true, 'map' => $cached, 'reason' => null, 'message' => 'Árvore em cache.'];
        }
        try {
            $map = $this->auth->treeBlobShas($owner, $repo, $branch);
            Cache::put($key, $map, $this->cacheTtl());
            return ['ok' => true, 'map' => $map, 'reason' => null, 'message' => 'Árvore resolvida do GitHub.'];
        } catch (SourceIntegrationException $e) {
            return ['ok' => false, 'map' => [], 'reason' => $this->reasonFor($e->errorCode), 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'map' => [], 'reason' => self::R_RESOLUTION_ERROR, 'message' => $e->getMessage()];
        }
    }

    /** Mapeia o código técnico da integração para o reason machine-readable do resolver. */
    private function reasonFor(string $code): string
    {
        return match ($code) {
            'TIMEOUT'                                        => self::R_TIMEOUT,
            'AUTHENTICATION_ERROR'                           => self::R_AUTHENTICATION_ERROR,
            'REPO_NOT_FOUND', 'REPO_NOT_AUTHORIZED',
            'BRANCH_NOT_FOUND'                               => self::R_REPOSITORY_NOT_FOUND,
            'GITHUB_UNAVAILABLE', 'NOT_CONFIGURED',
            'APP_NOT_CONFIGURED', 'APP_NOT_INSTALLED'        => self::R_GITHUB_UNAVAILABLE,
            default                                          => self::R_RESOLUTION_ERROR,
        };
    }
}
