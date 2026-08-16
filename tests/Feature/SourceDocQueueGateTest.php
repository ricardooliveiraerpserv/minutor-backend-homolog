<?php

namespace Tests\Feature;

use App\Jobs\ReprocessSourceDocJob;
use App\Models\SourceDoc;
use App\Models\SourceDocActionLog;
use App\Models\SourceDocVersion;
use App\Models\User;
use App\SourceCode\GithubAppAuth;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Central de Fontes — C3 (gate de fila). Prova: reprocess é async (job na conexão/fila dedicada,
 * POST não bloqueia); o reaper recupera execução órfã (libera o lock inflight); nova tentativa OK.
 */
class SourceDocQueueGateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['cache.default' => 'array', 'services.source_doc_ai.reap_stale_minutes' => 15]);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function admin(): User { return User::factory()->create(['type' => 'admin']); }

    private function makeDoc(string $status, string $blob): SourceDoc
    {
        $doc = SourceDoc::create(['owner' => 'erpserv-clientes', 'repository' => 'concreserv', 'branch' => 'main',
            'path' => 'x/' . uniqid() . '.prw', 'filename' => 'X.prw', 'tipo' => 'protheus', 'analysis_status' => $status]);
        $ver = SourceDocVersion::create(['source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . uniqid(),
            'source_blob_sha' => $blob, 'analysis_status' => $status, 'deterministic_json' => ['functions' => []]]);
        $doc->forceFill(['current_version_id' => $ver->id])->save();
        return $doc->refresh();
    }

    private function fakeAuth(string $blob): GithubAppAuth
    {
        return new class($blob) extends GithubAppAuth {
            public function __construct(private string $b) { parent::__construct(); }
            public function getFileWithSha(string $o, string $r, string $ref, string $p): ?array { return ['content' => 'c', 'blob_sha' => $this->b]; }
            public function treeBlobShas(string $o, string $r, string $ref): array { return []; }
        };
    }

    /** Job vai para a conexão/fila DEDICADA (async) — POST não roda inline. */
    public function test_reprocess_dispatches_to_dedicated_async_queue(): void
    {
        Queue::fake();
        $doc = $this->makeDoc('partial', 'blobA');
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'deterministic'])->assertStatus(202);
        Queue::assertPushed(ReprocessSourceDocJob::class, function ($job) {
            return $job->connection === 'database' && $job->queue === 'source-doc';
        });
    }

    /** Reaper marca execução órfã (running vencida) como failed/stale_execution e libera o inflight. */
    public function test_reaper_recovers_orphan_execution_and_frees_inflight(): void
    {
        Queue::fake();
        $doc = $this->makeDoc('partial', 'blobA');
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $admin = $this->admin();

        // 1ª execução enfileirada (fica inflight = queued)
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'deterministic'])->assertStatus(202);
        // simula ÓRFÃ: running há 20min (processo morreu no meio). Enquanto assim, o índice inflight
        // bloquearia nova tentativa (409 — coberto em SourceDocActionTest::double_click_and_concurrency).
        SourceDocActionLog::where('source_doc_id', $doc->id)->where('action', 'reprocess')
            ->update(['status' => 'running', 'updated_at' => now()->subMinutes(20)]);

        // reaper roda
        $this->artisan('source-doc:reap-stale-executions')->assertExitCode(0);
        $log = SourceDocActionLog::where('source_doc_id', $doc->id)->where('action', 'reprocess')->latest('id')->first();
        $this->assertSame('failed', $log->status);
        $this->assertSame('stale_execution', $log->reason);

        // agora nova tentativa é aceita (inflight liberado)
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'deterministic'])->assertStatus(202);
    }

    /** Reaper NÃO toca execução recente (dentro do limite). */
    public function test_reaper_leaves_fresh_execution(): void
    {
        Queue::fake();
        $doc = $this->makeDoc('partial', 'blobA');
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'deterministic'])->assertStatus(202);
        // running há 2min (fresca)
        SourceDocActionLog::where('source_doc_id', $doc->id)->update(['status' => 'running', 'updated_at' => now()->subMinutes(2)]);
        $this->artisan('source-doc:reap-stale-executions')->assertExitCode(0);
        $log = SourceDocActionLog::where('source_doc_id', $doc->id)->latest('id')->first();
        $this->assertSame('running', $log->status); // preservada
    }
}
