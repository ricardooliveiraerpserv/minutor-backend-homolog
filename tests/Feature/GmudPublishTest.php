<?php

namespace Tests\Feature;

use App\Jobs\GmudExtractAnalyzeJob;
use App\Attachments\Storage\StorageProvider;
use App\Models\ClientSourceRepo;
use App\Models\Customer;
use App\Models\GmudPackage;
use App\Models\GmudPackageFile;
use App\Models\HelpDeskTicket;
use App\Models\User;
use App\SourceCode\Exceptions\SourceIntegrationException;
use App\SourceCode\Gmud\GmudMatchingService;
use App\SourceCode\Gmud\GmudPackageService;
use App\SourceCode\Gmud\GmudPublishException;
use App\SourceCode\Gmud\GmudPublishService;
use App\SourceCode\Gmud\GmudZipExtractor;
use App\SourceCode\GithubAppAuth;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GMUD G4/G6/G7 — PUBLICAÇÃO governada. Prova: NOVO vai p/ a pasta escolhida, EXISTENTE grava no
 * path do Git, AMBÍGUO usa a ocorrência escolhida, IDÊNTICO é pulado — tudo num ÚNICO commit
 * atômico. Colisão/ambíguo-não-resolvido/HEAD-movido bloqueiam sem publicar.
 */
class GmudPublishTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => 'minutor'] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['cache.default' => 'array']);
        Queue::fake();
    }

    private function admin(): User { return User::factory()->create(['type' => 'admin']); }

    /** GithubAppAuth falso: árvore controlada + GRAVA a chamada de commit (espião do que foi publicado). */
    private function fakeAuth(array $tree, bool $failCommit = false): GithubAppAuth
    {
        return new class($tree, $failCommit) extends GithubAppAuth {
            public ?array $lastCommit = null;
            public int $commitCount = 0;
            public function __construct(private array $t, private bool $fail) { parent::__construct(); }
            public function isConfigured(): bool { return true; }
            public function treeBlobShas(string $o, string $r, string $ref): array { return $this->t; }
            public function commitFiles(string $owner, string $repo, string $branch, array $files, string $message, ?array &$blobShas = null): string
            {
                $this->commitCount++;
                if ($this->fail) {
                    throw SourceIntegrationException::upstream(422); // HEAD moveu / conflito
                }
                $blobShas = [];
                foreach ($files as $path => $c) { $blobShas[$path] = 'blob_' . substr(md5($path), 0, 12); }
                $this->lastCommit = compact('owner', 'repo', 'branch', 'files', 'message');
                return 'commit_sha_ABC123';
            }
        };
    }

    private function upload(array $entries): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pub') . '.zip';
        $zip = new \ZipArchive(); $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $n => $c) { $zip->addFromString($n, $c); }
        $zip->close();
        return new UploadedFile($tmp, 'pacote.zip', 'application/zip', null, true);
    }

    /** Monta cliente+ticket+repo, recebe o ZIP e roda a análise (matching) — devolve o pacote pronto. */
    private function analyzedPackage(array $zipEntries, array $tree, GithubAppAuth $fake): array
    {
        $customer = Customer::factory()->create();
        $ticket = HelpDeskTicket::create(['subject' => 'GMUD pub', 'customer_id' => $customer->id]);
        ClientSourceRepo::create(['customer_id' => $customer->id, 'owner' => 'erpserv-clientes', 'repository' => 'demo',
            'branch' => 'main', 'tipo' => 'protheus', 'active' => true, 'needs_review' => false]);

        $this->app->instance(GithubAppAuth::class, $fake);
        $pkg = app(GmudPackageService::class)->receiveFromUpload($ticket, $this->admin(), $this->upload($zipEntries));
        (new GmudExtractAnalyzeJob($pkg->id))->handle(app(GmudZipExtractor::class), app(GmudMatchingService::class), app(StorageProvider::class));
        return [$pkg->fresh(), $customer, $ticket];
    }

    public function test_publish_single_atomic_commit_with_correct_destinations(): void
    {
        $ex = app(GmudZipExtractor::class);
        $igual = "User Function Igual()\nReturn";
        $tree = [
            'src/EXISTE.prw' => 'blob_git_diferente',
            'src/IGUAL.prw'  => $ex->gitBlobSha($igual),
            'src/AMBIG.prw'  => 'a1',
            'lib/AMBIG.prw'  => 'a2',
        ];
        $fake = $this->fakeAuth($tree);
        [$pkg, $customer, $ticket] = $this->analyzedPackage([
            'EXISTE.prw' => 'novo conteudo existe',
            'IGUAL.prw'  => $igual,
            'NOVO.prw'   => 'conteudo novo',
            'AMBIG.prw'  => 'conteudo ambig',
        ], $tree, $fake);

        // resolve o ambíguo para src/AMBIG.prw
        $ambigId = $pkg->files()->where('filename', 'AMBIG.prw')->value('id');

        $res = app(GmudPublishService::class)->publish($pkg, null, 'src/novos', [$ambigId => 'src/AMBIG.prw'], $this->admin());

        // 1 commit só, com os 3 destinos certos (idêntico fora)
        $this->assertSame(1, $fake->commitCount);
        $this->assertSame('commit_sha_ABC123', $res['commit_sha']);
        $committed = array_keys($fake->lastCommit['files']);
        sort($committed);
        $this->assertSame(['src/AMBIG.prw', 'src/EXISTE.prw', 'src/novos/NOVO.prw'], $committed);
        // conteúdo do NOVO veio do ZIP
        $this->assertSame('conteudo novo', $fake->lastCommit['files']['src/novos/NOVO.prw']);

        $pkg->refresh();
        $this->assertSame(GmudPackage::STATUS_PUBLISHED, $pkg->status);
        $this->assertSame('src/novos', $pkg->project_folder);
        $this->assertNotNull($pkg->source_repo_id);

        $byName = $pkg->files()->get()->keyBy('filename');
        $this->assertSame('add', $byName['NOVO.prw']->action);
        $this->assertSame('src/novos/NOVO.prw', $byName['NOVO.prw']->dest_git_path);
        $this->assertSame('modify', $byName['EXISTE.prw']->action);
        $this->assertSame('src/EXISTE.prw', $byName['EXISTE.prw']->dest_git_path);
        $this->assertSame('modify', $byName['AMBIG.prw']->action);
        $this->assertSame('src/AMBIG.prw', $byName['AMBIG.prw']->dest_git_path);
        $this->assertSame('skip', $byName['IGUAL.prw']->action);
        $this->assertNotNull($byName['NOVO.prw']->published_blob_sha);
    }

    public function test_ambiguous_unresolved_blocks_without_commit(): void
    {
        $tree = ['src/AMBIG.prw' => 'a1', 'lib/AMBIG.prw' => 'a2'];
        $fake = $this->fakeAuth($tree);
        [$pkg] = $this->analyzedPackage(['AMBIG.prw' => 'x'], $tree, $fake);

        try {
            app(GmudPublishService::class)->publish($pkg, null, 'src', [], $this->admin());
            $this->fail('deveria bloquear ambíguo não resolvido');
        } catch (GmudPublishException $e) {
            $this->assertSame(0, $fake->commitCount);
            $this->assertNotSame(GmudPackage::STATUS_PUBLISHED, $pkg->fresh()->status);
        }
    }

    public function test_destination_collision_blocks_without_commit(): void
    {
        $fake = $this->fakeAuth([]); // nenhum existente → ambos NOVOS
        [$pkg] = $this->analyzedPackage(['a/DUP.prw' => '1', 'b/DUP.prw' => '2'], [], $fake);
        $this->expectException(GmudPublishException::class);
        try {
            app(GmudPublishService::class)->publish($pkg, null, 'src', [], $this->admin());
        } finally {
            $this->assertSame(0, $fake->commitCount);
        }
    }

    public function test_git_failure_marks_publish_failed_and_publishes_nothing(): void
    {
        $tree = [];
        $fake = $this->fakeAuth($tree, failCommit: true);
        [$pkg] = $this->analyzedPackage(['NOVO.prw' => 'x'], $tree, $fake);
        try {
            app(GmudPublishService::class)->publish($pkg, null, 'src', [], $this->admin());
            $this->fail('deveria propagar falha do Git');
        } catch (SourceIntegrationException $e) {
            $this->assertSame(GmudPackage::STATUS_PUBLISH_FAILED, $pkg->fresh()->status);
        }
    }

    public function test_cannot_publish_twice(): void
    {
        $fake = $this->fakeAuth([]);
        [$pkg] = $this->analyzedPackage(['NOVO.prw' => 'x'], [], $fake);
        app(GmudPublishService::class)->publish($pkg, null, 'src', [], $this->admin());
        $this->expectException(GmudPublishException::class);
        app(GmudPublishService::class)->publish($pkg->fresh(), null, 'src', [], $this->admin());
    }

    public function test_directories_lists_repo_folders(): void
    {
        $fake = $this->fakeAuth(['src/A.prw' => '1', 'src/sub/B.prw' => '2', 'lib/C.prw' => '3']);
        [$pkg] = $this->analyzedPackage(['NOVO.prw' => 'x'], ['src/A.prw' => '1', 'src/sub/B.prw' => '2', 'lib/C.prw' => '3'], $fake);
        $dirs = app(GmudPublishService::class)->directories($pkg, null);
        $this->assertSame(['lib', 'src', 'src/sub'], $dirs['dirs']);
    }

    public function test_publish_endpoint_requires_permission(): void
    {
        $fake = $this->fakeAuth([]);
        [$pkg] = $this->analyzedPackage(['NOVO.prw' => 'x'], [], $fake);
        $plain = User::factory()->create(['type' => 'consultor']);
        $this->actingAs($plain, 'sanctum')
            ->postJson("/api/v1/gmud/packages/{$pkg->id}/publish", ['dest_folder' => 'src'])
            ->assertStatus(403);
    }
}
