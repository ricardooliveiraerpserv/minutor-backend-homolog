<?php

namespace App\Console\Commands;

use App\Models\SourceDoc;
use App\SourceCode\SourceContextResolver;
use Illuminate\Console\Command;

/**
 * Cross-source Fase 1 — roda o resolver determinístico num fonte e imprime a cadeia (resolved/
 * ambiguous/unresolved, descartes com motivo, context_sources, telemetria). SEM IA.
 * Uso: php artisan source-doc:resolve-context 66 [--persist]
 */
class SourceDocResolveContextCommand extends Command
{
    protected $signature = 'source-doc:resolve-context {id} {--persist} {--json}';
    protected $description = 'Resolve dependências cross-source (determinístico, read-only) e mostra a proveniência.';

    public function handle(SourceContextResolver $resolver): int
    {
        $doc = SourceDoc::with('currentVersion')->find((int) $this->argument('id'));
        if (! $doc) {
            $this->error('Fonte não encontrada.');
            return self::FAILURE;
        }
        $r = $resolver->resolve($doc, (bool) $this->option('persist'));

        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->line("== Fonte #{$doc->id} {$doc->owner}/{$doc->repository} :: ".basename($doc->path));
        $this->line('telemetria: '.json_encode($r['telemetry'], JSON_UNESCAPED_UNICODE));
        $this->line("\n-- RESOLVED (".count($r['resolved']).') --');
        foreach ($r['resolved'] as $x) {
            $this->line(sprintf('  %-18s -> doc %-5s %s [%s]  fn=%s L%s-%s  writes=%s tabs=%s  score=%.2f  %s%s',
                $x['symbol'], $x['target_doc_id'], $x['target_repo'], $x['scope'], $x['target_function'],
                $x['start_line'], $x['end_line'], $x['writes'] ? 'sim' : '-', $x['touches_tables'],
                $x['relevance_score'], $x['relevant'] ? 'RELEVANTE' : 'descartado:'.$x['reason'],
                $x['relevant'] ? " ~{$x['est_context_tokens']}tok" : ''));
        }
        $this->line("\n-- AMBIGUOUS (".count($r['ambiguous']).') --');
        foreach ($r['ambiguous'] as $x) {
            $this->line("  {$x['symbol']} [{$x['scope']}] candidatos=".json_encode($x['candidates']));
        }
        $this->line("\n-- UNRESOLVED (".count($r['unresolved']).') -- '.json_encode($r['unresolved']));
        $this->line("\n-- DESCARTES (".count($r['discarded']).') --');
        foreach ($r['discarded'] as $x) {
            $this->line("  {$x['symbol']} -> doc ".($x['target_doc'] ?? '-')." motivo={$x['reason_discarded']} score={$x['relevance_score']}");
        }
        $this->line("\n-- CONTEXT_SOURCES (facts-first; bounded; entrariam na IA na Fase 3) (".count($r['context_sources']).') --');
        foreach ($r['context_sources'] as $x) {
            $this->line(sprintf('  %s -> doc %s (%s) fn=%s | facts=%s snippet=%s%s ~%dtok',
                $x['symbol'], $x['target_doc_id'], $x['target_repo'], $x['target_function'],
                $x['facts_included'] ? 'sim' : '-', $x['snippet_included'] ? 'sim' : 'NÃO',
                $x['snippet_included'] ? '' : ' ('.$x['snippet_skipped_reason'].')', $x['estimated_context_tokens']));
        }
        if ($this->option('persist')) {
            $this->info("\nedges persistidas em source_semantic_context_edge (dependent={$doc->id}).");
        }
        return self::SUCCESS;
    }
}
