<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixDatabaseCharset extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:fix-charset {--truncate : Truncar tabela movidesk_tickets após conversão}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Corrige o charset do banco de dados e tabelas para utf8mb4';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔧 Verificando configuração atual do banco de dados...');

        // Verificar charset do banco de dados
        $database = config('database.connections.mysql.database');
        $dbCharset = DB::select("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
                                 FROM information_schema.SCHEMATA
                                 WHERE SCHEMA_NAME = ?", [$database]);

        if (!empty($dbCharset)) {
            $this->line("📊 Banco atual: {$database}");
            $this->line("   Charset: {$dbCharset[0]->DEFAULT_CHARACTER_SET_NAME}");
            $this->line("   Collation: {$dbCharset[0]->DEFAULT_COLLATION_NAME}");
        }

        // Converter banco de dados
        $this->info("\n🔄 Convertendo banco de dados para utf8mb4...");
        try {
            DB::statement("ALTER DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->info("✅ Banco de dados convertido com sucesso!");
        } catch (\Exception $e) {
            $this->error("❌ Erro ao converter banco: {$e->getMessage()}");
            return 1;
        }

        // Verificar e converter tabela movidesk_tickets
        $this->info("\n🔄 Verificando tabela movidesk_tickets...");

        $tableCharset = DB::select("SELECT TABLE_COLLATION
                                    FROM information_schema.TABLES
                                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'movidesk_tickets'", [$database]);

        if (!empty($tableCharset)) {
            $this->line("   Collation atual: {$tableCharset[0]->TABLE_COLLATION}");
        }

        // Converter tabela
        try {
            DB::statement('ALTER TABLE movidesk_tickets CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $this->info("✅ Tabela movidesk_tickets convertida!");
        } catch (\Exception $e) {
            $this->error("❌ Erro ao converter tabela: {$e->getMessage()}");
            return 1;
        }

        // Converter colunas individualmente
        $this->info("\n🔄 Convertendo colunas...");
        $stringColumns = ['ticket_id', 'categoria', 'urgencia', 'nivel', 'servico', 'titulo', 'status'];

        foreach ($stringColumns as $column) {
            try {
                DB::statement("ALTER TABLE movidesk_tickets
                              MODIFY COLUMN {$column} VARCHAR(255)
                              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $this->line("   ✅ Coluna {$column} convertida");
            } catch (\Exception $e) {
                $this->warn("   ⚠️  Aviso na coluna {$column}: {$e->getMessage()}");
            }
        }

        // Truncar tabela se solicitado
        if ($this->option('truncate')) {
            $this->warn("\n⚠️  ATENÇÃO: Você está prestes a TRUNCAR a tabela movidesk_tickets!");
            if ($this->confirm('Deseja continuar?', false)) {
                try {
                    DB::table('movidesk_tickets')->truncate();
                    $this->info("✅ Tabela movidesk_tickets truncada. Os dados podem ser reinseridos agora.");
                } catch (\Exception $e) {
                    $this->error("❌ Erro ao truncar tabela: {$e->getMessage()}");
                    return 1;
                }
            } else {
                $this->info("ℹ️  Truncate cancelado.");
            }
        } else {
            $this->warn("\n⚠️  IMPORTANTE: Os dados existentes ainda podem estar corrompidos!");
            $this->warn("   Para limpar os dados corrompidos, execute:");
            $this->line("   php artisan db:fix-charset --truncate");
        }

        $this->info("\n✨ Processo concluído!");
        $this->info("\n📝 Próximos passos:");
        $this->line("   1. Reinicie o container do banco: docker compose restart db");
        $this->line("   2. Limpe o cache do Laravel: php artisan config:clear");
        $this->line("   3. Reinsira os dados via webhook ou manualmente");

        return 0;
    }
}
