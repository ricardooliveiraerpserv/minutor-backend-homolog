<?php
// Medição C1 — roda contra minutor_c1full (350 fontes reais/clonadas, 3 repos).
// Chama os métodos do controller diretamente (sem middleware) para isolar o custo de banco.

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\SourceDocCatalogController;
use App\SourceCode\SourceDocStatusResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// aponta para o banco de medição
config(['database.connections.pgsql.database' => 'minutor_c1full', 'cache.default' => 'array']);
DB::purge('pgsql');

$ctrl = $app->make(SourceDocCatalogController::class);

function timeIt(callable $fn, int $iters = 5): array {
    $times = [];
    for ($i = 0; $i < $iters; $i++) { $t = microtime(true); $fn(); $times[] = (microtime(true) - $t) * 1000; }
    sort($times);
    return ['min' => round($times[0], 1), 'median' => round($times[intdiv(count($times), 2)], 1), 'max' => round(end($times), 1)];
}

echo "=== dataset ===\n";
echo 'source_docs: ' . DB::table('source_docs')->count() . "\n";
echo 'repos distintos: ' . DB::table('source_docs')->distinct()->count(DB::raw("owner||'/'||repository||'/'||branch")) . "\n\n";

// ---- 1) CATÁLOGO: query count (N+1) + timing, per_page=50, sem situação (isola DB) ----
$req = Request::create('/api/v1/source-docs', 'GET', ['per_page' => 50, 'with_situation' => 'false']);
DB::flushQueryLog(); DB::enableQueryLog();
$resp = $ctrl->index($req);
$qlog = DB::getQueryLog(); DB::disableQueryLog();
$json = $resp->getData(true);
echo "=== 1) CATÁLOGO (per_page=50, with_situation=false) ===\n";
echo 'linhas retornadas: ' . count($json['data']) . ' de total ' . $json['pagination']['total'] . "\n";
echo 'QUERIES executadas: ' . count($qlog) . "  (constante = sem N+1)\n";
$t = timeIt(fn () => $ctrl->index(Request::create('/x', 'GET', ['per_page' => 50, 'with_situation' => 'false'])));
echo "tempo COM functions_count (ms): min={$t['min']} mediana={$t['median']} max={$t['max']}\n";
$tf = timeIt(fn () => $ctrl->index(Request::create('/x', 'GET', ['per_page' => 50, 'with_situation' => 'false', 'with_counts' => 'false'])));
echo "tempo SEM functions_count (with_counts=false) (ms): min={$tf['min']} mediana={$tf['median']} max={$tf['max']}\n";
echo 'indicadores: total=' . $json['indicators']['total'] . ' | by_situation presente? ' . (isset($json['indicators']['by_situation']) ? 'SIM(erro)' : 'nao (ok, ajuste #1)') . "\n";
// tamanho do payload do catálogo (50 linhas)
echo 'payload catálogo (50 linhas): ' . number_format(strlen(json_encode($json)) / 1024, 1) . " KB\n\n";

// prova N+1: repetir com per_page menor não muda o nº de queries base
$req10 = Request::create('/x', 'GET', ['per_page' => 10, 'with_situation' => 'false']);
DB::flushQueryLog(); DB::enableQueryLog(); $ctrl->index($req10); $q10 = count(DB::getQueryLog()); DB::disableQueryLog();
echo "prova N+1: per_page=10 → {$q10} queries · per_page=50 → " . count($qlog) . " queries (iguais)\n\n";

// ---- 2) FICHA META vs DOCUMENTAÇÃO PESADA (payload pequeno × grande) ----
echo "=== 2) PAYLOAD ficha (ajuste #3) — real ===\n";
// evita GitHub: injeta um resolver fake que não bate na rede
$fakeResolver = new class(app(App\SourceCode\GithubAppAuth::class)) extends SourceDocStatusResolver {
    public function resolve(App\Models\SourceDoc $doc, bool $f = false): array {
        return ['status' => 'NAO_VALIDADO', 'documented_blob_sha' => null, 'current_blob_sha' => null, 'source_commit_sha' => null, 'checked_at' => now()->toIso8601String(), 'reason' => 'measurement', 'message' => ''];
    }
};
$app->instance(SourceDocStatusResolver::class, $fakeResolver);
$ctrl2 = $app->make(SourceDocCatalogController::class);

foreach ([['id' => 1, 'label' => 'PEQUENO (updwms_sx1.prw)'], ['id' => 9, 'label' => 'GRANDE (CCSPCP03.PRW, 78 funções)']] as $c) {
    $metaResp = $ctrl2->show($c['id']);
    $metaSize = strlen(json_encode($metaResp->getData(true)));
    $docResp = $ctrl2->documentation($c['id']);
    $docSize = strlen(json_encode($docResp->getData(true)));
    $fullDb = DB::table('source_docs')->where('id', $c['id'])->value(DB::raw('octet_length(documentation_json::text)'));
    printf("%-40s meta(show)=%6.1f KB | documentation(pesado)=%7.1f KB | doc_json inteiro no banco=%7.1f KB\n",
        $c['label'], $metaSize / 1024, $docSize / 1024, $fullDb / 1024);
}
$t2 = timeIt(fn () => $ctrl2->show(9));
echo "tempo ficha-meta fonte GRANDE (ms): min={$t2['min']} mediana={$t2['median']}\n";
echo "\nOK\n";
