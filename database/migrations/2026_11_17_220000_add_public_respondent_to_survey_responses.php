<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Link PÚBLICO da pesquisa (sem login, ex.: WhatsApp p/ clientes): quem responde se identifica no form.
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE notification_survey_responses ADD COLUMN IF NOT EXISTS respondent_name varchar(160) NULL');
        DB::statement('ALTER TABLE notification_survey_responses ADD COLUMN IF NOT EXISTS respondent_email varchar(190) NULL');
        DB::statement('ALTER TABLE notification_survey_responses ADD COLUMN IF NOT EXISTS respondent_company varchar(190) NULL');
        DB::statement('ALTER TABLE notification_survey_responses ADD COLUMN IF NOT EXISTS via_public_link boolean NOT NULL DEFAULT false');
    }
    public function down(): void {}
};
