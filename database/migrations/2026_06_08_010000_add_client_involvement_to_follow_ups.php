<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envolvimento de cliente no Follow Up (espelha stage_deliveries): "quem participa".
 * Regra: se NÃO houver cliente envolvido, nenhum cliente vê os comentários/anexos.
 * Só o cliente envolvido (client_user_id) enxerga a timeline e os anexos.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            if (!Schema::hasColumn('follow_ups', 'client_involved')) {
                $table->boolean('client_involved')->default(false)->after('requester_user_id');
            }
            if (!Schema::hasColumn('follow_ups', 'client_user_id')) {
                $table->foreignId('client_user_id')->nullable()->after('client_involved')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('follow_ups', 'client_email')) {
                $table->string('client_email', 180)->nullable()->after('client_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            if (Schema::hasColumn('follow_ups', 'client_user_id')) $table->dropConstrainedForeignId('client_user_id');
            foreach (['client_involved', 'client_email'] as $c) {
                if (Schema::hasColumn('follow_ups', $c)) $table->dropColumn($c);
            }
        });
    }
};
