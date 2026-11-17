<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Publicações (Meu Dia / Comunicados) com VÍDEO opcional — arquivo no storage local (volume). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notifications_center', function (Blueprint $t) {
            if (!Schema::hasColumn('notifications_center', 'video_path')) $t->string('video_path', 255)->nullable();
            if (!Schema::hasColumn('notifications_center', 'video_name')) $t->string('video_name', 255)->nullable();
            if (!Schema::hasColumn('notifications_center', 'video_size')) $t->bigInteger('video_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notifications_center', function (Blueprint $t) {
            $t->dropColumn(['video_path', 'video_name', 'video_size']);
        });
    }
};
