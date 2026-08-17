<?php

namespace Tests\Feature;

use App\SourceCode\SemanticBlobReuse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Bloco 4.2.1-A — reuso semântico persistente por blob+contrato. Reusa a ANÁLISE, não o documento.
 * Chave inclui facts_version (versão do contrato determinístico) → parser evoluído invalida reuso.
 */
class SemanticBlobReuseTest extends TestCase
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
        config([
            'services.source_doc_ai.blob_reuse_enabled' => true,
            'services.source_doc_ai.facts_version' => 1,
            'services.source_doc_ai.prompt_version' => 2,
            'services.source_doc_ai.model' => 'fake-model-1',
        ]);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function sem(string $status = 'completed'): array
    {
        return ['status' => $status, 'objetivo' => 'Faz X.', 'funcoes' => [['name' => 'F', 'finalidade' => 'y']],
            'usage' => ['calls' => 4, 'actual_cost_usd' => 0.2], 'strategy' => 'initial_blocks_v3'];
    }

    public function test_put_then_get_reuses_analysis(): void
    {
        $svc = new SemanticBlobReuse();
        $svc->put('BLOBX', $this->sem(), 101); // origem = doc 101 (cliente A)

        // 2ª ocorrência (doc 202, cliente B) do MESMO blob → reusa
        $r = $svc->get('BLOBX', 202);
        $this->assertNotNull($r);
        $this->assertSame('reuse_blob_persistent', $r['strategy']);
        $this->assertTrue($r['reuse']['hit']);
        $this->assertSame(101, $r['reuse']['origin_source_doc_id']); // origem preservada
        $this->assertSame('Faz X.', $r['objetivo']);                  // análise idêntica
        $this->assertSame(0, $r['usage']['calls']);                   // 0 chamadas IA nesta ocorrência
        $this->assertSame(0.0, $r['usage']['actual_cost_usd']);
    }

    public function test_different_facts_version_does_not_reuse(): void
    {
        $svc = new SemanticBlobReuse();
        $svc->put('BLOBY', $this->sem(), 1);
        // parser evoluiu → facts_version bumpado → semântica antiga NÃO é reutilizada
        config(['services.source_doc_ai.facts_version' => 2]);
        $this->assertNull($svc->get('BLOBY', 2));
    }

    public function test_different_prompt_version_does_not_reuse(): void
    {
        $svc = new SemanticBlobReuse();
        $svc->put('BLOBZ', $this->sem(), 1);
        config(['services.source_doc_ai.prompt_version' => 99]);
        $this->assertNull($svc->get('BLOBZ', 2));
    }

    public function test_does_not_store_unusable_status(): void
    {
        $svc = new SemanticBlobReuse();
        $svc->put('BLOBF', $this->sem('failed'), 1);
        $svc->put('BLOBF', $this->sem('skipped_cost_limit'), 1);
        $this->assertNull($svc->get('BLOBF', 2)); // nada aproveitável foi guardado
    }

    public function test_does_not_restore_a_reused_result(): void
    {
        $svc = new SemanticBlobReuse();
        $svc->put('BLOBR', $this->sem(), 10);
        $reused = $svc->get('BLOBR', 20);           // vem com reuse.hit=true
        $svc->put('BLOBR2', $reused, 20);           // não deve re-armazenar um reuso
        $this->assertNull($svc->get('BLOBR2', 30));
    }

    public function test_flag_off_disables_reuse(): void
    {
        config(['services.source_doc_ai.blob_reuse_enabled' => false]);
        $svc = new SemanticBlobReuse();
        $svc->put('BLOBOFF', $this->sem(), 1);
        $this->assertNull($svc->get('BLOBOFF', 2)); // rollback: no-op
    }

    // ── Fase 3 — P0: contexto externo faz parte da chave ─────────────────────────

    public function test_different_context_fingerprint_does_not_reuse(): void
    {
        // MESMO blob principal, contexto EXTERNO diferente (B vs C) → NÃO reutiliza (erro silencioso evitado).
        $svc = new SemanticBlobReuse();
        $svc->put('BLOBCTX', $this->sem(), 1, 'fingerprintB');
        $this->assertNull($svc->get('BLOBCTX', 2, 'fingerprintC'), 'blob A + ctx B não pode servir blob A + ctx C');
        $r = $svc->get('BLOBCTX', 2, 'fingerprintB');
        $this->assertNotNull($r, 'mesmo blob + mesmo contexto → reutiliza');
        $this->assertTrue($r['reuse']['hit']);
    }

    public function test_self_contained_neutral_fingerprint_reuses(): void
    {
        // self-contained (fingerprint '') é o comportamento atual e casa com linhas sem contexto.
        $svc = new SemanticBlobReuse();
        $svc->put('BLOBNEUTRO', $this->sem(), 1);          // put sem fingerprint → '' neutro
        $this->assertNotNull($svc->get('BLOBNEUTRO', 2));  // get sem fingerprint → HIT
        $this->assertNull($svc->get('BLOBNEUTRO', 2, 'ctxX'), 'contexto externo não reusa a semântica sem contexto');
    }
}
