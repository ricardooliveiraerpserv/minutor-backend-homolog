<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SourceDoc;
use App\Models\SourceDocActionLog;
use App\Models\SourceDocQualityAnalysis;
use App\Models\SourceDocQualityFinding;
use App\Models\SourceDocVersion;
use App\Models\User;
use App\SourceCode\GithubAppAuth;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Central de Fontes — Análise de Qualidade (CodeAnalysis), Gate A2 (backend Minutor).
 * Escopo/permissão/versionamento/concorrência/resiliência/auditoria + integração server-to-server.
 */
class SourceDocQualityTest extends TestCase
{
    use DatabaseTransactions;

    private const CA_URL = 'http://ca.test';
    private const CA_TOKEN = 'supersecrettoken';

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config([
            'cache.default' => 'array',
            'services.codeanalysis.enabled' => true,
            'services.codeanalysis.base_url' => self::CA_URL,
            'services.codeanalysis.token' => self::CA_TOKEN,
            'services.codeanalysis.timeout' => 30,
        ]);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function admin(): User { return User::factory()->create(['type' => 'admin']); }

    private function makeDoc(string $blob = 'blobA', ?int $customerId = null): SourceDoc
    {
        $doc = SourceDoc::create([
            'owner' => 'erpserv-clientes', 'repository' => 'concreserv', 'branch' => 'main',
            'path' => 'src/' . uniqid() . '.prw', 'filename' => 'MATA010.PRW', 'lang' => 'advpl',
            'tipo' => 'protheus', 'analysis_status' => 'completed', 'customer_id' => $customerId,
        ]);
        $ver = SourceDocVersion::create([
            'source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . uniqid(), 'source_blob_sha' => $blob,
            'analysis_status' => 'completed',
        ]);
        $doc->forceFill(['current_version_id' => $ver->id])->save();
        return $doc->refresh();
    }

    /** Fake do GitHub: content + blob controláveis; tree map keyed pelo path do doc. */
    private function bindAuth(SourceDoc $doc, string $treeBlob, string $content = 'code', ?string $fetchBlob = null): void
    {
        $auth = new class($treeBlob, $doc->path, $content, $fetchBlob ?? $treeBlob) extends GithubAppAuth {
            public function __construct(private string $t, private string $p, private string $c, private string $b) { parent::__construct(); }
            public function getFileWithSha(string $o, string $r, string $ref, string $path): ?array { return ['content' => $this->c, 'blob_sha' => $this->b]; }
            public function getFileContent(string $o, string $r, string $ref, string $path): ?string { return $this->c; }
            public function treeBlobShas(string $o, string $r, string $ref): array { return [$this->p => $this->t]; }
            public function getBranchHeadSha(string $o, string $r, string $b): ?string { return 'head'; }
        };
        $this->app->instance(GithubAppAuth::class, $auth);
    }

    /** Stub da API do CodeAnalysis: POST → $post, GET job → $get. */
    private function fakeCa(array $post = ['job_id' => 'job123', 'status' => 'queued'], int $postStatus = 202, ?array $get = null, int $getStatus = 200): void
    {
        Http::fake(function ($request) use ($post, $postStatus, $get, $getStatus) {
            if ($request->method() === 'GET') {
                return Http::response($get ?? ['job_id' => 'job123', 'status' => 'running'], $getStatus);
            }
            return Http::response($post, $postStatus);
        });
    }

    // ── permissões (1,2,3,4) ────────────────────────────────────────────────

    public function test_view_authorized(): void
    {
        $doc = $this->makeDoc();
        $this->bindAuth($doc, 'blobA');
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")
            ->assertOk()->assertJsonPath('data.state', 'never_analyzed');
    }

    public function test_view_without_permission_forbidden(): void
    {
        $doc = $this->makeDoc();
        $u = User::factory()->create(['type' => 'consultor']); // sem source_docs.quality.view
        $this->actingAs($u, 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")->assertForbidden();
    }

    public function test_run_authorized_creates_record_and_calls_service(): void
    {
        $doc = $this->makeDoc();
        $this->bindAuth($doc, 'blobRUN');
        $this->fakeCa(['job_id' => 'jobX', 'status' => 'queued']);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertStatus(202);

        $rec = SourceDocQualityAnalysis::where('source_doc_id', $doc->id)->firstOrFail();
        $this->assertSame('blobRUN', $rec->source_blob_sha);                 // (7) versão/blob correto
        $this->assertSame($doc->current_version_id, $rec->source_doc_version_id); // (8) vínculo à versão
        $this->assertSame('jobX', $rec->external_job_id);
        // payload correto enviado ao serviço (filename + conteúdo obtidos server-side)
        Http::assertSent(fn ($r) => $r->url() === self::CA_URL . '/api/v1/analyses'
            && $r['filename'] === basename($doc->path) && $r['content'] === 'code');
        // auditoria registrada
        $this->assertDatabaseHas('source_doc_action_log', [
            'source_doc_id' => $doc->id, 'action' => 'quality_run', 'status' => 'ok',
        ]);
    }

    public function test_run_without_run_permission_forbidden(): void
    {
        // Coord COM escopo (executivo do cliente) p/ isolar o gate de PERMISSÃO do de ESCOPO:
        // tem quality.view mas NÃO quality.run → 403 (permissão barra antes do controller).
        $cust = Customer::factory()->create();
        $doc = $this->makeDoc(customerId: $cust->id);
        $coord = User::factory()->create(['type' => 'coordenador']);
        $cust->update(['executive_id' => $coord->id]);
        $this->actingAs($coord, 'sanctum')
            ->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertForbidden();
    }

    // ── escopo / IDOR (5,6,19) ──────────────────────────────────────────────

    public function test_out_of_scope_returns_404(): void
    {
        $custX = Customer::factory()->create();
        $custY = Customer::factory()->create();
        $doc = $this->makeDoc(customerId: $custX->id);
        $coord = User::factory()->create(['type' => 'coordenador']);
        $custY->update(['executive_id' => $coord->id]); // escopo do coord = Y, não X
        $this->actingAs($coord, 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")->assertNotFound(); // 404, não vaza existência
    }

    public function test_nonexistent_source_doc_404(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/source-docs/99999999/quality')->assertNotFound();
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/source-docs/99999999/quality')->assertNotFound();
    }

    // ── versionamento / stale (9) ───────────────────────────────────────────

    public function test_completed_matching_blob_is_current(): void
    {
        $doc = $this->makeDoc('blobA');
        $this->bindAuth($doc, 'blobA'); // tree atual = blobA
        SourceDocQualityAnalysis::create([
            'source_doc_id' => $doc->id, 'source_doc_version_id' => $doc->current_version_id,
            'source_blob_sha' => 'blobA', 'status' => 'completed', 'score' => 82, 'grade' => 'B',
        ]);
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")
            ->assertOk()->assertJsonPath('data.state', 'completed')
            ->assertJsonPath('data.analysis.stale', false)
            ->assertJsonPath('data.analysis.score', 82);
    }

    public function test_blob_changed_marks_outdated(): void
    {
        $doc = $this->makeDoc('blobA');
        $this->bindAuth($doc, 'blobB'); // fonte mudou → tree atual = blobB
        SourceDocQualityAnalysis::create([
            'source_doc_id' => $doc->id, 'source_doc_version_id' => $doc->current_version_id,
            'source_blob_sha' => 'blobA', 'status' => 'completed', 'score' => 82,
        ]);
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")
            ->assertOk()->assertJsonPath('data.state', 'outdated')
            ->assertJsonPath('data.analysis.stale', true);
    }

    // ── concorrência (10) ───────────────────────────────────────────────────

    public function test_concurrent_duplicate_is_reused(): void
    {
        $doc = $this->makeDoc();
        $this->bindAuth($doc, 'blobDUP');
        $this->fakeCa(['job_id' => 'jobD', 'status' => 'queued']);
        $admin = $this->admin();

        $r1 = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertStatus(202);
        $r2 = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertStatus(202);

        $this->assertSame($r1->json('data.analysis.id'), $r2->json('data.analysis.id')); // mesmo job
        $this->assertTrue($r2->json('reused'));
        $this->assertSame(1, SourceDocQualityAnalysis::where('source_doc_id', $doc->id)->count());
        Http::assertSentCount(1); // serviço chamado só uma vez
    }

    // ── resiliência (11,12,13,14,15) ────────────────────────────────────────

    public function test_service_unavailable_5xx_marks_failed_and_503(): void
    {
        $doc = $this->makeDoc();
        $this->bindAuth($doc, 'blobU');
        $this->fakeCa(post: ['error' => 'boom'], postStatus: 500);
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertStatus(503);
        $rec = SourceDocQualityAnalysis::where('source_doc_id', $doc->id)->firstOrFail();
        $this->assertSame('failed', $rec->status); // NÃO fica "running" à toa
    }

    public function test_timeout_marks_failed_and_503(): void
    {
        $doc = $this->makeDoc();
        $this->bindAuth($doc, 'blobT');
        Http::fake(function () { throw new ConnectionException('timeout'); });
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertStatus(503);
        $this->assertSame('failed', SourceDocQualityAnalysis::where('source_doc_id', $doc->id)->firstOrFail()->status);
    }

    public function test_invalid_response_marks_failed(): void
    {
        $doc = $this->makeDoc();
        $this->bindAuth($doc, 'blobI');
        $this->fakeCa(post: ['no_job_id' => true], postStatus: 200); // resposta inválida
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertStatus(503);
        $this->assertSame('failed', SourceDocQualityAnalysis::where('source_doc_id', $doc->id)->firstOrFail()->status);
    }

    public function test_remote_failed_reflected_on_show(): void
    {
        $doc = $this->makeDoc();
        $this->bindAuth($doc, 'blobRF');
        $this->fakeCa(
            post: ['job_id' => 'jobRF', 'status' => 'queued'],
            get: ['job_id' => 'jobRF', 'status' => 'failed', 'error' => 'analyzer crashed']
        );
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertStatus(202);
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/source-docs/{$doc->id}/quality")
            ->assertOk()->assertJsonPath('data.state', 'failed');
    }

    public function test_completed_flow_persists_score_and_counts(): void
    {
        $doc = $this->makeDoc('blobC');
        $this->bindAuth($doc, 'blobC');
        $this->fakeCa(
            post: ['job_id' => 'jobC', 'status' => 'queued'],
            get: ['job_id' => 'jobC', 'status' => 'completed', 'score' => 82, 'grade' => 'B', 'risk' => 'MEDIO',
                  'counts' => ['critical' => 2, 'warnings' => 5, 'recommendations' => 11, 'total' => 18],
                  'engine' => ['name' => 'TOTVS', 'image' => 'img:1', 'rules_version' => 'r1'],
                  'finished_at' => '2026-08-23T15:40:00Z']
        );
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertStatus(202);
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/source-docs/{$doc->id}/quality")
            ->assertOk()->assertJsonPath('data.state', 'completed')
            ->assertJsonPath('data.analysis.score', 82)
            ->assertJsonPath('data.analysis.counts.critical', 2)
            ->assertJsonPath('data.analysis.counts.total', 18);
        $rec = SourceDocQualityAnalysis::where('source_doc_id', $doc->id)->firstOrFail();
        $this->assertSame('completed', $rec->status);
        $this->assertSame(2, $rec->n_critical);
    }

    // ── histórico (16) ──────────────────────────────────────────────────────

    public function test_history_lists_analyses(): void
    {
        $doc = $this->makeDoc('blobH');
        $this->bindAuth($doc, 'blobH');
        foreach (['blobOld', 'blobH'] as $b) {
            SourceDocQualityAnalysis::create([
                'source_doc_id' => $doc->id, 'source_doc_version_id' => $doc->current_version_id,
                'source_blob_sha' => $b, 'status' => 'completed', 'score' => 70,
            ]);
        }
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality/history")
            ->assertOk()->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.stale', false)   // mais recente = blobH (atual)
            ->assertJsonPath('data.items.1.stale', true);   // blobOld
    }

    // ── código-fonte não vaza ao browser (17) ───────────────────────────────

    public function test_run_response_never_contains_source_code(): void
    {
        $doc = $this->makeDoc();
        $this->bindAuth($doc, 'blobS', content: 'SECRET_CODE_XYZ'); // conteúdo sensível do fonte
        $this->fakeCa(['job_id' => 'jobS', 'status' => 'queued']);
        // usuário COM quality.run mas SEM view_git (e escopo global via view_all_customers)
        $u = User::factory()->create([
            'type' => 'consultor',
            'extra_permissions' => ['source_docs.quality.run', 'source_docs.view_all_customers'],
        ]);
        $res = $this->actingAs($u, 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertStatus(202);
        $this->assertStringNotContainsString('SECRET_CODE_XYZ', $res->getContent());
    }

    // ── token server-to-server (18) ─────────────────────────────────────────

    public function test_service_token_sent_in_header_and_never_in_response(): void
    {
        $doc = $this->makeDoc();
        $this->bindAuth($doc, 'blobTk');
        $this->fakeCa(['job_id' => 'jobTk', 'status' => 'queued']);
        $res = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertStatus(202);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer ' . self::CA_TOKEN));
        $this->assertStringNotContainsString(self::CA_TOKEN, $res->getContent());
    }

    // ── findings: gating de código por view_git + anti-IDOR ─────────────────

    private function makeCompletedAnalysis(SourceDoc $doc, string $jobId = 'jobF'): SourceDocQualityAnalysis
    {
        return SourceDocQualityAnalysis::create([
            'source_doc_id' => $doc->id, 'source_doc_version_id' => $doc->current_version_id,
            'source_blob_sha' => 'blobF', 'status' => 'completed', 'external_job_id' => $jobId, 'score' => 80,
        ]);
    }

    private function fakeFindings(): void
    {
        Http::fake(fn ($r) => Http::response(['job_id' => 'jobF', 'status' => 'completed', 'findings' => [[
            'severity' => 'CRITICAL', 'category' => 'G2 - Performance', 'rule' => 'CA_LOOP',
            'title' => 'Query em laço', 'description' => 'consulta dentro de laço', 'line' => 182, 'start_line' => 182,
            'snippet' => 'SECRET_SNIPPET_CODE', 'source' => 'SECRET_SOURCE', 'content' => 'SECRET_CONTENT',
        ]]], 200));
    }

    public function test_findings_with_view_git_includes_snippet(): void
    {
        $doc = $this->makeDoc();
        $rec = $this->makeCompletedAnalysis($doc);
        $this->fakeFindings();
        $res = $this->actingAs($this->admin(), 'sanctum') // admin tem view_git via '*'
            ->getJson("/api/v1/source-docs/{$doc->id}/quality/{$rec->id}/findings")->assertOk();
        $res->assertJsonPath('data.view_git', true)
            ->assertJsonPath('data.findings.0.snippet', 'SECRET_SNIPPET_CODE')
            ->assertJsonPath('data.findings.0.severity', 'CRITICAL')
            ->assertJsonPath('data.findings.0.line', 182);
    }

    public function test_findings_without_view_git_strips_all_code(): void
    {
        $doc = $this->makeDoc();
        $rec = $this->makeCompletedAnalysis($doc);
        $this->fakeFindings();
        $u = User::factory()->create([
            'type' => 'consultor',
            'extra_permissions' => ['source_docs.quality.view', 'source_docs.view_all_customers'],
        ]); // tem quality.view + escopo global, NÃO tem view_git
        $res = $this->actingAs($u, 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality/{$rec->id}/findings")->assertOk();
        $res->assertJsonPath('data.view_git', false)
            ->assertJsonPath('data.findings.0.severity', 'CRITICAL')   // metadado seguro preservado
            ->assertJsonPath('data.findings.0.line', 182)
            ->assertJsonMissingPath('data.findings.0.snippet')
            ->assertJsonMissingPath('data.findings.0.source')
            ->assertJsonMissingPath('data.findings.0.content');
        // nenhum trecho de código no JSON, em nenhuma forma
        $body = $res->getContent();
        $this->assertStringNotContainsString('SECRET_SNIPPET_CODE', $body);
        $this->assertStringNotContainsString('SECRET_SOURCE', $body);
        $this->assertStringNotContainsString('SECRET_CONTENT', $body);
    }

    public function test_findings_idor_analysis_of_other_doc_404(): void
    {
        $docA = $this->makeDoc();
        $docB = $this->makeDoc();
        $recB = $this->makeCompletedAnalysis($docB, 'jobB');
        // análise de B acessada via A → 404 (não vaza)
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$docA->id}/quality/{$recB->id}/findings")->assertNotFound();
    }

    public function test_findings_out_of_scope_404(): void
    {
        $custX = Customer::factory()->create();
        $custY = Customer::factory()->create();
        $doc = $this->makeDoc(customerId: $custX->id);
        $rec = $this->makeCompletedAnalysis($doc);
        $coord = User::factory()->create(['type' => 'coordenador']);
        $custY->update(['executive_id' => $coord->id]);
        $this->actingAs($coord, 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality/{$rec->id}/findings")->assertNotFound();
    }

    // ── sem regressão nos endpoints atuais da Central (20) ───────────────────

    public function test_no_regression_on_catalog_show(): void
    {
        $doc = $this->makeDoc();
        $this->bindAuth($doc, 'blobA');
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}")->assertOk()
            ->assertJsonPath('data.id', $doc->id);
    }

    // ── P2: persistência durável dos findings + reconciliação (job_lost) ─────

    /** Resposta completed do CA, com um finding que carrega snippet (código). */
    private function caCompletedWithFinding(): array
    {
        return ['job_id' => 'jobP', 'status' => 'completed',
            'engine' => ['name' => 'TOTVS', 'image' => 'img:latest', 'rules_version' => 'r1'],
            'score' => 91, 'grade' => 'A', 'risk' => 'BAIXO',
            'counts' => ['critical' => 0, 'warnings' => 0, 'recommendations' => 1, 'total' => 1],
            'findings' => [[
                'severity' => 'INFO', 'analyzer_severity' => 'INFO', 'category' => 'Outras',
                'rule' => 'CA_X', 'title' => 'Regra X', 'description' => 'desc', 'recommendation' => 'rec',
                'file' => 'F.prw', 'line' => 10, 'start_line' => 10, 'count' => 1,
                'snippet' => 'SECRET_CODE_XYZ', 'source' => 'SECRET_SRC',
            ]]];
    }

    public function test_findings_persisted_on_completion_and_survive_ca_restart(): void
    {
        $doc = $this->makeDoc('blobP');
        $this->bindAuth($doc, 'blobP');
        $this->fakeCa(['job_id' => 'jobP', 'status' => 'queued'], 202, $this->caCompletedWithFinding(), 200);

        // dispara (queued) → poll (show) completa e PERSISTE os findings
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/source-docs/{$doc->id}/quality")->assertStatus(202);
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")
            ->assertOk()->assertJsonPath('data.state', 'completed');

        $rec = SourceDocQualityAnalysis::where('source_doc_id', $doc->id)->firstOrFail();
        $this->assertDatabaseHas('source_doc_quality_findings', [
            'source_doc_quality_analysis_id' => $rec->id, 'rule' => 'CA_X',
            'severity' => 'INFO', 'line' => 10, 'category' => 'Outras',
        ]);
        // NENHUM código-fonte no banco (nem em coluna, nem no meta json)
        $f = SourceDocQualityFinding::where('source_doc_quality_analysis_id', $rec->id)->firstOrFail();
        $blob = json_encode($f->toArray());
        $this->assertStringNotContainsString('SECRET_CODE_XYZ', $blob);
        $this->assertStringNotContainsString('SECRET_SRC', $blob);

        // Sobrevivência ao restart do CA: um usuário SEM view_git é servido 100% do Postgres
        // (enrichSnippets é view_git-only → ZERO chamada ao CodeAnalysis). Prova que os findings
        // não dependem do store efêmero e que código nunca vaza.
        $noGit = User::factory()->create([
            'type' => 'consultor',
            'extra_permissions' => ['source_docs.quality.view', 'source_docs.view_all_customers'],
        ]);
        $res = $this->actingAs($noGit, 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality/{$rec->id}/findings")->assertOk();
        $res->assertJsonPath('data.source', 'persisted')      // servido do Postgres
            ->assertJsonPath('data.view_git', false)
            ->assertJsonPath('data.findings.0.rule', 'CA_X')
            ->assertJsonPath('data.findings.0.severity', 'INFO')
            ->assertJsonPath('data.findings.0.line', 10);
        // findings sobreviveram no Postgres; snippet (código) nunca aparece
        $this->assertStringNotContainsString('SECRET_CODE_XYZ', $res->getContent());
        $this->assertStringNotContainsString('SECRET_SRC', $res->getContent());
    }

    // ── reconciliação job_lost guardada (anti-404-prematuro) ────────────────

    /** Fake: /health → $healthCode; GET job → $jobBody/$jobCode; POST create → 202. */
    private function fakeHealthAndJob(int $healthCode, array $jobBody = ['message' => 'not found'], int $jobCode = 404): void
    {
        Http::fake(function ($request) use ($healthCode, $jobBody, $jobCode) {
            if (str_contains($request->url(), '/health')) {
                return Http::response(['status' => 'ok'], $healthCode);
            }
            if ($request->method() === 'GET') {
                return Http::response($jobBody, $jobCode);
            }
            return Http::response(['job_id' => 'jobX', 'status' => 'queued'], 202);
        });
    }

    private function inflight(SourceDoc $doc, string $blob, string $job): SourceDocQualityAnalysis
    {
        return SourceDocQualityAnalysis::create([
            'source_doc_id' => $doc->id, 'source_doc_version_id' => $doc->current_version_id,
            'source_blob_sha' => $blob, 'status' => 'running', 'external_job_id' => $job,
        ]);
    }

    /** (1) 404 único com CA saudável → NÃO terminaliza; só marca missing_since. */
    public function test_single_404_healthy_does_not_terminalize(): void
    {
        $doc = $this->makeDoc('b1'); $this->bindAuth($doc, 'b1');
        $rec = $this->inflight($doc, 'b1', 'j1');
        $this->fakeHealthAndJob(200, ['message' => 'not found'], 404);
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")->assertOk()
            ->assertJsonPath('data.state', 'running');
        $rec->refresh();
        $this->assertSame('running', $rec->status);
        $this->assertNull($rec->error_code);
        $this->assertNotNull($rec->missing_since); // 1ª ausência registrada, não confirmada
    }

    /** (2) 2º 404 confirmado + CA saudável + grace → job_lost. */
    public function test_confirmed_absence_healthy_marks_job_lost(): void
    {
        $doc = $this->makeDoc('b2'); $this->bindAuth($doc, 'b2');
        $rec = $this->inflight($doc, 'b2', 'j2');
        $this->fakeHealthAndJob(200, ['message' => 'not found'], 404);
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")->assertOk();
        $this->assertNotNull($rec->fresh()->missing_since);
        $this->assertSame('running', $rec->fresh()->status);

        $this->travel(25)->seconds(); // passa a grace
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")->assertOk()
            ->assertJsonPath('data.state', 'failed')
            ->assertJsonPath('data.analysis.error_code', 'job_lost');
        $rec->refresh();
        $this->assertSame('failed', $rec->status);
        $this->assertSame('job_lost', $rec->error_code);
        $this->assertNull($rec->missing_since);
        $this->travelBack();
    }

    /** (3) 404 com CA unhealthy → mantém estado (404 não confiável). */
    public function test_404_unhealthy_ca_keeps_state(): void
    {
        $doc = $this->makeDoc('b3'); $this->bindAuth($doc, 'b3');
        $rec = $this->inflight($doc, 'b3', 'j3');
        $this->fakeHealthAndJob(503, ['message' => 'not found'], 404);
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")->assertOk();
        $rec->refresh();
        $this->assertSame('running', $rec->status);
        $this->assertNull($rec->error_code);
        $this->assertNull($rec->missing_since); // sem marcador (CA unhealthy)
    }

    /** (4) getJob 5xx (transitório) → mantém estado. */
    public function test_transient_5xx_getjob_keeps_state(): void
    {
        $doc = $this->makeDoc('b4'); $this->bindAuth($doc, 'b4');
        $rec = $this->inflight($doc, 'b4', 'j4');
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/health')) return Http::response(['status' => 'ok'], 200);
            if ($request->method() === 'GET') return Http::response(['message' => 'boom'], 503);
            return Http::response(['job_id' => 'jobX', 'status' => 'queued'], 202);
        });
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")->assertOk();
        $rec->refresh();
        $this->assertSame('running', $rec->status);
        $this->assertNull($rec->error_code);
    }

    /** (5) job reaparece após ausência transitória → continua normalmente (marcador limpo). */
    public function test_job_reappears_clears_missing(): void
    {
        $doc = $this->makeDoc('b5'); $this->bindAuth($doc, 'b5');
        $rec = $this->inflight($doc, 'b5', 'j5');
        // closure stateful: /health sempre 200; 1º getJob → 404 (ausência), demais → 200 running (voltou).
        $getCalls = 0;
        Http::fake(function ($request) use (&$getCalls) {
            if (str_contains($request->url(), '/health')) return Http::response(['status' => 'ok'], 200);
            if ($request->method() === 'GET') {
                $getCalls++;
                return $getCalls === 1
                    ? Http::response(['message' => 'not found'], 404)
                    : Http::response(['job_id' => 'j5', 'status' => 'running'], 200);
            }
            return Http::response(['job_id' => 'j5', 'status' => 'queued'], 202);
        });
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")->assertOk();
        $this->assertNotNull($rec->fresh()->missing_since); // 1ª ausência

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")->assertOk(); // job voltou (getJob 200)
        $rec->refresh();
        $this->assertSame('running', $rec->status);
        $this->assertNull($rec->missing_since); // marcador limpo — job não estava perdido
        $this->assertNull($rec->error_code);
    }

    // ── CA-R1b: mapeamento de error_code estruturado (analyzer_timeout) ──────

    /** getJob failed + error_code=analyzer_timeout → BFF persiste analyzer_timeout (não remote_failed/job_lost). */
    public function test_analyzer_timeout_maps_structured_error_code(): void
    {
        $doc = $this->makeDoc('bT'); $this->bindAuth($doc, 'bT');
        $rec = $this->inflight($doc, 'bT', 'jT');
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/health')) return Http::response(['status' => 'ok'], 200);
            if ($request->method() === 'GET') {
                return Http::response([
                    'job_id' => 'jT', 'status' => 'failed',
                    'error' => 'Timeout: o analyzer excedeu CA_ANALYZER_TIMEOUT.',
                    'error_code' => 'analyzer_timeout',
                    'engine' => ['name' => 'TOTVS'],
                ], 200);
            }
            return Http::response(['job_id' => 'jT', 'status' => 'queued'], 202);
        });
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")->assertOk()
            ->assertJsonPath('data.state', 'failed')
            ->assertJsonPath('data.analysis.error_code', 'analyzer_timeout');
        $rec->refresh();
        $this->assertSame('failed', $rec->status);
        $this->assertSame('analyzer_timeout', $rec->error_code);
    }

    /** getJob failed SEM error_code → fallback remote_failed (compat). */
    public function test_failed_without_error_code_maps_remote_failed(): void
    {
        $doc = $this->makeDoc('bF'); $this->bindAuth($doc, 'bF');
        $rec = $this->inflight($doc, 'bF', 'jF');
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/health')) return Http::response(['status' => 'ok'], 200);
            if ($request->method() === 'GET') {
                return Http::response(['job_id' => 'jF', 'status' => 'failed', 'error' => 'boom', 'engine' => ['name' => 'TOTVS']], 200);
            }
            return Http::response(['job_id' => 'jF', 'status' => 'queued'], 202);
        });
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/source-docs/{$doc->id}/quality")->assertOk()
            ->assertJsonPath('data.analysis.error_code', 'remote_failed');
        $this->assertSame('remote_failed', $rec->fresh()->error_code);
    }
}
