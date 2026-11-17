<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Emite (ou revoga) o link do conector MCP do Minutor p/ um ADMIN. */
class McpTokenCommand extends Command
{
    protected $signature = 'mcp:token {user : id ou e-mail do admin} {--revoke : revoga os links existentes}';
    protected $description = 'Gera/revoga o link do conector MCP (Claude) de um admin';

    public function handle(): int
    {
        $arg = (string) $this->argument('user');
        $u = ctype_digit($arg) ? User::find((int) $arg) : User::whereRaw('lower(email)=?', [mb_strtolower($arg)])->first();
        if (!$u || !$u->isAdmin()) { $this->error('Usuário não encontrado ou não é admin.'); return self::FAILURE; }
        $u->tokens()->where('name', 'mcp-connector')->delete();
        if ($this->option('revoke')) { $this->info("Links do conector revogados para {$u->name}."); return self::SUCCESS; }
        $plain = $u->createToken('mcp-connector', ['mcp:read'])->plainTextToken;
        $this->line(rtrim(config('app.url'), '/') . '/api/v1/mcp/' . rawurlencode($plain));
        return self::SUCCESS;
    }
}
