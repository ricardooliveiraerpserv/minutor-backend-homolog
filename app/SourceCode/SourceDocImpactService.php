<?php

namespace App\SourceCode;

use App\Models\SourceDoc;
use App\Models\SourceDocEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * C4b — Motor de ANÁLISE DE IMPACTO (read-model, SEM IA, SEM scan de deterministic_json).
 *
 * Responde "se eu alterar X, onde impacta?" de forma ESTRUTURADA (cliente → fonte → função →
 * acesso → evidência), lendo apenas source_doc_entities (índice C2) + source_docs + customers.
 * Governança = SourceDocCustomerScope (C4a) é a ÚNICA fonte de verdade do escopo (deny-by-default).
 *
 * REGRAS FIXAS desta fase:
 *  - Fatos determinísticos (contagens/operações); NENHUM grau BAIXO/MÉDIO/ALTO.
 *  - Sanitização de integration/risk é BACKEND, antes da serialização (nunca vaza URL/IP/token/segredo).
 *  - Motor v2.1 congelado; nenhuma chamada de IA; nenhum reprocessamento.
 *
 * Escopo × cross-customer:
 *  - accessibleCustomerIds() é a fronteira DURA de dados (sempre aplicada).
 *  - cross=true (cruzamento ENTRE clientes) exige: global (admin/view_all) OU view_cross_customer;
 *    cliente externo NUNCA cruza (nem com permissão indevida) → cross rejeitado.
 */
class SourceDocImpactService
{
    /** operações consideradas LEITURA / ESCRITA (derivadas do access[] indexado). */
    public const READ_OPS  = ['READ', 'SELECT'];
    public const WRITE_OPS = ['INSERT', 'UPDATE', 'DELETE'];

    /** entidades que o índice suporta para impacto. */
    public const ENTITIES = ['field', 'table', 'function', 'dependency', 'integration', 'risk'];

    public function __construct(private SourceDocCustomerScope $scope)
    {
    }

    /**
     * Resolve o escopo de dados + se o cruzamento entre clientes é permitido.
     * @return array{ids: ?array, denied: bool, cross: bool, cross_rejected: bool}
     */
    public function resolveScope(User $user, bool $cross): array
    {
        $ids = $this->scope->accessibleCustomerIds($user); // null=global, []=deny, [ids]

        if ($ids === []) {
            return ['ids' => [], 'denied' => true, 'cross' => false, 'cross_rejected' => false];
        }

        $external = $user->isCustomerUser() || $user->isCliente();
        $crossOk = false;
        $crossRejected = false;

        if ($cross) {
            if ($external) {
                $crossRejected = true;                 // cliente externo NUNCA cruza
            } elseif ($ids === null) {
                $crossOk = true;                       // global (admin/view_all)
            } elseif ($user->hasAccess('source_docs.view_cross_customer')) {
                $crossOk = true;                       // interno com permissão: cruza dentro do próprio escopo
            } else {
                $crossRejected = true;                 // sem permissão de cruzamento
            }
        }

        return ['ids' => $ids, 'denied' => false, 'cross' => $crossOk, 'cross_rejected' => $crossRejected];
    }

    /**
     * Executa a análise de impacto.
     *
     * @param array{entity:string,name:string,table?:?string,access?:?string,cross?:bool,page?:int,per_page?:int} $q
     * @return array{summary:array,data:array,pagination:array,query:array,notice:?string}
     */
    public function impact(User $user, array $q): array
    {
        $entity = (string) ($q['entity'] ?? '');
        $name   = trim((string) ($q['name'] ?? ''));
        $table  = isset($q['table']) ? trim((string) $q['table']) : null;
        $access = (string) ($q['access'] ?? 'any');   // read | write | any
        $cross  = (bool) ($q['cross'] ?? false);
        $page   = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(max((int) ($q['per_page'] ?? 30) ?: 30, 1), 100);

        $scope = $this->resolveScope($user, $cross);

        // Deny-by-default: sem escopo → VAZIO absoluto (sem metadata/count/cliente — teste lateral).
        if ($scope['denied']) {
            return $this->emptyResult($entity, $name, $table, $access, $page, $perPage);
        }

        // customer_ids p/ filtro (null = global, sem restrição).
        $ids = $scope['ids'];

        // Filtro opcional por UM cliente — só estreita DENTRO do escopo (deny-by-default:
        // cliente fora do escopo acessível → resultado vazio, sem vazamento/IDOR).
        $onlyCustomer = isset($q['customer_id']) && (int) $q['customer_id'] > 0 ? (int) $q['customer_id'] : null;
        if ($onlyCustomer !== null) {
            if ($ids === null || in_array($onlyCustomer, $ids, true)) {
                $ids = [$onlyCustomer];
            } else {
                return $this->emptyResult($entity, $name, $table, $access, $page, $perPage);
            }
        }

        // Query base scoped (fresh via callback p/ clonar em summary × página).
        $base = fn () => $this->baseQuery($entity, $name, $table, $access, $ids);

        $summary = $this->summary($base, $entity, $access);
        [$pagination, $groups] = $this->pagedGroups($base, $entity, $page, $perPage);

        return [
            'summary'    => $summary,
            'data'       => $groups,
            'pagination' => $pagination,
            'query'      => [
                'entity' => $entity,
                // integration: NUNCA ecoar o valor bruto (URL/token) — devolve projeção segura.
                'name'   => $entity === 'integration' ? $this->sanitizeIntegration($name)['integration'] : $name,
                'table'  => $table,
                'access' => $access, 'cross' => $scope['cross'],
            ],
            'notice'     => $scope['cross_rejected']
                ? 'Cruzamento entre clientes não autorizado; resultado limitado ao seu escopo.'
                : null,
        ];
    }

    // ── query base ───────────────────────────────────────────────────────────

    /**
     * Query scoped das entidades que casam (entity_type + nome exato + parent + access).
     * Usa o índice type_lname (entity_type, lower(name)) e access_gin.
     */
    private function baseQuery(string $entity, string $name, ?string $table, string $access, ?array $ids)
    {
        $q = SourceDocEntity::query()
            ->where('entity_type', $entity)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)]);

        // field: opcionalmente restringe pela tabela (parent).
        if ($entity === 'field' && $table !== null && $table !== '') {
            $q->where('parent', $table);
        }

        $this->applyAccessFilter($q, $access);

        // ESCOPO POR CLIENTE — fronteira dura (deny-by-default já tratado antes).
        if ($ids !== null) {
            $q->whereIn('customer_id', $ids);
        }

        return $q;
    }

    /** read → contém READ/SELECT ; write → contém INSERT/UPDATE/DELETE ; any → sem filtro. */
    private function applyAccessFilter($q, string $access): void
    {
        $ops = match ($access) {
            'read'  => self::READ_OPS,
            'write' => self::WRITE_OPS,
            default => [],
        };
        if ($ops === []) {
            return;
        }
        $q->where(function ($w) use ($ops) {
            foreach ($ops as $op) {
                $w->orWhereRaw('access @> ?', [json_encode([$op])]); // usa access_gin (jsonb_path_ops)
            }
        });
    }

    // ── summary (agregado, INDEPENDENTE da página) ────────────────────────────

    private function summary(callable $base, string $entity, string $access): array
    {
        $agg = $base()->selectRaw('
            count(distinct customer_id)   as clientes,
            count(distinct source_doc_id) as fontes,
            count(*)                      as ocorrencias,
            count(distinct parent) filter (where parent is not null) as contextos
        ')->first();

        // leitores / escritores por ocorrência (só faz sentido onde há access).
        $readers = 0; $writers = 0;
        if (in_array($entity, ['field', 'table'], true)) {
            $readers = (clone $this->baseAccess($base, self::READ_OPS))->count();
            $writers = (clone $this->baseAccess($base, self::WRITE_OPS))->count();
        }

        // risk_flags recorrentes (sanitizado: só o nome da flag, nunca o valor/segredo).
        $risks = $base()
            ->whereRaw("risk_flags is not null and risk_flags::text <> '[]'")
            ->get(['risk_flags'])
            ->flatMap(fn ($r) => (array) $r->risk_flags)
            ->countBy()
            ->sortDesc()
            ->take(10)
            ->all();

        return [
            'clientes'    => (int) ($agg->clientes ?? 0),
            'fontes'      => (int) ($agg->fontes ?? 0),
            'ocorrencias' => (int) ($agg->ocorrencias ?? 0),
            'contextos'   => (int) ($agg->contextos ?? 0),   // funções/tabelas de contexto
            'leitores'    => $readers,
            'escritores'  => $writers,
            'risk_flags'  => $risks,
        ];
    }

    private function baseAccess(callable $base, array $ops)
    {
        $q = $base();
        $q->where(function ($w) use ($ops) {
            foreach ($ops as $op) {
                $w->orWhereRaw('access @> ?', [json_encode([$op])]);
            }
        });
        return $q;
    }

    // ── agrupamento paginado (cliente → fonte → ocorrências) ──────────────────

    private function pagedGroups(callable $base, string $entity, int $page, int $perPage): array
    {
        // Paginação POR FONTE (distinct source_doc_id) — mesmo padrão do SourceDocSearchController.
        $total = (clone $base())->distinct()->count('source_doc_id');
        $docIds = (clone $base())->select('source_doc_id')->distinct()
            ->orderBy('source_doc_id')->forPage($page, $perPage)->pluck('source_doc_id')->all();
        $lastPage = (int) max(1, ceil(($total ?: 0) / $perPage));

        if (empty($docIds)) {
            return [$this->pagination($page, $perPage, $total, $lastPage), []];
        }

        // ocorrências da página
        $rows = (clone $base())->whereIn('source_doc_id', $docIds)
            ->orderBy('customer_id')->orderBy('source_doc_id')->orderBy('name')
            ->get(['source_doc_id', 'customer_id', 'entity_type', 'name', 'parent', 'access', 'risk_flags', 'line_start', 'line_end']);

        // meta das fontes + clientes (sem N+1: 2 queries batch)
        $docs = SourceDoc::whereIn('id', $docIds)
            ->with('customer:id,name')
            ->get(['id', 'filename', 'path', 'owner', 'repository', 'branch', 'customer_id'])
            ->keyBy('id');

        // monta árvore cliente → fonte → ocorrências
        $byCustomer = [];
        foreach ($rows as $r) {
            $doc = $docs->get($r->source_doc_id);
            $cid = $r->customer_id;
            $byCustomer[$cid] ??= [
                'customer' => $doc?->customer ? ['id' => $doc->customer->id, 'name' => $doc->customer->name] : ['id' => $cid, 'name' => null],
                'sources'  => [],
            ];
            $sid = $r->source_doc_id;
            $byCustomer[$cid]['sources'][$sid] ??= [
                'source_doc' => $doc ? [
                    'id' => $doc->id, 'filename' => $doc->filename, 'path' => $doc->path,
                    'owner' => $doc->owner, 'repository' => $doc->repository, 'branch' => $doc->branch,
                ] : ['id' => $sid],
                'occurrences' => [],
            ];
            $byCustomer[$cid]['sources'][$sid]['occurrences'][] = $this->normalize($entity, $r);
        }

        // reindexa em listas
        $data = [];
        foreach ($byCustomer as $c) {
            $c['sources'] = array_values($c['sources']);
            $data[] = $c;
        }

        return [$this->pagination($page, $perPage, $total, $lastPage), $data];
    }

    /** Normaliza a ocorrência por tipo (evidência honesta: só o que o índice tem). */
    private function normalize(string $entity, SourceDocEntity $r): array
    {
        $access = (array) $r->access;
        $kind = null;
        if ($access) {
            $isR = (bool) array_intersect($access, self::READ_OPS);
            $isW = (bool) array_intersect($access, self::WRITE_OPS);
            $kind = $isW ? ($isR ? 'both' : 'write') : ($isR ? 'read' : null);
        }

        $occ = [
            'name'        => $r->name,
            'access'      => $access ?: null,
            'kind'        => $kind,
            'line_start'  => $r->line_start,
            'line_end'    => $r->line_end,
            'risk_flags'  => ! empty($r->risk_flags) ? array_values((array) $r->risk_flags) : null,
        ];

        // contexto/função conforme o tipo:
        //  field      → parent = tabela (sem função/linha)
        //  query      → parent = função (com linha)
        //  function   → parent = tipo (definição, com linha)
        //  dependency → parent = função CALLER
        //  table      → sem parent
        $occ['context']  = $r->parent;
        $occ['function'] = in_array($entity, ['query', 'dependency', 'function'], true) ? $r->parent : null;

        // integration: NUNCA devolver o valor bruto — projeção segura backend.
        if ($entity === 'integration') {
            $occ = array_merge($occ, ['name' => null], $this->sanitizeIntegration($r->name));
        }

        return $occ;
    }

    // ── sanitização BACKEND (obrigatória antes da serialização) ───────────────

    /**
     * Projeta uma integração para representação SEGURA: só scheme + host (IP interno mascarado),
     * com flags has_path/has_credential. NUNCA retorna URL/path/query/userinfo/token/segredo.
     */
    public function sanitizeIntegration(string $raw): array
    {
        $p = @parse_url($raw);

        // não é URL (ex.: "MsExecAuto", "FWRest"): devolve só se for token técnico seguro.
        if ($p === false || empty($p['host'])) {
            $safe = preg_match('/^[A-Za-z0-9_.\-]{1,60}$/', $raw) === 1
                && preg_match('/(token|secret|senha|password|key|oauth|pwd|pass)/i', $raw) !== 1;
            return $safe
                ? ['integration' => ['kind' => 'label', 'value' => $raw]]
                : ['integration' => ['kind' => 'redacted']];
        }

        $host = $p['host'];
        $sensitiveHost = $this->isPrivateHost($host);
        $pathQuery = ($p['path'] ?? '') . '?' . ($p['query'] ?? '');
        $hasCredential = isset($p['user']) || isset($p['pass'])
            || preg_match('/(token|oauth|secret|apikey|api_key|access[_-]?key|password|senha|credential)/i', $pathQuery) === 1;

        return ['integration' => [
            'kind'           => 'endpoint',
            'scheme'         => $p['scheme'] ?? null,
            'host'           => $sensitiveHost ? 'rede-interna' : $host,
            'has_path'       => ! empty($p['path']) && $p['path'] !== '/',
            'has_credential' => $hasCredential,
            // NUNCA: path, query, fragment, user, pass, url bruta.
        ]];
    }

    /** IP privado/loopback/link-local ou host sem ponto (interno). */
    private function isPrivateHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        // hostname sem domínio público (sem ponto) tende a ser interno.
        return ! str_contains($host, '.');
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function pagination(int $page, int $perPage, int $total, int $lastPage): array
    {
        return [
            'current_page'  => $page,
            'per_page'      => $perPage,
            'total_sources' => $total,
            'last_page'     => $lastPage,
        ];
    }

    private function emptyResult(string $entity, string $name, ?string $table, string $access, int $page, int $perPage): array
    {
        return [
            'summary'    => ['clientes' => 0, 'fontes' => 0, 'ocorrencias' => 0, 'contextos' => 0, 'leitores' => 0, 'escritores' => 0, 'risk_flags' => []],
            'data'       => [],
            'pagination' => $this->pagination($page, $perPage, 0, 1),
            'query'      => ['entity' => $entity, 'name' => $name, 'table' => $table, 'access' => $access, 'cross' => false],
            'notice'     => null,
        ];
    }
}
