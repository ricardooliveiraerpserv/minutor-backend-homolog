<?php

namespace App\SourceCode;

use App\SourceCode\Exceptions\SourceIntegrationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Autenticação da integração de código-fonte via GitHub App (SOMENTE-LEITURA).
 * Responsável ÚNICO por: JWT da App (RS256), resolução automática de installation por owner,
 * emissão/renovação/cache do installation token (por instalação) com lock anti-stampede.
 * NUNCA loga/retorna/persiste JWT, private key ou installation token. Sem fallback.
 */
class GithubAppAuth
{
    private const INSTALL_TTL   = 21600;  // 6h — instalações mudam raramente
    private const TOKEN_MARGIN  = 300;    // 5 min de margem sobre o expires_at do GitHub

    private string $api;
    private int $timeout;
    private ?string $jwtCache = null;
    private int $jwtExp = 0;

    public function __construct()
    {
        $this->api = rtrim((string) config('services.github_source.api', 'https://api.github.com'), '/');
        $this->timeout = (int) config('services.github_source.timeout', 20);
    }

    public function isConfigured(): bool
    {
        return !empty(config('services.github_source.app_id')) && $this->privateKeyPem() !== null;
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw SourceIntegrationException::appNotConfigured();
        }
    }

    /** Installation token VÁLIDO para o owner (cacheado por installation_id, com lock). */
    public function installationToken(string $owner): string
    {
        $this->assertConfigured();
        $installId = $this->installationId($owner);
        $key = "github_app_token:{$installId}";

        if ($cached = Cache::get($key)) {
            return $cached;
        }
        $mint = function () use ($key, $installId): string {
            if ($again = Cache::get($key)) {
                return $again;   // double-check dentro do lock
            }
            $data = $this->mintToken($installId);
            $ttl = $this->tokenTtl($data['expires_at'] ?? null);
            if ($ttl > 0) {
                Cache::put($key, $data['token'], $ttl);
            }
            return $data['token'];
        };
        try {
            return Cache::lock("github_app_token_lock:{$installId}", 10)->block(5, $mint);
        } catch (LockTimeoutException) {
            return $mint();   // fail-safe: sem lock, ainda emite (sem travar)
        }
    }

    /** Resolve o installation_id da App para o owner (org ou usuário). 404 nos dois = não instalada. */
    public function installationId(string $owner): int
    {
        $this->assertConfigured();
        return (int) Cache::remember('github_app_installation:' . strtolower($owner), self::INSTALL_TTL, function () use ($owner) {
            foreach (["/orgs/{$owner}/installation", "/users/{$owner}/installation"] as $path) {
                $res = $this->appRequest()->get("{$this->api}{$path}");
                if ($res->successful()) {
                    return (int) $res->json('id');
                }
                if ($res->status() !== 404) {
                    $this->assertUpstream($res);
                }
            }
            throw SourceIntegrationException::appNotInstalled($owner);
        });
    }

    /** Repos que a instalação do owner enxerga (p/ o seletor). [{name, default_branch, private}]. */
    public function listRepos(string $owner): array
    {
        $token = $this->installationToken($owner);   // pode lançar APP_NOT_INSTALLED/NOT_CONFIGURED
        $out = [];
        for ($page = 1; $page <= 5; $page++) {   // teto 500 repos — suficiente p/ um cliente
            $res = Http::withToken($token)->timeout($this->timeout)->withHeaders($this->baseHeaders())
                ->get("{$this->api}/installation/repositories", ['per_page' => 100, 'page' => $page]);
            if (!$res->successful()) {
                break;
            }
            $repos = $res->json('repositories', []);
            foreach ($repos as $r) {
                $out[] = [
                    'name'           => $r['name'] ?? null,
                    'default_branch' => $r['default_branch'] ?? null,
                    'private'        => (bool) ($r['private'] ?? true),
                ];
            }
            if (count($repos) < 100) {
                break;
            }
        }
        usort($out, fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
        return $out;
    }

    /**
     * Cria um repositório PRIVADO na organização (ESCRITA — exige a App com
     * "Administration: Read and write"). auto_init=true → já nasce com branch default.
     * Idempotente: se o nome já existir e a instalação enxergar, devolve o existente.
     * @return array{name:string, default_branch:string, full_name:string, created:bool}
     */
    public function createOrgRepo(string $owner, string $name, string $description = ''): array
    {
        $token = $this->installationToken($owner);   // App instalada no owner?
        $res = Http::withToken($token)->timeout($this->timeout)->withHeaders($this->baseHeaders())
            ->post("{$this->api}/orgs/{$owner}/repos", [
                'name'         => $name,
                'private'      => true,
                'auto_init'    => true,
                'description'  => $description !== '' ? mb_substr($description, 0, 300) : null,
                'has_issues'   => false,
                'has_wiki'     => false,
                'has_projects' => false,
            ]);
        if ($res->successful()) {
            return [
                'name'           => (string) $res->json('name'),
                'default_branch' => (string) ($res->json('default_branch') ?: 'main'),
                'full_name'      => (string) $res->json('full_name'),
                'created'        => true,
            ];
        }
        // Nome já existe → reaproveita o repo (idempotência).
        if ($res->status() === 422 && $this->nameAlreadyExists($res)) {
            $existing = Http::withToken($token)->timeout($this->timeout)->withHeaders($this->baseHeaders())
                ->get("{$this->api}/repos/{$owner}/{$name}");
            if ($existing->successful()) {
                return [
                    'name'           => (string) $existing->json('name'),
                    'default_branch' => (string) ($existing->json('default_branch') ?: 'main'),
                    'full_name'      => (string) $existing->json('full_name'),
                    'created'        => false,
                ];
            }
            throw SourceIntegrationException::repoNameTaken($owner, $name);
        }
        // 403 sem rate-limit = App sem permissão de escrita (Administration).
        if ($res->status() === 403 && (string) $res->header('X-RateLimit-Remaining') !== '0') {
            throw SourceIntegrationException::writeNotPermitted($owner);
        }
        $this->assertUpstream($res);   // 429/rate-limit
        throw SourceIntegrationException::upstream($res->status());
    }

    /**
     * Renomeia um repositório (ESCRITA — Administration:RW). O GitHub mantém redirect do nome antigo.
     * @return array{name:string, default_branch:string, full_name:string}
     */
    public function renameRepo(string $owner, string $from, string $to): array
    {
        $token = $this->installationToken($owner);
        $res = Http::withToken($token)->timeout($this->timeout)->withHeaders($this->baseHeaders())
            ->patch("{$this->api}/repos/{$owner}/{$from}", ['name' => $to]);
        if ($res->successful()) {
            return [
                'name'           => (string) $res->json('name'),
                'default_branch' => (string) ($res->json('default_branch') ?: 'main'),
                'full_name'      => (string) $res->json('full_name'),
            ];
        }
        if ($res->status() === 422 && $this->nameAlreadyExists($res)) {
            throw SourceIntegrationException::repoNameTaken($owner, $to);
        }
        if ($res->status() === 403 && (string) $res->header('X-RateLimit-Remaining') !== '0') {
            throw SourceIntegrationException::writeNotPermitted($owner);
        }
        if ($res->status() === 404) {
            throw SourceIntegrationException::repoNotFound("{$owner}/{$from}");
        }
        $this->assertUpstream($res);
        throw SourceIntegrationException::upstream($res->status());
    }

    /**
     * Commit ATÔMICO de vários arquivos numa branch (Git Data API — exige Contents:RW).
     * $files: [caminho_relativo => conteúdo_em_bytes]. base64 preserva bytes exatos (encoding
     * legado dos fontes AdvPL). Retorna o SHA do commit criado.
     * @param array<string,string> $files
     */
    public function commitFiles(string $owner, string $repo, string $branch, array $files, string $message, ?array &$blobShas = null): string
    {
        $blobShas = [];
        if (empty($files)) {
            throw SourceIntegrationException::upstream(400);
        }
        $token = $this->installationToken($owner);
        $base = "{$this->api}/repos/{$owner}/{$repo}";
        $http = fn () => Http::withToken($token)->timeout($this->timeout)->withHeaders($this->baseHeaders());

        // 1) HEAD da branch → commit atual
        $refRes = $http()->get("{$base}/git/ref/heads/{$branch}");
        if ($refRes->status() === 404) {
            throw SourceIntegrationException::branchNotFound($branch);
        }
        if (!$refRes->successful()) {
            $this->assertUpstream($refRes);
            throw SourceIntegrationException::upstream($refRes->status());
        }
        $headSha = (string) $refRes->json('object.sha');

        // 2) commit atual → tree base
        $commitRes = $http()->get("{$base}/git/commits/{$headSha}");
        if (!$commitRes->successful()) {
            throw SourceIntegrationException::upstream($commitRes->status());
        }
        $baseTree = (string) $commitRes->json('tree.sha');

        // 3) blobs (base64) → entradas da tree
        $tree = [];
        foreach ($files as $path => $content) {
            $blobRes = $http()->post("{$base}/git/blobs", ['content' => base64_encode($content), 'encoding' => 'base64']);
            $this->assertWrite($blobRes, $owner);
            $norm = ltrim(str_replace('\\', '/', $path), '/');
            $blobSha = (string) $blobRes->json('sha');   // git blob SHA autoritativo do GitHub
            $blobShas[$norm] = $blobSha;                  // out: path (normalizado) → blob SHA (Bloco 3)
            $tree[] = ['path' => $norm, 'mode' => '100644', 'type' => 'blob', 'sha' => $blobSha];
        }

        // 4) nova tree (sobre a base → preserva o resto do repo)
        $treeRes = $http()->post("{$base}/git/trees", ['base_tree' => $baseTree, 'tree' => $tree]);
        $this->assertWrite($treeRes, $owner);
        $newTree = (string) $treeRes->json('sha');

        // 5) commit
        $cRes = $http()->post("{$base}/git/commits", ['message' => $message, 'tree' => $newTree, 'parents' => [$headSha]]);
        $this->assertWrite($cRes, $owner);
        $newCommit = (string) $cRes->json('sha');

        // 6) atualiza a ref da branch
        $upRes = $http()->patch("{$base}/git/refs/heads/{$branch}", ['sha' => $newCommit, 'force' => false]);
        $this->assertWrite($upRes, $owner);

        return $newCommit;
    }

    /** Erros de chamada de ESCRITA: 403 sem rate-limit = App sem Contents:RW. */
    private function assertWrite(Response $res, string $owner): void
    {
        if ($res->successful()) {
            return;
        }
        if ($res->status() === 403 && (string) $res->header('X-RateLimit-Remaining') !== '0') {
            throw SourceIntegrationException::contentsWriteNotPermitted($owner);
        }
        $this->assertUpstream($res);
        throw SourceIntegrationException::upstream($res->status());
    }

    /** Existe repo com esse nome no owner? (GET read-only; 200=sim, 404=não). */
    public function repoExists(string $owner, string $name): bool
    {
        try {
            $token = $this->installationToken($owner);
            $res = Http::withToken($token)->timeout($this->timeout)->withHeaders($this->baseHeaders())
                ->get("{$this->api}/repos/{$owner}/{$name}");
            return $res->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** SHA do HEAD da branch (read-only), ou null. Usado como parent do diff antes de commitar. */
    public function getBranchHeadSha(string $owner, string $repo, string $branch): ?string
    {
        try {
            $token = $this->installationToken($owner);
            $res = Http::withToken($token)->timeout($this->timeout)->withHeaders($this->baseHeaders())
                ->get("{$this->api}/repos/{$owner}/{$repo}/git/ref/heads/{$branch}");
            return $res->successful() ? ($res->json('object.sha') ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Conteúdo de UM arquivo na branch/ref (read-only), ou null se não existir (fonte novo). */
    public function getFileContent(string $owner, string $repo, string $branch, string $path): ?string
    {
        return $this->getFileWithSha($owner, $repo, $branch, $path)['content'] ?? null;
    }

    /**
     * Bloco 3 — conteúdo + BLOB SHA autoritativo do GitHub (Contents API: `sha` = git blob SHA do
     * arquivo naquele ref). Devolve ['content' => string, 'blob_sha' => string] ou null se não existir.
     * NÃO descartar o sha: é a identidade técnica do conteúdo (ver source_doc_versions.source_blob_sha).
     */
    public function getFileWithSha(string $owner, string $repo, string $ref, string $path): ?array
    {
        try {
            $token = $this->installationToken($owner);
            $res = Http::withToken($token)->timeout($this->timeout)->withHeaders($this->baseHeaders())
                ->get("{$this->api}/repos/{$owner}/{$repo}/contents/" . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/')))), ['ref' => $ref]);
            if (!$res->successful()) {
                return null;
            }
            $content = (string) $res->json('content', '');
            $encoding = (string) $res->json('encoding', 'base64');
            if ($encoding === 'base64') {
                $decoded = base64_decode(str_replace(["\n", "\r"], '', $content), true);
                if ($decoded === false) {
                    return null;
                }
                $content = $decoded;
            }
            return ['content' => $content, 'blob_sha' => (string) $res->json('sha', '') ?: null];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Bloco 3 (anti-N+1) — árvore RECURSIVA do ref: mapa [path => blob_sha] de TODOS os arquivos
     * (type=blob) do repo naquele ref (HEAD do branch, ou um commit/tree sha). UMA chamada resolve
     * o repo inteiro; o resolver compara localmente contra o source_blob_sha documentado.
     *
     * Lança SourceIntegrationException classificada (o SourceDocStatusResolver mapeia código→reason;
     * falha técnica NUNCA vira DESATUALIZADA). Timeout de conexão → 'GITHUB_UNAVAILABLE'.
     *
     * @return array<string,string> path (relativo à raiz do repo) → git blob SHA
     */
    public function treeBlobShas(string $owner, string $repo, string $ref): array
    {
        try {
            $token = $this->installationToken($owner);
            $res = Http::withToken($token)->timeout($this->timeout)->withHeaders($this->baseHeaders())
                ->get("{$this->api}/repos/{$owner}/{$repo}/git/trees/" . rawurlencode($ref), ['recursive' => '1']);
        } catch (\Throwable $e) {
            // Falha de conexão/timeout → indisponibilidade técnica (jamais "desatualizada").
            $isTimeout = stripos($e->getMessage(), 'timed out') !== false
                || stripos($e->getMessage(), 'timeout') !== false;
            throw new SourceIntegrationException(
                $isTimeout ? 'TIMEOUT' : 'GITHUB_UNAVAILABLE',
                'GitHub indisponível ao ler a árvore: ' . $e->getMessage(),
                $isTimeout ? 504 : 502
            );
        }

        if ($res->status() === 401) {
            throw new SourceIntegrationException('AUTHENTICATION_ERROR', 'Falha de autenticação ao ler a árvore do repositório.', 401);
        }
        if ($res->status() === 404) {
            throw SourceIntegrationException::repoNotFound("{$owner}/{$repo}@{$ref}");
        }
        if ($res->status() === 409) {
            // Repositório vazio (sem commits) → árvore vazia (não é erro técnico).
            return [];
        }
        if (!$res->successful()) {
            throw new SourceIntegrationException('GITHUB_UNAVAILABLE', "GitHub devolveu {$res->status()} ao ler a árvore.", 502);
        }

        $map = [];
        foreach ((array) $res->json('tree', []) as $node) {
            if (($node['type'] ?? null) === 'blob' && isset($node['path'], $node['sha'])) {
                $map[(string) $node['path']] = (string) $node['sha'];
            }
        }
        return $map;
    }

    /** Metadados do repo (size em KB, default_branch) ou null se não existir. */
    public function repoMeta(string $owner, string $name): ?array
    {
        try {
            $token = $this->installationToken($owner);
            $res = Http::withToken($token)->timeout($this->timeout)->withHeaders($this->baseHeaders())
                ->get("{$this->api}/repos/{$owner}/{$name}");
            if (!$res->successful()) {
                return null;
            }
            return [
                'size'           => (int) $res->json('size', 0),
                'default_branch' => (string) ($res->json('default_branch') ?: 'main'),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Apaga um repositório (ESCRITA — Administration:RW). 204=ok, 404=já não existe. IRREVERSÍVEL. */
    public function deleteRepo(string $owner, string $name): void
    {
        $token = $this->installationToken($owner);
        $res = Http::withToken($token)->timeout($this->timeout)->withHeaders($this->baseHeaders())
            ->delete("{$this->api}/repos/{$owner}/{$name}");
        if ($res->successful() || $res->status() === 404) {
            return;
        }
        if ($res->status() === 403 && (string) $res->header('X-RateLimit-Remaining') !== '0') {
            throw SourceIntegrationException::writeNotPermitted($owner);
        }
        $this->assertUpstream($res);
        throw SourceIntegrationException::upstream($res->status());
    }

    private function nameAlreadyExists(Response $res): bool
    {
        foreach ((array) $res->json('errors', []) as $e) {
            if (str_contains((string) ($e['message'] ?? ''), 'already exists')) {
                return true;
            }
        }
        return str_contains((string) $res->json('message', ''), 'already exists');
    }

    /** Classificação (item 14): a instalação do owner tem acesso a ESTE repo? (best-effort) */
    public function installationHasRepo(string $owner, string $repo): bool
    {
        try {
            $token = $this->installationToken($owner);
            $res = Http::withToken($token)->timeout($this->timeout)
                ->withHeaders($this->baseHeaders())
                ->get("{$this->api}/installation/repositories", ['per_page' => 100]);
            if (!$res->successful()) {
                return false;
            }
            foreach ($res->json('repositories', []) as $r) {
                if (strcasecmp((string) ($r['name'] ?? ''), $repo) === 0) {
                    return true;
                }
            }
            // repository_selection=all lista todos; se veio página cheia (100) pode haver mais — assume acessível.
            return count($res->json('repositories', [])) >= 100;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── internos ─────────────────────────────────────────────────────────

    private function mintToken(int $installId): array
    {
        $t0 = microtime(true);
        $res = $this->appRequest()->post("{$this->api}/app/installations/{$installId}/access_tokens");
        $this->assertUpstream($res);
        Log::debug('github_app.token_minted', [
            'installation_id' => $installId,
            'status'          => $res->status(),
            'ms'              => (int) ((microtime(true) - $t0) * 1000),
        ]);
        return ['token' => $res->json('token'), 'expires_at' => $res->json('expires_at')];
    }

    /** TTL do cache do token = (expires_at − agora) − margem; 0 = não cacheia. */
    private function tokenTtl(?string $expiresAt): int
    {
        if (!$expiresAt) {
            return 0;
        }
        $secs = Carbon::parse($expiresAt)->getTimestamp() - Carbon::now()->getTimestamp() - self::TOKEN_MARGIN;
        return max(0, $secs);
    }

    private function appRequest()
    {
        return Http::withToken($this->jwt())->timeout($this->timeout)->withHeaders($this->baseHeaders());
    }

    private function baseHeaders(): array
    {
        return [
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'Minutor-SourceCode',
        ];
    }

    /** JWT RS256 da App (nativo, sem dependência). Memoizado ~8 min (<10 min do limite). */
    private function jwt(): string
    {
        $now = Carbon::now()->getTimestamp();
        if ($this->jwtCache !== null && $this->jwtExp > $now + 30) {
            return $this->jwtCache;
        }
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $exp = $now + 540;
        $payload = ['iat' => $now - 60, 'exp' => $exp, 'iss' => (string) config('services.github_source.app_id')];
        $seg = $this->b64url(json_encode($header)) . '.' . $this->b64url(json_encode($payload));

        $key = openssl_pkey_get_private($this->privateKeyPem());
        if ($key === false) {
            throw SourceIntegrationException::appNotConfigured();   // private key inválida = misconfig
        }
        $sig = '';
        openssl_sign($seg, $sig, $key, OPENSSL_ALGO_SHA256);

        $this->jwtCache = $seg . '.' . $this->b64url($sig);
        $this->jwtExp = $exp;
        return $this->jwtCache;
    }

    /** PEM da private key: prefere base64 (linha única no env do Render), senão o PEM cru. */
    private function privateKeyPem(): ?string
    {
        $b64 = config('services.github_source.app_private_key_base64');
        if (!empty($b64)) {
            $decoded = base64_decode((string) $b64, true);
            if ($decoded !== false && str_contains($decoded, 'PRIVATE KEY')) {
                return $decoded;
            }
        }
        $raw = config('services.github_source.app_private_key');
        return !empty($raw) ? (string) $raw : null;
    }

    private function assertUpstream(Response $res): void
    {
        if ($res->successful()) {
            return;
        }
        if ($res->status() === 429 || ($res->status() === 403 && (string) $res->header('X-RateLimit-Remaining') === '0')) {
            throw SourceIntegrationException::rateLimited();
        }
        throw SourceIntegrationException::upstream($res->status());
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
