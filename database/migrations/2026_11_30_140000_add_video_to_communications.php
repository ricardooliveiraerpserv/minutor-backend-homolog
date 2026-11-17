<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Comunicação com cliente: vídeo opcional (mesmo armazenamento dos vídeos das publicações). */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('communications', 'video_path')) {
            Schema::table('communications', function (Blueprint $t) {
                $t->string('video_path')->nullable();
                $t->string('video_name')->nullable();
                $t->unsignedBigInteger('video_size')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('communications', 'video_path')) {
            Schema::table('communications', fn (Blueprint $t) => $t->dropColumn(['video_path', 'video_name', 'video_size']));
        }
    }
};
