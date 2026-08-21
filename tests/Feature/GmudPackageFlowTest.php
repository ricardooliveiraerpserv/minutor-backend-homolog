<?php

namespace Tests\Feature;

use App\Attachments\AttachmentService;
use App\Attachments\Storage\StorageProvider;
use App\Jobs\GmudExtractAnalyzeJob;
use App\Models\Attachment;
use App\Models\ClientSourceRepo;
use App\Models\Customer;
use App\Models\GmudPackage;
use App\Models\GmudPackageFile;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketEvent;
use App\Models\SourceDoc;
use App\Models\User;
use App\SourceCode\Gmud\GmudMatchingService;
use App\SourceCode\Gmud\GmudPackageService;
use App\SourceCode\Gmud\GmudZipExtractor;
use App\SourceCode\GithubAppAuth;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GMUD G0-G2 — fluxo governado. Prova a GARANTIA CENTRAL desta entrega: nenhum upload/matching gera
 * commit no Git (GithubAppAuth::commitFiles NUNCA é chamado). Cobre também o matching determinístico
 * (new/existing/identical/ambiguous), o link de Acervo, o escopo/IDOR do endpoint e o desacoplamento
 * do antigo auto-commit da solução GMUD.
 */
class GmudPackageFlowTest extends TestCase
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
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function admin(): User { return User::factory()->create(['type' => 'admin']); }

    private function customerWithTicket(): array
    {
        $customer = Customer::factory()->create();
        $ticket = HelpDeskTicket::create(['subject' => 'GMUD teste', 'customer_id' => $customer->id]);
        return [$customer, $ticket];
    }

    private function repo(Customer $c, string $owner = 'erpserv-clientes', string $repo = 'demo', string $branch = 'main'): ClientSourceRepo
    {
        return ClientSourceRepo::create([
            'customer_id' => $c->id, 'owner' => $owner, 'repository' => $repo, 'branch' => $branch,
            'tipo' => 'protheus', 'active' => true, 'needs_review' => false,
        ]);
    }

    /** GithubAppAuth falso: árvore controlada + espião que registra QUALQUER tentativa de commit. */
    private function fakeAuth(array $tree): GithubAppAuth
    {
        return new class($tree) extends GithubAppAuth {
            public bool $committed = false;
            public function __construct(private array $tree) { parent::__construct(); }
            public function isConfigured(): bool { return true; }
            public function treeBlobShas(string $o, string $r, string $ref): array { return $this->tree; }
            public function commitFiles(string $owner, string $repo, string $branch, array $files, string $message, ?array &$blobShas = null): string
            {
                $this->committed = true;
                return 'SHOULD_NEVER_HAPPEN';
            }
        };
    }

    private function zipBytes(array $entries): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'flowzip') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) { $zip->addFromString($name, $content); }
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    private function upload(array $entries): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'flowup') . '.zip';
        file_put_contents($tmp, $this->zipBytes($entries));
        return new UploadedFile($tmp, 'pacote.zip', 'application/zip', null, true);
    }

    private function packageWithFiles(HelpDeskTicket $ticket, int $customerId, array $files): GmudPackage
    {
        $pkg = GmudPackage::create([
            'ticket_id' => $ticket->id, 'customer_id' => $customerId,
            'original_name' => 'p.zip', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64),
            'status' => GmudPackage::STATUS_ANALYZING, 'received_at' => now(),
        ]);
        foreach ($files as $f) {
            GmudPackageFile::create(array_merge(['gmud_package_id' => $pkg->id, 'is_source' => true, 'size_bytes' => 1], $f));
        }
        return $pkg;
    }

    // ── matching determinístico ─────────────────────────────────────────────

    public function test_matching_classifies_new_existing_identical_ambiguous(): void
    {
        [$customer, $ticket] = $this->customerWithTicket();
        $this->repo($customer);
        // Acervo: EXIST.prw é fonte documentado (habilita "Abrir no Acervo").
        SourceDoc::create(['owner' => 'erpserv-clientes', 'repository' => 'demo', 'branch' => 'main',
            'path' => 'src/EXIST.prw', 'filename' => 'EXIST.prw', 'analysis_status' => 'completed']);

        $tree = [
            'src/EXIST.prw'  => 'blob_exist_git',
            'src/IDENT.prw'  => 'blob_ident_shared',
            'src/DUP.prw'    => 'blob_dup_1',
            'mod/DUP.prw'    => 'blob_dup_2',
        ];
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth($tree));

        $pkg = $this->packageWithFiles($ticket, $customer->id, [
            ['filename' => 'NEW.prw',   'path_in_zip' => 'NEW.prw',   'extension' => 'prw', 'git_blob_sha' => 'blob_new'],
            ['filename' => 'IDENT.prw', 'path_in_zip' => 'IDENT.prw', 'extension' => 'prw', 'git_blob_sha' => 'blob_ident_shared'],
            ['filename' => 'EXIST.prw', 'path_in_zip' => 'EXIST.prw', 'extension' => 'prw', 'git_blob_sha' => 'blob_exist_LOCAL_diff'],
            ['filename' => 'DUP.prw',   'path_in_zip' => 'DUP.prw',   'extension' => 'prw', 'git_blob_sha' => 'blob_dup_local'],
        ]);

        app(GmudMatchingService::class)->matchPackage($pkg);
        $byName = $pkg->files()->get()->keyBy('filename');

        $this->assertSame(GmudPackageFile::MATCH_NEW, $byName['NEW.prw']->match_status);
        $this->assertSame(GmudPackageFile::MATCH_IDENTICAL, $byName['IDENT.prw']->match_status);

        $exist = $byName['EXIST.prw'];
        $this->assertSame(GmudPackageFile::MATCH_EXISTING, $exist->match_status);
        $this->assertSame('src/EXIST.prw', $exist->matched_git_path);
        $this->assertNotNull($exist->matched_source_doc_id); // "Abrir no Acervo" habilitado

        $dup = $byName['DUP.prw'];
        $this->assertSame(GmudPackageFile::MATCH_AMBIGUOUS, $dup->match_status);
        $this->assertCount(2, $dup->match_candidates);
    }

    public function test_case_mismatch_is_ambiguous(): void
    {
        [$customer, $ticket] = $this->customerWithTicket();
        $this->repo($customer);
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth(['src/File.prw' => 'blobX']));

        $pkg = $this->packageWithFiles($ticket, $customer->id, [
            ['filename' => 'FILE.PRW', 'path_in_zip' => 'FILE.PRW', 'extension' => 'prw', 'git_blob_sha' => 'blobY'],
        ]);
        app(GmudMatchingService::class)->matchPackage($pkg);

        $f = $pkg->files()->first();
        $this->assertSame(GmudPackageFile::MATCH_AMBIGUOUS, $f->match_status);
        $this->assertSame('case_mismatch', $f->match_evidence['reason']);
    }

    public function test_no_active_repo_leaves_status_null(): void
    {
        [$customer, $ticket] = $this->customerWithTicket(); // sem repo ativo
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth([]));
        $pkg = $this->packageWithFiles($ticket, $customer->id, [
            ['filename' => 'X.prw', 'path_in_zip' => 'X.prw', 'extension' => 'prw', 'git_blob_sha' => 'b'],
        ]);
        app(GmudMatchingService::class)->matchPackage($pkg);
        $f = $pkg->files()->first();
        $this->assertNull($f->match_status);
        $this->assertSame('no_repo', $f->match_evidence['reason']);
    }

    // ── GARANTIA CENTRAL: zero commit ────────────────────────────────────────

    public function test_full_job_extracts_and_matches_without_any_commit(): void
    {
        Queue::fake(); // o job é rodado manualmente abaixo; evita push real na fila do DB
        [$customer, $ticket] = $this->customerWithTicket();
        $this->repo($customer);

        $identContent = "User Function Ident()\nReturn";
        $extractor = app(GmudZipExtractor::class);
        $identBlob = $extractor->gitBlobSha($identContent);

        $tree = [
            'src/EXIST.prw' => 'blob_exist_git_side',
            'src/IDENT.prw' => $identBlob, // idêntico ao conteúdo do ZIP
        ];
        $fake = $this->fakeAuth($tree);
        $this->app->instance(GithubAppAuth::class, $fake);

        // Recebe via upload real (armazena o ZIP imutável) e roda o job de análise em processo.
        $pkg = app(GmudPackageService::class)->receiveFromUpload($ticket, $this->admin(), $this->upload([
            'EXIST.prw' => "alterado localmente",
            'IDENT.prw' => $identContent,
            'NOVO.prw'  => "nova rotina",
            '__MACOSX/IDENT.prw' => 'junk',
        ]));

        (new GmudExtractAnalyzeJob($pkg->id))->handle($extractor, app(GmudMatchingService::class), app(StorageProvider::class));

        $pkg->refresh();
        $this->assertSame(GmudPackage::STATUS_ANALYZED, $pkg->status);
        $this->assertFalse($fake->committed, 'NENHUM commit pode ocorrer no upload/análise (G0-G2).');

        $byName = $pkg->files()->get()->keyBy('filename');
        $this->assertCount(3, $byName); // junk do __MACOSX ignorado
        $this->assertSame(GmudPackageFile::MATCH_IDENTICAL, $byName['IDENT.prw']->match_status);
        $this->assertSame(GmudPackageFile::MATCH_EXISTING, $byName['EXIST.prw']->match_status);
        $this->assertSame(GmudPackageFile::MATCH_NEW, $byName['NOVO.prw']->match_status);

        // Evidência de auditoria com committed=false.
        $ev = HelpDeskTicketEvent::where('ticket_id', $ticket->id)->where('event_type', 'gmud_package_analyzed')->latest('id')->first();
        $this->assertNotNull($ev);
        $this->assertFalse($ev->meta['committed']);
    }

    public function test_solution_gmud_receives_package_and_never_commits(): void
    {
        Queue::fake();
        [$customer, $ticket] = $this->customerWithTicket();
        $this->repo($customer);
        $fake = $this->fakeAuth([]);
        $this->app->instance(GithubAppAuth::class, $fake);
        $admin = $this->admin();

        // Interação de solução GMUD com ZIP anexado (categoria source_code, como no fluxo real).
        $comment = $ticket->comments()->create(['author_user_id' => $admin->id, 'body' => 'solução', 'form_kind' => 'gmud', 'visibility' => 'customer']);
        app(AttachmentService::class)->store($admin, [
            'entity_type' => 'HELPDESK_TICKET_COMMENT', 'entity_id' => $comment->id,
            'category' => 'source_code', 'visibility' => 'internal', 'file' => $this->upload(['A.prw' => 'x']),
        ]);

        $packages = app(GmudPackageService::class)->receiveFromComment($ticket, $comment);

        $this->assertCount(1, $packages);
        $this->assertDatabaseHas('gmud_packages', ['ticket_id' => $ticket->id, 'status' => GmudPackage::STATUS_RECEIVED]);
        $this->assertFalse($fake->committed, 'A submissão da solução GMUD não pode commitar (auto-commit removido).');
        Queue::assertPushed(GmudExtractAnalyzeJob::class, fn ($job) => $job->connection === 'database' && $job->queue === 'source-doc');
    }

    public function test_receive_from_comment_is_idempotent(): void
    {
        Queue::fake();
        [$customer, $ticket] = $this->customerWithTicket();
        $admin = $this->admin();
        $comment = $ticket->comments()->create(['author_user_id' => $admin->id, 'body' => 's', 'form_kind' => 'gmud', 'visibility' => 'customer']);
        app(AttachmentService::class)->store($admin, [
            'entity_type' => 'HELPDESK_TICKET_COMMENT', 'entity_id' => $comment->id,
            'category' => 'source_code', 'visibility' => 'internal', 'file' => $this->upload(['A.prw' => 'x']),
        ]);
        app(GmudPackageService::class)->receiveFromComment($ticket, $comment);
        app(GmudPackageService::class)->receiveFromComment($ticket, $comment); // 2ª vez não duplica
        $this->assertSame(1, GmudPackage::where('ticket_id', $ticket->id)->count());
    }

    public function test_package_original_is_immutable_and_sha_recorded(): void
    {
        Queue::fake();
        [$customer, $ticket] = $this->customerWithTicket();
        $bytes = $this->zipBytes(['A.prw' => 'conteudo']);
        $tmp = tempnam(sys_get_temp_dir(), 'imm') . '.zip';
        file_put_contents($tmp, $bytes);
        $file = new UploadedFile($tmp, 'orig.zip', 'application/zip', null, true);

        $pkg = app(GmudPackageService::class)->receiveFromUpload($ticket, $this->admin(), $file);

        $att = Attachment::find($pkg->attachment_id);
        $this->assertNotNull($att);
        $this->assertSame(hash('sha256', $bytes), $pkg->sha256);
        $this->assertSame($att->checksum, $pkg->sha256); // sha do ZIP = checksum do anexo imutável
        $this->assertGreaterThan(0, $pkg->size_bytes);
    }

    // ── escopo / autorização (anti-IDOR) ─────────────────────────────────────

    public function test_upload_endpoint_requires_permission(): void
    {
        [$customer, $ticket] = $this->customerWithTicket();
        $plain = User::factory()->create(['type' => 'consultor']); // sem source_docs.gmud_publish
        $this->actingAs($plain, 'sanctum')
            ->post("/api/v1/help-desk/tickets/{$ticket->id}/gmud/packages", ['file' => $this->upload(['A.prw' => 'x'])])
            ->assertStatus(403);
    }

    public function test_upload_endpoint_accepts_admin_and_enqueues(): void
    {
        Queue::fake();
        [$customer, $ticket] = $this->customerWithTicket();
        $this->actingAs($this->admin(), 'sanctum')
            ->post("/api/v1/help-desk/tickets/{$ticket->id}/gmud/packages", ['file' => $this->upload(['A.prw' => 'x'])])
            ->assertStatus(201)
            ->assertJsonPath('data.committed', false);
        $this->assertSame(1, GmudPackage::where('ticket_id', $ticket->id)->count());
        Queue::assertPushed(GmudExtractAnalyzeJob::class);
    }
}
