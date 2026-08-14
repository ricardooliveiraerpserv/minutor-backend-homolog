<?php

namespace App\Console\Commands;

use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\Models\SystemSetting;
use App\SourceCode\GithubAppAuth;
use App\SourceCode\SourceDocRenderer;
use Illuminate\Console\Command;

/**
 * Fase 4 — renderiza o documentation_json de uma doc/versão (Markdown/HTML/docx/PDF) e,
 * opcionalmente, EXPORTA a doc ao Git (--export-git), gravando documentation_commit_sha
 * (que NUNCA substitui o source_commit_sha). Detecta doc desatualizada (HEAD ≠ current sha).
 *
 *   php artisan source-doc:render --doc=1 --format=md
 *   php artisan source-doc:render --version=6 --format=docx
 *   php artisan source-doc:render --doc=1 --export-git --git-dir=docs
 */
class SourceDocRenderCommand extends Command
{
    protected $signature = 'source-doc:render {--doc=} {--version=} {--format=md} {--export-git} {--git-dir=docs}';
    protected $description = 'Fase 4: renderiza (md/html/docx/pdf) e opcionalmente exporta a doc ao Git.';

    public function handle(SourceDocRenderer $renderer, GithubAppAuth $auth): int
    {
        try {
            return $this->doRender($renderer, $auth);
        } catch (\Throwable $e) {
            \App\Models\SystemSetting::set('diag_render', json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]), 'string', 'diag');
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function doRender(SourceDocRenderer $renderer, GithubAppAuth $auth): int
    {
        $ver = $this->option('version')
            ? SourceDocVersion::find((int) $this->option('version'))
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

        $head = $auth->getBranchHeadSha($doc->owner, $doc->repository, $doc->branch);
        $outdated = $head && $ver->source_commit_sha && $head !== $ver->source_commit_sha;
        $customer = $doc->customer ? ['name' => $doc->customer->name] : [];

        if ($this->option('export-git')) {
            $md = $renderer->markdown($json, $outdated, $customer);
            $path = trim((string) $this->option('git-dir'), '/') . '/' . $doc->path . '.md';
            $sha = $auth->commitFiles($doc->owner, $doc->repository, $doc->branch, [$path => $md],
                "docs: {$doc->path} (GMUD " . ($ver->ticket_number ?: '-') . ")");
            $ver->documentation_commit_sha = $sha;
            $ver->save();
            $this->info("Exportado: {$doc->owner}/{$doc->repository}@{$doc->branch}:{$path} · documentation_commit_sha=" . substr($sha, 0, 8));
            return self::SUCCESS;
        }

        $fmt = (string) $this->option('format');
        $out = match ($fmt) {
            'html'  => $renderer->html($json, $outdated, $customer),
            'docx'  => 'docx=' . strlen($renderer->docx($json, $outdated, $customer)) . ' bytes',
            'pdf'   => 'pdf=' . strlen($renderer->pdf($json, $outdated, $customer)) . ' bytes',
            default => $renderer->markdown($json, $outdated, $customer),
        };
        SystemSetting::set('diag_render', json_encode(['outdated' => $outdated, 'format' => $fmt, 'output' => $out], JSON_UNESCAPED_UNICODE), 'string', 'diag');
        $this->line($out);
        return self::SUCCESS;
    }
}
