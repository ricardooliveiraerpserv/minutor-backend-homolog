<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de tenants (landlord). Vive SOMENTE no schema `public` — não deve ser
 * criado dentro de um schema de tenant. Por isso o guard por current_schema().
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->currentSchema() !== 'public') {
            return; // rodando dentro de um tenant → não cria o registry lá
        }
        if (Schema::hasTable('tenants')) {
            return;
        }

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();     // usado pelo X-Tenant / subdomínio
            $table->string('schema')->unique();   // schema Postgres do tenant
            $table->string('domain')->nullable(); // ex.: conecta.minutor.com.br
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if ($this->currentSchema() !== 'public') {
            return;
        }
        Schema::dropIfExists('tenants');
    }

    protected function currentSchema(): string
    {
        return (string) DB::selectOne('select current_schema() as s')->s;
    }
};
