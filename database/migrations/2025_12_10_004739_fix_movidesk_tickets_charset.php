<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Corrige o charset da tabela movidesk_tickets para utf8mb4
     * e converte as colunas de string para suportar caracteres especiais e acentos
     */
    public function up(): void
    {
        // Verificar se a tabela existe
        if (Schema::hasTable('movidesk_tickets')) {
            // SQLite não suporta alteração de charset, então só executar para MySQL
            $driver = DB::connection()->getDriverName();
            
            if ($driver === 'mysql') {
                // Converter a tabela inteira para utf8mb4
                DB::statement('ALTER TABLE movidesk_tickets CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

                // Converter cada coluna string individualmente para garantir utf8mb4
                $stringColumns = ['ticket_id', 'categoria', 'urgencia', 'nivel', 'servico', 'titulo', 'status'];

                foreach ($stringColumns as $column) {
                    if (Schema::hasColumn('movidesk_tickets', $column)) {
                        DB::statement("ALTER TABLE movidesk_tickets MODIFY COLUMN {$column} VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    }
                }
            }
            // Para SQLite, não é necessário fazer nada pois já suporta UTF-8 por padrão
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Não há necessidade de reverter, mas podemos deixar vazio
    }
};
