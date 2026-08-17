<?php
// Medição C2 contra minutor_c1full (350 fontes, ~59.7k entidades).
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\SourceDocCatalogController;
use App\Http\Controllers\SourceDocSearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

config(['database.connections.pgsql.database' => 'minutor_c1full', 'cache.default' => 'array']);
DB::purge('pgsql');

function pctl(array $t, float $p): float { sort($t); $i = (int) ceil($p * count($t)) - 1; return round($t[max(0, $i)], 1); }
function bench(callable $fn, int $n = 30): array {
    $t = [];
    for ($i = 0; $i < $n; $i++) { $s = microtime(true); $fn(); $t[] = (microtime(true) - $s) * 1000; }
    return ['p50' => pctl($t, .5), 'p95' => pctl($t, .95), 'min' => round(min($t), 1)];
}

echo 'entidades: ' . DB::table('source_doc_entities')->count() . ' | fontes indexados: ' . DB::table('source_doc_index')->count() . "\n\n";

$cat = $app->make(SourceDocCatalogController::class);
$search = $app->make(SourceDocSearchController::class);

// 1) catálogo COM functions_count (agora do índice) — sem situação p/ isolar DB
$c = bench(fn () => $cat->index(Request::create('/x', 'GET', ['per_page' => 50, 'with_situation' => 'false'])));
echo "1) CATÁLOGO 50 linhas c/ functions_count (do índice): p50={$c['p50']}ms p95={$c['p95']}ms\n";
// prova: pega uma linha e mostra functions_count
$j = $cat->index(Request::create('/x', 'GET', ['per_page' => 1, 'with_situation' => 'false']))->getData(true);
echo "   functions_count exemplo: " . ($j['data'][0]['functions_count'] ?? 'null') . "\n\n";

// 2) busca por dimensão (p50/p95)
$cases = [
    ['entity' => 'table', 'q' => 'SC2', 'match' => 'exact', 'label' => 'tabela=SC2'],
    ['entity' => 'field', 'q' => 'C2_STATUS', 'match' => 'exact', 'access' => 'UPDATE', 'label' => 'campo=C2_STATUS access=UPDATE'],
    ['entity' => 'function', 'q' => 'IMPPCP', 'match' => 'exact', 'label' => 'função=IMPPCP'],
    ['entity' => 'risk', 'q' => 'dynamic_sql_by_concatenation', 'match' => 'exact', 'label' => 'risk=dynamic_sql'],
    ['entity' => 'table', 'q' => 'S', 'match' => 'prefix', 'label' => 'tabela prefixo S*'],
];
foreach ($cases as $c2) {
    $params = array_diff_key($c2, ['label' => 1]);
    $b = bench(fn () => $search->search(Request::create('/x', 'GET', $params)));
    $res = $search->search(Request::create('/x', 'GET', $params))->getData(true);
    echo "2) busca {$c2['label']}: p50={$b['p50']}ms p95={$b['p95']}ms · fontes={$res['pagination']['total']}\n";
}

echo "\n3) PROVA de uso do índice (EXPLAIN, não toca deterministic_json):\n";
$plan = DB::select("explain select distinct source_doc_id from source_doc_entities where entity_type='table' and lower(name)='sc2'");
foreach ($plan as $p) { echo '   ' . $p->{'QUERY PLAN'} . "\n"; }
echo "\nOK\n";
