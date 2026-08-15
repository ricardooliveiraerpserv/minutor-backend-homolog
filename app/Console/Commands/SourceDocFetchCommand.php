<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\SourceCode\GithubAppAuth;
use Illuminate\Console\Command;

/** Baixa o conteúdo cru de um fonte (base64 em system_settings 'diag_raw') p/ inspeção/validação. */
class SourceDocFetchCommand extends Command
{
    protected $signature = 'source-doc:fetch {owner} {repo} {path} {--branch=main}';
    protected $description = 'Baixa um fonte específico (GitHub App, read-only) para system_settings diag_raw.';

    public function handle(GithubAppAuth $auth): int
    {
        $code = $auth->getFileContent($this->argument('owner'), $this->argument('repo'), (string) $this->option('branch'), $this->argument('path'));
        if ($code === null) {
            $this->error('não consegui ler');
            return self::FAILURE;
        }
        SystemSetting::set('diag_raw', base64_encode($code), 'string', 'diag');
        $this->info('bytes: ' . strlen($code));
        return self::SUCCESS;
    }
}
