<?php

namespace App\Console\Commands;

use App\Models\SourceDoc;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cross-source Fase 1 — constrói o índice "quem define o símbolo" a partir do determinístico já
 * armazenado (read-only sobre o determinístico; a tabela é read-model derivado/reconstruível).
 * SEM IA. Uso: php artisan source-doc:build-symbol-index [--truncate]
 */
class SourceDocBuildSymbolIndexCommand extends Command
{
    protected $signature = 'source-doc:build-symbol-index {--truncate} {--id=}';
    protected $description = 'Reconstrói source_symbol_definition (índice de definições) a partir do determinístico.';

    public function handle(): int
    {
        if ($this->option('truncate')) {
            DB::table('source_symbol_definition')->truncate();
            $this->info('índice truncado.');
        }
        $writesEff = ['database_write', 'database_delete', 'file_write', 'routine_execution'];
        $q = SourceDoc::query()->with('currentVersion')->whereNotNull('current_version_id');
        if ($id = $this->option('id')) {
            $q->where('id', (int) $id);
        }
        $rows = [];
        $docs = 0;
        $syms = 0;
        $now = now();
        $q->chunkById(200, function ($chunk) use (&$rows, &$docs, &$syms, $writesEff, $now) {
            foreach ($chunk as $doc) {
                $ver = $doc->currentVersion;
                $det = is_array($ver?->deterministic_json) ? $ver->deterministic_json : null;
                if (! $det) {
                    continue;
                }
                $docs++;
                foreach (($det['functions'] ?? []) as $f) {
                    $name = (string) ($f['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $eff = (array) ($f['effects'] ?? []);
                    $rows[] = [
                        'symbol_norm'      => $this->norm($name),
                        'source_doc_id'    => $doc->id,
                        'version_id'       => $ver->id,
                        'blob_sha'         => $ver->source_blob_sha,
                        'owner'            => $doc->owner,
                        'repository'       => $doc->repository,
                        'function_name'    => mb_substr($name, 0, 191),
                        'start_line'       => $f['start_line'] ?? ($f['evidence']['line_start'] ?? null),
                        'end_line'         => $f['end_line'] ?? ($f['evidence']['line_end'] ?? null),
                        'is_user_function' => str_contains(strtolower((string) ($f['type'] ?? '')), 'user function'),
                        'writes'           => (bool) array_intersect($writesEff, $eff),
                        'touches_tables'   => count((array) ($f['tables'] ?? [])),
                        'created_at'       => $now, 'updated_at' => $now,
                    ];
                    $syms++;
                }
                if (count($rows) >= 1000) {
                    foreach (array_chunk($rows, 500) as $c) {
                        DB::table('source_symbol_definition')->insert($c);
                    }
                    $rows = [];
                }
            }
        });
        foreach (array_chunk($rows, 500) as $c) {
            DB::table('source_symbol_definition')->insert($c);
        }
        $this->info("índice construído: {$docs} fontes, {$syms} definições.");
        return self::SUCCESS;
    }

    private function norm(string $s): string
    {
        $s = strtolower(trim($s));
        return str_starts_with($s, 'u_') ? substr($s, 2) : $s;
    }
}
