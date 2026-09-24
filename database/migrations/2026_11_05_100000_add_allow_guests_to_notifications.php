<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Confirmar presença": permite que o convidado leve acompanhantes/familiares.
// Quando true, ao confirmar presença o app pergunta os dependentes (nome + parentesco).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications_center', 'allow_guests')) {
                $table->boolean('allow_guests')->default(false)->after('actions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            if (Schema::hasColumn('notifications_center', 'allow_guests')) {
                $table->dropColumn('allow_guests');
            }
        });
    }
};
