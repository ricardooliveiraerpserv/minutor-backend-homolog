<?php

namespace App\Multitenancy;

use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

/**
 * Troca o tenant ativo mudando o `search_path` do Postgres (schema-per-tenant, MESMO banco).
 * makeCurrent → search_path = "<schema>, public"  (tabelas do tenant vencem; extensões/tipos
 * compartilhados no public seguem acessíveis). forgetCurrent → volta para "public" (grupo).
 *
 * spatie chama makeCurrent no início do request/job do tenant e forgetCurrent ao final —
 * cobre HTTP, queue e scheduler (queues_are_tenant_aware_by_default = true).
 */
class SwitchTenantSchemaTask implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        $schema = $this->safeSchema((string) $tenant->schema);
        $this->setSearchPath("{$schema}, public");
    }

    public function forgetCurrent(): void
    {
        $this->setSearchPath('public');
    }

    /** Nome de schema NUNCA vem de input do usuário, mas validamos (não dá pra bindar identifier em SQL). */
    protected function safeSchema(string $schema): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $schema)) {
            throw new \InvalidArgumentException("Nome de schema inválido: {$schema}");
        }

        return $schema;
    }

    protected function setSearchPath(string $path): void
    {
        DB::statement("set search_path to {$path}");
    }
}
