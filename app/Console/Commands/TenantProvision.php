<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Provisiona um tenant NOVO como um schema Postgres isolado no MESMO banco.
 *
 * Receita (validada): cria o schema → clona a ESTRUTURA de um schema conhecido-bom
 * (pg_dump --schema-only, default `public` = grupo) → carimba a tabela `migrations`
 * (deploys futuros aplicam só o que for novo) → copia os CADASTROS de config como
 * template inicial → cria o admin. NENHUM dado operacional do grupo é copiado.
 *
 * Ex.: php artisan tenant:provision conecta --name="CONECTA" \
 *        --domain=conecta.minutor.com.br --admin-name="Ricardo Oliveira" \
 *        --admin-email=ri83@hotmail.com
 */
class TenantProvision extends Command
{
    protected $signature = 'tenant:provision
        {slug : identificador/subdomínio do tenant (ex.: conecta)}
        {--name= : nome de exibição do tenant}
        {--domain= : domínio (ex.: conecta.minutor.com.br)}
        {--source-schema=public : schema de origem para clonar a estrutura}
        {--admin-name=Administrador : nome do admin inicial}
        {--admin-email= : e-mail do admin inicial (obrigatório)}
        {--admin-password= : senha do admin (default: gerada e exibida)}';

    protected $description = 'Provisiona um tenant novo (schema isolado + config + admin)';

    /** Tabelas de CONFIG copiadas como template inicial. NUNCA tabelas operacionais. */
    private const SEED_TABLES = [
        'service_types', 'contract_types', 'expense_categories', 'system_settings',
    ];

    public function handle(): int
    {
        $slug   = strtolower(trim((string) $this->argument('slug')));
        $schema = $slug;
        $source = (string) $this->option('source-schema');
        $email  = (string) $this->option('admin-email');

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
            $this->error("slug inválido: {$slug} (use [a-z][a-z0-9_]*)");
            return self::FAILURE;
        }
        if ($email === '') {
            $this->error('--admin-email é obrigatório.');
            return self::FAILURE;
        }
        if ($this->schemaExists($schema)) {
            $this->error("schema '{$schema}' já existe — abortando (provisionamento não é idempotente por segurança).");
            return self::FAILURE;
        }
        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("tenant '{$slug}' já registrado.");
            return self::FAILURE;
        }

        $password = (string) ($this->option('admin-password') ?: Str::password(14));

        // 1. Registro do tenant (schema public/landlord).
        $tenant = Tenant::create([
            'name'   => $this->option('name') ?: strtoupper($slug),
            'slug'   => $slug,
            'schema' => $schema,
            'domain' => $this->option('domain') ?: null,
            'status' => 'active',
        ]);
        $this->info("• tenant registrado (#{$tenant->id})");

        // 2. Cria o schema.
        DB::statement('create schema "' . $schema . '"');
        $this->info("• schema '{$schema}' criado");

        // 3. Clona a ESTRUTURA do schema fonte.
        $this->cloneStructure($source, $schema);
        $tables = $this->countTables($schema);
        $this->info("• estrutura clonada de '{$source}' → {$tables} tabelas");

        // 4. Carimba as migrations (mesmo estado da fonte).
        DB::statement("insert into \"{$schema}\".migrations (migration, batch) select migration, batch from \"{$source}\".migrations");
        $this->info('• migrations carimbadas');

        // 5. Copia os cadastros de config (template inicial).
        foreach (self::SEED_TABLES as $t) {
            if ($this->tableExists($source, $t) && $this->tableExists($schema, $t)) {
                DB::statement("insert into \"{$schema}\".{$t} select * from \"{$source}\".{$t}");
            }
        }
        $this->info('• cadastros de config copiados');

        // 6. Cria o admin DENTRO do tenant.
        $tenant->makeCurrent();
        try {
            $admin = new User();
            $admin->name  = (string) $this->option('admin-name');
            $admin->email = $email;
            $admin->password = Hash::make($password);
            $admin->type = 'admin';
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'enabled')) {
                $admin->enabled = true;
            }
            $admin->save();
        } finally {
            Tenant::forgetCurrent();
        }
        $this->info("• admin criado: {$email}");

        $this->newLine();
        $this->line("<info>✔ tenant '{$slug}' provisionado.</info>");
        $this->line("  domínio:  " . ($tenant->domain ?: '(defina o X-Tenant/subdomínio)'));
        $this->line("  admin:    {$email}");
        $this->line("  senha:    {$password}   <comment>(troque no 1º login)</comment>");

        return self::SUCCESS;
    }

    /** Clona a estrutura (schema-only) via pg_dump → transform em PHP → psql. */
    private function cloneStructure(string $source, string $target): void
    {
        $c = config('database.connections.pgsql');
        $env = ['PGPASSWORD' => (string) $c['password']];
        $dump = storage_path('app/tenant_struct_' . $target . '.sql');

        $args = [
            'pg_dump', '-h', (string) $c['host'], '-p', (string) $c['port'],
            '-U', (string) $c['username'], '-d', (string) $c['database'],
            '--schema-only', '--schema=' . $source, '--no-owner', '--no-privileges', '--no-comments',
            // O registry `tenants` vive SÓ no public (landlord); não clonar p/ o schema do tenant
            // (senão Tenant::query() sob tenant ativo resolveria a tabela vazia via search_path).
            '--exclude-table=' . $source . '.tenants',
            '-f', $dump,
        ];
        $r = Process::env($env)->timeout(600)->run($args);
        if (! $r->successful()) {
            throw new \RuntimeException('pg_dump falhou: ' . $r->errorOutput());
        }

        // Transform em PHP (portável — evita `\b` do sed BSD): requalifica source.→target.
        $sql = (string) file_get_contents($dump);
        $sql = preg_replace('/^.*transaction_timeout.*$/m', '', $sql);
        $sql = preg_replace('/^\s*(CREATE|ALTER|COMMENT ON|DROP)\s+SCHEMA\b.*$/mi', '', $sql);
        $sql = str_replace($source . '.', $target . '.', $sql);
        file_put_contents($dump, "SET search_path TO \"{$target}\", public;\n" . $sql);

        $load = [
            'psql', '-h', (string) $c['host'], '-p', (string) $c['port'],
            '-U', (string) $c['username'], '-d', (string) $c['database'],
            '-v', 'ON_ERROR_STOP=0', '-q', '-f', $dump,
        ];
        $r2 = Process::env($env)->timeout(600)->run($load);
        @unlink($dump);
        // Erros benignos (already exists / transaction_timeout) são tolerados; falha real vira exceção só se 0 tabelas.
        if ($this->countTables($target) === 0) {
            throw new \RuntimeException('clone gerou 0 tabelas: ' . $r2->errorOutput());
        }
    }

    private function schemaExists(string $s): bool
    {
        return DB::selectOne('select 1 from information_schema.schemata where schema_name = ?', [$s]) !== null;
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ?',
            [$schema, $table]
        ) !== null;
    }

    private function countTables(string $schema): int
    {
        return (int) DB::selectOne(
            "select count(*) c from information_schema.tables where table_schema = ? and table_type='BASE TABLE'",
            [$schema]
        )->c;
    }
}
