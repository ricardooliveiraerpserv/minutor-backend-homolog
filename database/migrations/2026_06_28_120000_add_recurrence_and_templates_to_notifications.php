<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reenvio + recorrência + modelos reutilizáveis para a Central de Notificações.
 *  - recurrence: none | every_days | day_of_month | business_day (+ recurrence_value = X)
 *  - resent_at: marca um reenvio (manual ou recorrente) → o pop-up reaparece
 *  - is_template/template_name: aviso salvo como MODELO (não vai pra ninguém; serve p/ reusar)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            $table->string('recurrence', 20)->default('none')->after('expires_at');
            $table->unsignedSmallInteger('recurrence_value')->nullable()->after('recurrence');
            $table->timestamp('last_fired_at')->nullable()->after('recurrence_value');
            $table->timestamp('resent_at')->nullable()->after('last_fired_at');
            $table->boolean('is_template')->default(false)->after('resent_at');
            $table->string('template_name', 120)->nullable()->after('is_template');
        });
    }

    public function down(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            $table->dropColumn(['recurrence', 'recurrence_value', 'last_fired_at', 'resent_at', 'is_template', 'template_name']);
        });
    }
};
