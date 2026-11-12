<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Aprenda o Minutor — imagem de capa do vídeo (card). Aditiva/idempotente. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('training_videos') && !Schema::hasColumn('training_videos', 'cover_path')) {
            Schema::table('training_videos', function (Blueprint $t) {
                $t->string('cover_path', 255)->nullable()->after('file_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('training_videos', 'cover_path')) {
            Schema::table('training_videos', fn (Blueprint $t) => $t->dropColumn('cover_path'));
        }
    }
};
