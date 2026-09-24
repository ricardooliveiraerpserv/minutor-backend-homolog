<?php

namespace App\Console\Commands;

use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\Models\SystemSetting;
use App\SourceCode\GithubAppAuth;
use App\SourceCode\SourceDocRenderer;
use App\SourceCode\SourceDocStatusResolver;
use Illuminate\Console\Command;

/**
 * Fase 4 — renderiza o documentation_json de uma doc/versão (Markdown/HTML/docx/PDF) e,
 * opcionalmente, EXPORTA a doc ao Git (--export-git), gravando documentation_commit_sha
 * (que NUNCA substitui o source_commit_sha). Detecta doc desatualizada (HEAD ≠ current sha).
 *
 *   php artisan source-doc:render --doc=1 --format=md
 *   php artisan source-doc:render --ver=6 --format=docx
 *   php artisan source-doc:render --doc=1 --export-git --git-dir=docs
 *
 * Obs.: a opção é --ver (não --version, reservada pelo Symfony Console).
 */
class SourceDocRenderCommand extends Command
{
    protected $signature = 'source-doc:render {--doc=} {--ver=} {--format=md} {--export-git} {--git-dir=docs} {--dump}';
    protected $description = 'Fase 4: renderiza (md/html/docx/pdf) e opcionalmente exporta a doc ao Git.';

    public function handle(SourceDocRenderer $renderer, GithubAppAuth $auth, SourceDocStatusResolver $resolver): int
    {
        $ver = $this->option('ver')
            ? SourceDocVersion::find((int) $this->option('ver'))
            : optional(SourceDoc::find((int) $this->option('doc')))->currentVersion;
        if (!$ver) {
            $this->error('Doc/versão não encontrada.');
            return self::FAILURE;
        }
        $doc = $ver->doc;
        $json = $ver->documentation_json ?: $doc->documentation_json;
        if (empty($json)) {
            $this->error('Sem documentation_json (rode a análise/reprocess primeiro).');
            return self::FAILURE;
        }

        // Bloco 3 — PONTO ÚNICO DE VERDADE: só o resolver decide "desatualizada" (blob × blob).
        // 'outdated' é ESTRITAMENTE o estado DESATUALIZADA; NAO_VALIDADO/erro técnico NÃO marca aviso.
        $st = $resolver->resolve($doc);
        $outdated = $st['status'] === SourceDocStatusResolver::STATUS_OUTDATED;
        $customer = $doc->customer ? ['name' => $doc->customer->name] : [];

        // Bloco 5 — histórico completo (todas as versões) + status do resolver, para o renderer
        // APENAS apresentar (não recalcula nada). Retrocompatível: renderer tolera context vazio.
        $versions = $doc->versions()->orderByDesc('created_at')->orderByDesc('id')->get()->map(function (SourceDocVersion $vv) {
            $ds = (array) ($vv->diff_stats ?? []);
            $sem = (array) ($vv->semantic_json ?? []);
            return [
                'created_at'        => (string) $vv->created_at,
                'ticket_number'     => $vv->ticket_number,
                'responsavel'       => $vv->responsavel,
                'source_commit_sha' => $vv->source_commit_sha,
                'source_blob_sha'   => $vv->source_blob_sha,
                'analysis_status'   => $vv->analysis_status,
                'structural_change' => $ds['structural_change'] ?? null,
                'resumo'            => $sem['resumo_alteracao'] ?? null,
            ];
        })->all();
        $context = ['status' => $st, 'versions' => $versions];

        if ($this->option('export-git')) {
            $md = $renderer->markdown($json, $outdated, $customer, $context);
            $path = trim((string) $this->option('git-dir'), '/') . '/' . $doc->path . '.md';
            $sha = $auth->commitFiles($doc->owner, $doc->repository, $doc->branch, [$path => $md],
                "docs: {$doc->path} (GMUD " . ($ver->ticket_number ?: '-') . ")");
            $ver->documentation_commit_sha = $sha;
            $ver->save();
            $this->info("Exportado: {$doc->owner}/{$doc->repository}@{$doc->branch}:{$path} · documentation_commit_sha=" . substr($sha, 0, 8));
            return self::SUCCESS;
        }

        $fmt = (string) $this->option('format');
        // --dump: devolve os bytes (base64) do binário em system_settings 'diag_render_bytes'.
        if ($this->option('dump') && in_array($fmt, ['docx', 'pdf'], true)) {
            $bytes = $fmt === 'docx' ? $renderer->docx($json, $outdated, $customer, $context) : $renderer->pdf($json, $outdated, $customer, $context);
            SystemSetting::set('diag_render_bytes', json_encode(['format' => $fmt, 'b64' => base64_encode($bytes)], JSON_UNESCAPED_UNICODE), 'string', 'diag');
            $this->info("dump {$fmt}: " . strlen($bytes) . ' bytes → diag_render_bytes');
            return self::SUCCESS;
        }
        $out = match ($fmt) {
            'html'  => $renderer->html($json, $outdated, $customer, $context),
            'docx'  => 'docx=' . strlen($renderer->docx($json, $outdated, $customer, $context)) . ' bytes',
            'pdf'   => 'pdf=' . strlen($renderer->pdf($json, $outdated, $customer, $context)) . ' bytes',
            default => $renderer->markdown($json, $outdated, $customer, $context),
        };
        SystemSetting::set('diag_render', json_encode([
            'outdated' => $outdated, 'format' => $fmt,
            'resolver' => [
                'status' => $st['status'], 'reason' => $st['reason'],
                'documented_blob_sha' => $st['documented_blob_sha'], 'current_blob_sha' => $st['current_blob_sha'],
                'source_commit_sha' => $st['source_commit_sha'],
            ],
            'output' => $out,
        ], JSON_UNESCAPED_UNICODE), 'string', 'diag');
        $this->line($out);
        return self::SUCCESS;
    }
}
