<?php

namespace App\Prosight;

use App\Models\EnvEnvironment;
use App\Models\ProsightRpoConfig;
use App\SourceCode\GithubAppAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Inventário RPO — comparação Git × RPO por ambiente. Porta fiel do inventory-core.js do ProSight
 * enviado: a "última alteração" do fonte = data do ÚLTIMO COMMIT no Git (git log %cI); o RPO grava
 * a data da última alteração/compilação. Status: sincronizado / recompilar / verificar_rpo /
 * nao_compilado / so_rpo. Exclusões por padrão (curinga *). Resumo com % de saúde.
 */
class RpoInventoryService
{
    private const SOURCE_EXTENSIONS = ['prw', 'prg', 'tlpp', 'ch', 'aph'];
    private const SYNC_THRESHOLD_MS = 60 * 1000; // 60s (idêntico ao enviado)

    public function __construct(private GithubAppAuth $github)
    {
    }

    /**
     * @return array{ok:bool, error?:string, scanned_at?:string, git?:array, rpo?:array, summary?:array, results?:array}
     */
    public function scan(EnvEnvironment $env, array $repos): array
    {
        $cfg = ProsightRpoConfig::where('environment_id', $env->id)->first();
        if (! $cfg || ! $cfg->rpo_api_url) {
            return ['ok' => false, 'error' => 'RPO não configurado. Preencha a Integração RPO (REST AdvPL).'];
        }
        if (empty($repos)) {
            return ['ok' => false, 'error' => 'Nenhum repositório Git ativo para esta empresa.'];
        }

        // 1) Fontes do Git (nome do programa → data do último commit).
        $diskDates = [];  // PROGRAM(upper) => timestamp(ms)
        $gitMeta = [];
        foreach ($repos as $repo) {
            try {
                $found = $this->gitProgramDates((string) $repo->owner, (string) $repo->repository, (string) ($repo->branch ?: 'main'), (string) ($repo->base_path ?? ''));
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Erro ao obter fontes do Git ('.$repo->owner.'/'.$repo->repository.'): '.mb_substr($e->getMessage(), 0, 180)];
            }
            foreach ($found as $prog => $ms) {
                // Se o mesmo programa aparece em 2 repos, mantém a data mais nova.
                if (! isset($diskDates[$prog]) || $ms > $diskDates[$prog]) {
                    $diskDates[$prog] = $ms;
                }
            }
            $gitMeta[] = ['owner' => $repo->owner, 'repository' => $repo->repository, 'branch' => $repo->branch ?: 'main', 'files' => count($found)];
        }

        // 2) RPO via REST AdvPL — envia os nomes dos programas do Git.
        $programNames = array_keys($diskDates);
        try {
            $rpoRecords = $this->fetchRpoFromAdvPL($cfg, $programNames);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Erro ao consultar endpoint AdvPL: '.mb_substr($e->getMessage(), 0, 180)];
        }

        // 3) Comparar + 4) exclusões + 5) resumo.
        $results = $this->compare($rpoRecords, $diskDates);
        $results = $this->applyExclusions($results, (string) ($cfg->rpo_exclusion_patterns ?? ''));
        $summary = $this->buildSummary($results);

        return [
            'ok' => true,
            'scanned_at' => now()->toIso8601String(),
            'git' => $gitMeta,
            'rpo' => ['url' => $cfg->rpo_api_url, 'count' => count($rpoRecords)],
            'summary' => $summary,
            'results' => $results,
        ];
    }

    // ── Git: data do último commit por arquivo (blobless clone + git log) ──────────
    /** @return array<string,int> PROGRAM(upper) => timestamp(ms) */
    private function gitProgramDates(string $owner, string $repo, string $branch, string $basePath): array
    {
        $token = $this->github->installationToken($owner);
        $url = "https://x-access-token:{$token}@github.com/{$owner}/{$repo}.git";
        $dir = sys_get_temp_dir().'/rpo-scan-'.bin2hex(random_bytes(6));

        try {
            // Clone COMPLETO (NÃO shallow / NÃO blobless) — igual ao ProSight enviado. Com clone parcial/
            // shallow o `git log` carimba a data do HEAD em todos os arquivos (import em massa) → tudo vira
            // "recompilar". O histórico completo é necessário p/ a data REAL do último commit por arquivo.
            $clone = new Process(['git', 'clone', '--single-branch', '--branch', $branch, $url, $dir]);
            $clone->setTimeout(300)->run();
            if (! $clone->isSuccessful()) {
                throw new \RuntimeException($this->scrub($clone->getErrorOutput() ?: 'git clone falhou', $token));
            }

            // Mapa data por CAMINHO: git log newest-first. Usa AUTHOR date (%aI) — num import em massa o
            // committer date vira a data do import, mas a author date preserva a data ORIGINAL do fonte.
            $log = new Process(['git', '-C', $dir, 'log', '--no-merges', '--name-only', '--date=iso-strict', '--pretty=format:@%aI'], null, null, null, 240);
            $log->run();
            if (! $log->isSuccessful()) {
                throw new \RuntimeException($this->scrub($log->getErrorOutput() ?: 'git log falhou', $token));
            }
            $dateByPath = [];  // relpath => timestamp(ms)
            $curMs = null;
            foreach (explode("\n", $log->getOutput()) as $line) {
                if ($line === '') {
                    continue;
                }
                if ($line[0] === '@') {
                    $ts = strtotime(substr($line, 1));
                    $curMs = $ts !== false ? $ts * 1000 : null;
                    continue;
                }
                if ($curMs !== null && ! isset($dateByPath[$line])) {
                    $dateByPath[$line] = $curMs;   // 1ª (mais nova) por caminho
                }
            }

            // Varre o DISCO (só arquivos que EXISTEM) sob base_path, casando a data pelo caminho relativo.
            $base = $basePath !== '' ? $dir.'/'.trim($basePath, '/') : $dir;
            $out = [];  // PROGRAM(upper) => timestamp(ms) mais novo
            if (is_dir($base)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if (! $file->isFile()) {
                        continue;
                    }
                    $ext = strtolower($file->getExtension());
                    if (! in_array($ext, self::SOURCE_EXTENSIONS, true)) {
                        continue;
                    }
                    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                    $ms = $dateByPath[$rel] ?? ($file->getMTime() * 1000);
                    $prog = strtoupper($file->getBasename('.'.$file->getExtension()));
                    if ($prog !== '' && (! isset($out[$prog]) || $ms > $out[$prog])) {
                        $out[$prog] = $ms;
                    }
                }
            }

            return $out;
        } finally {
            if (is_dir($dir)) {
                (new Process(['rm', '-rf', $dir]))->run();
            }
        }
    }

    // ── RPO: POST {programs} + Basic auth → [{program,date,status,rpo}] ────────────
    private function fetchRpoFromAdvPL(ProsightRpoConfig $cfg, array $programs): array
    {
        $client = Http::timeout(30)->acceptJson()->asJson();
        if ($cfg->allow_insecure_tls) {
            $client = $client->withoutVerifying();
        }
        if ($cfg->rpo_api_user && $cfg->rpo_api_password) {
            $client = $client->withBasicAuth($cfg->rpo_api_user, $cfg->rpo_api_password);
        }
        $resp = $client->post($cfg->rpo_api_url, ['programs' => array_values($programs)]);

        $body = ltrim($resp->body());
        if ($body !== '' && $body[0] === '<') {
            throw new \RuntimeException("endpoint retornou HTML (HTTP {$resp->status()}) — verifique usuário e senha");
        }
        $json = $resp->json();
        if (! is_array($json)) {
            throw new \RuntimeException("resposta não é um array JSON (HTTP {$resp->status()})");
        }

        return array_map(fn ($r) => [
            'program' => strtoupper((string) ($r['program'] ?? '')),
            'date'    => $r['date'] ?? null,
            'status'  => $r['status'] ?? '',
            'rpo'     => $r['rpo'] ?? '',
        ], $json);
    }

    // ── Comparação (idêntica ao inventory-core.js) ────────────────────────────────
    /**
     * Data do RPO → timestamp ms. O RPO grava a data em horário de BRASÍLIA (sem timezone) →
     * interpreta como America/Sao_Paulo (o disco/git é UTC). Sem isso há offset de ~3h e nada
     * fica "sincronizado". Se a string já vier com timezone, ela é respeitada.
     */
    private function parseRpoDateMs(string $s): ?int
    {
        try {
            return (int) (\Illuminate\Support\Carbon::parse(trim($s), 'America/Sao_Paulo')->getTimestamp() * 1000);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,int> $diskDates */
    private function compare(array $rpoRecords, array $diskDates): array
    {
        $strip = fn (string $n) => strtoupper(preg_replace('/\.[^.]+$/', '', $n));

        $rpoMap = [];
        foreach ($rpoRecords as $r) {
            $rpoMap[$strip($r['program'])] = $r;
        }

        $keys = array_unique(array_merge(array_keys($rpoMap), array_keys($diskDates)));
        $results = [];
        foreach ($keys as $key) {
            $inRpo = isset($rpoMap[$key]);
            $inDisk = isset($diskDates[$key]);
            $rpoRec = $rpoMap[$key] ?? null;

            $rpoMs = ($inRpo && ! empty($rpoRec['date'])) ? $this->parseRpoDateMs((string) $rpoRec['date']) : null;
            $diskMs = $inDisk ? $diskDates[$key] : null;

            if ($inRpo && $inDisk) {
                $diff = (int) ($diskMs ?? 0) - (int) ($rpoMs ?? 0);
                if (abs($diff) <= self::SYNC_THRESHOLD_MS) {
                    $status = 'sincronizado';
                } elseif ($diff > self::SYNC_THRESHOLD_MS) {
                    $status = 'recompilar';       // fonte mais novo → falta compilar
                } else {
                    $status = 'verificar_rpo';    // RPO mais novo que o fonte
                }
            } elseif ($inDisk && ! $inRpo) {
                $status = 'nao_compilado';
            } else {
                $status = 'so_rpo';
            }

            $results[] = [
                'program'   => $key,
                'disk_date' => $diskMs !== null ? Carbon::createFromTimestampMs($diskMs)->toIso8601String() : null,
                'rpo_date'  => $inRpo ? ($rpoRec['date'] ?? null) : null,
                'rpo_status' => $inRpo ? ($rpoRec['status'] ?? null) : null,
                'rpo_type'  => $inRpo ? ($rpoRec['rpo'] ?? null) : null,
                'status'    => $status,
                'is_rest_api' => (bool) preg_match('/^(WS|SERVICE|REST|API)/i', $key),
            ];
        }

        // Ordena por status (mais crítico primeiro) e nome.
        $order = ['recompilar' => 0, 'verificar_rpo' => 1, 'nao_compilado' => 2, 'so_rpo' => 3, 'sincronizado' => 4];
        usort($results, fn ($a, $b) => [$order[$a['status']] ?? 9, $a['program']] <=> [$order[$b['status']] ?? 9, $b['program']]);

        return $results;
    }

    private function applyExclusions(array $results, string $exclusionRaw): array
    {
        $raw = trim($exclusionRaw);
        if ($raw === '') {
            return $results;
        }
        $patterns = array_filter(array_map(fn ($p) => strtoupper(trim($p)), explode(',', $raw)));
        if (empty($patterns)) {
            return $results;
        }
        $matches = function (string $name) use ($patterns): bool {
            $n = strtoupper($name);
            foreach ($patterns as $pat) {
                if (str_contains($pat, '*')) {
                    $re = '/^'.str_replace('\*', '.*', preg_quote($pat, '/')).'$/';
                    if (preg_match($re, $n)) {
                        return true;
                    }
                } elseif ($n === $pat) {
                    return true;
                }
            }
            return false;
        };

        return array_values(array_filter($results, fn ($r) => ! $matches($r['program'])));
    }

    private function buildSummary(array $results): array
    {
        $counts = ['sincronizado' => 0, 'recompilar' => 0, 'verificar_rpo' => 0, 'nao_compilado' => 0, 'so_rpo' => 0];
        $restApi = 0;
        foreach ($results as $r) {
            if (isset($counts[$r['status']])) {
                $counts[$r['status']]++;
            }
            if ($r['is_rest_api']) {
                $restApi++;
            }
        }
        $total = count($results);
        $healthPct = $total > 0 ? round(($counts['sincronizado'] / $total) * 1000) / 10 : 0;
        if ($healthPct < 30) {
            $healthLabel = 'Crítico';
        } elseif ($healthPct < 60) {
            $healthLabel = 'Alerta';
        } elseif ($healthPct < 80) {
            $healthLabel = 'Regular';
        } else {
            $healthLabel = 'Saudável';
        }

        return ['counts' => $counts, 'total' => $total, 'health_pct' => $healthPct, 'health_label' => $healthLabel, 'rest_api_count' => $restApi];
    }

    private function scrub(string $msg, string $token): string
    {
        return str_replace($token, '***', $msg);
    }
}
