<?php

namespace Tests\Feature;

use App\Models\ClientSourceRepo;
use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\SourceCode\Exceptions\SourceIntegrationException;
use App\SourceCode\GithubAppAuth;
use App\SourceCode\SourceDocStatusResolver;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Bloco 3 — prova que a verdade "documentação bate com o arquivo atual?" é por BLOB SHA (conteúdo),
 * eliminando o falso-positivo do commit HEAD, e que falha técnica nunca vira DESATUALIZADA.
 *
 * Sem banco: os modelos são construídos em memória (setRelation) — o resolver só LÊ
 * relações/atributos (currentVersion.source_blob_sha, sourceRepo.active, owner/repo/branch/path).
 */
class SourceDocStatusResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']); // a árvore é cacheada por repo; array isola o teste
        Cache::flush();
    }

    /** Fake do GithubAppAuth: devolve uma árvore controlada (path→blob_sha) ou lança um erro técnico. */
    private function fakeAuth(array|\Throwable $tree): GithubAppAuth
    {
        return new class($tree) extends GithubAppAuth {
            /** @var array<string,string>|\Throwable */
            private $treeData;
            public function __construct($treeData)
            {
                parent::__construct();
                $this->treeData = $treeData;
            }
            public function treeBlobShas(string $owner, string $repo, string $ref): array
            {
                if ($this->treeData instanceof \Throwable) {
                    throw $this->treeData;
                }
                return $this->treeData;
            }
        };
    }

    /** SourceDoc em memória com a versão vigente (e opcionalmente o repo) já resolvidos. */
    private function makeDoc(int $id, string $path, ?string $blobSha, ?ClientSourceRepo $repo = null): SourceDoc
    {
        $doc = new SourceDoc([
            'owner' => 'erpserv-clientes', 'repository' => 'promax', 'branch' => 'main',
            'path' => $path, 'filename' => basename($path),
        ]);
        $doc->id = $id;

        $ver = new SourceDocVersion([
            'source_commit_sha' => 'commit_' . substr(md5($path), 0, 10),
            'source_blob_sha'   => $blobSha,
            'analysis_status'   => 'completed',
        ]);
        $doc->setRelation('currentVersion', $ver);
        $doc->setRelation('sourceRepo', $repo); // null = sem repo vinculado (pula o check de ativo)
        return $doc;
    }

    /** 🔴 CRÍTICO: o Git muda SÓ o arquivo A → A DESATUALIZADA, B ATUALIZADA (1 árvore, sem N+1). */
    public function test_only_changed_file_is_outdated(): void
    {
        $docA = $this->makeDoc(1, 'src/A.prw', 'aaa111');
        $docB = $this->makeDoc(2, 'src/B.prw', 'bbb222');
        // HEAD atual: o blob de A mudou; o de B permanece idêntico.
        $resolver = new SourceDocStatusResolver($this->fakeAuth([
            'src/A.prw' => 'aaaXXX',
            'src/B.prw' => 'bbb222',
        ]));

        $res = $resolver->resolveMany([$docA, $docB]);

        $this->assertSame(SourceDocStatusResolver::STATUS_OUTDATED, $res[1]['status']);
        $this->assertSame(SourceDocStatusResolver::STATUS_UPDATED, $res[2]['status']);
        $this->assertNull($res[2]['reason']);
        $this->assertSame('bbb222', $res[2]['current_blob_sha']);
    }

    /** Novos commits no repo, mas o BLOB do arquivo permaneceu igual → ATUALIZADA. */
    public function test_same_blob_despite_new_commits_is_updated(): void
    {
        $doc = $this->makeDoc(1, 'src/X.prw', 'sha_same');
        $res = (new SourceDocStatusResolver($this->fakeAuth(['src/X.prw' => 'sha_same'])))->resolve($doc);
        $this->assertSame(SourceDocStatusResolver::STATUS_UPDATED, $res['status']);
    }

    /** Falha técnica (GitHub fora) NUNCA vira DESATUALIZADA → NAO_VALIDADO/github_unavailable. */
    public function test_technical_failure_is_unverified_not_outdated(): void
    {
        $doc = $this->makeDoc(1, 'src/X.prw', 'sha1');
        $res = (new SourceDocStatusResolver($this->fakeAuth(
            new SourceIntegrationException('GITHUB_UNAVAILABLE', 'down', 502)
        )))->resolve($doc);
        $this->assertSame(SourceDocStatusResolver::STATUS_UNVERIFIED, $res['status']);
        $this->assertSame('github_unavailable', $res['reason']);
    }

    public function test_timeout_is_unverified_with_timeout_reason(): void
    {
        $doc = $this->makeDoc(1, 'src/X.prw', 'sha1');
        $res = (new SourceDocStatusResolver($this->fakeAuth(
            new SourceIntegrationException('TIMEOUT', 'timed out', 504)
        )))->resolve($doc);
        $this->assertSame(SourceDocStatusResolver::STATUS_UNVERIFIED, $res['status']);
        $this->assertSame('timeout', $res['reason']);
    }

    /** Arquivo removido do Git (não está na árvore) → NAO_VALIDADO/source_not_found (não REMOVIDA). */
    public function test_removed_file_is_source_not_found(): void
    {
        $doc = $this->makeDoc(1, 'src/gone.prw', 'sha1');
        $res = (new SourceDocStatusResolver($this->fakeAuth(['src/other.prw' => 'zzz'])))->resolve($doc);
        $this->assertSame(SourceDocStatusResolver::STATUS_UNVERIFIED, $res['status']);
        $this->assertSame('source_not_found', $res['reason']);
    }

    /** Versão antiga sem blob documentado → NAO_VALIDADO/missing_documented_sha (não fabrica SHA). */
    public function test_missing_documented_sha(): void
    {
        $doc = $this->makeDoc(1, 'src/old.prw', null);
        $res = (new SourceDocStatusResolver($this->fakeAuth(['src/old.prw' => 'whatever'])))->resolve($doc);
        $this->assertSame(SourceDocStatusResolver::STATUS_UNVERIFIED, $res['status']);
        $this->assertSame('missing_documented_sha', $res['reason']);
    }

    /** Repo desativado no Minutor → NAO_VALIDADO/repository_inactive (nem consulta o Git). */
    public function test_inactive_repo_is_unverified(): void
    {
        $repo = new ClientSourceRepo(['owner' => 'erpserv-clientes', 'repository' => 'promax', 'branch' => 'main', 'active' => false]);
        $doc = $this->makeDoc(1, 'src/X.prw', 'sha1', $repo);
        // Se tocar no Git, o teste falha (o fake lança). Prova o curto-circuito.
        $res = (new SourceDocStatusResolver($this->fakeAuth(
            new \RuntimeException('não deveria consultar o Git para repo inativo')
        )))->resolve($doc);
        $this->assertSame(SourceDocStatusResolver::STATUS_UNVERIFIED, $res['status']);
        $this->assertSame('repository_inactive', $res['reason']);
    }
}
