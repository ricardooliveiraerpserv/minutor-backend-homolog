<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Canal de comentários NATIVO do projeto: mensagens keyadas por project_id
        // (projetos que não vieram de Demanda não têm contract_request_id).
        if (!Schema::hasColumn('contract_request_messages', 'project_id')) {
            Schema::table('contract_request_messages', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('contract_request_id')
                    ->constrained('projects')->nullOnDelete();
                $table->index('project_id');
            });
        }
        // contract_request_id passa a ser opcional (mensagem pode ser só de projeto).
        DB::statement('ALTER TABLE contract_request_messages ALTER COLUMN contract_request_id DROP NOT NULL');
    }

    public function down(): void
    {
        if (Schema::hasColumn('contract_request_messages', 'project_id')) {
            Schema::table('contract_request_messages', function (Blueprint $table) {
                $table->dropConstrainedForeignId('project_id');
            });
        }
    }
};
