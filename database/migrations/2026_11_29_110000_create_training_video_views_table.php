<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aprenda o Minutor — quem assistiu cada vídeo e QUAL versão (para o selo "Assistido" e o destaque
 * "Atualizado" quando o vídeo muda depois que a pessoa assistiu).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS training_video_views (
            id BIGSERIAL PRIMARY KEY,
            training_video_id BIGINT NOT NULL REFERENCES training_videos(id) ON DELETE CASCADE,
            user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            watched_version INTEGER NOT NULL DEFAULT 1,
            first_watched_at TIMESTAMP(0) NULL,
            last_watched_at TIMESTAMP(0) NULL,
            created_at TIMESTAMP(0) NULL,
            updated_at TIMESTAMP(0) NULL
        )');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS training_video_views_video_user_uq ON training_video_views (training_video_id, user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS training_video_views_user_idx ON training_video_views (user_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS training_video_views');
    }
};
