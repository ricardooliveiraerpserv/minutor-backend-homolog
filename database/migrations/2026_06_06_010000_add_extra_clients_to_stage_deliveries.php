<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('stage_deliveries', 'extra_clients')) {
                // Clientes adicionais envolvidos na atividade (além do client_user_id/client_email
                // primário). Cada item: { user_id?: int, email?: string, name?: string }.
                $table->jsonb('extra_clients')->nullable()->after('client_involved');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('stage_deliveries', 'extra_clients')) {
                $table->dropColumn('extra_clients');
            }
        });
    }
};
