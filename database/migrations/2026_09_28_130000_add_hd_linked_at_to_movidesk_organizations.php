<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca QUANDO a organização foi vinculada ao Help Desk. O import só traz chamados
 * CRIADOS a partir desse momento — "importa a partir do vínculo apenas" (nada de histórico).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movidesk_organizations', function (Blueprint $t) {
            if (!Schema::hasColumn('movidesk_organizations', 'hd_linked_at')) {
                $t->timestamp('hd_linked_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('movidesk_organizations', function (Blueprint $t) {
            $t->dropColumn('hd_linked_at');
        });
    }
};
