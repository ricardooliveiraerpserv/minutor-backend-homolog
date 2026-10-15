<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promove o módulo de Reunião para o modelo NOVO (Central de Reuniões / Teams a partir do chamado)
 * de forma ADITIVA sobre a tabela `meetings` existente (Central antiga: meeting_date + tarefas).
 *
 * - `meetings`: acrescenta os campos do agendamento novo (starts_at/provider/origem/join_url/...),
 *   preservando `meeting_date` e as 6 reuniões antigas (que ficam com origin_type NULL).
 * - `meeting_participants`: acrescenta e-mail/nome/papel/externo (participante do cliente), mantendo
 *   o uso antigo como pivô user_id (belongsToMany).
 * - cria `meeting_events` (timeline imutável).
 *
 * Idempotente (Schema::hasColumn / hasTable) — seguro para rodar mais de uma vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            if (!Schema::hasColumn('meetings', 'provider'))            $table->string('provider', 20)->default('presencial');
            if (!Schema::hasColumn('meetings', 'status'))              $table->string('status', 16)->default('scheduled');
            if (!Schema::hasColumn('meetings', 'starts_at'))           $table->timestampTz('starts_at')->nullable();
            if (!Schema::hasColumn('meetings', 'ends_at'))             $table->timestampTz('ends_at')->nullable();
            if (!Schema::hasColumn('meetings', 'duration_minutes'))    $table->integer('duration_minutes')->nullable();
            if (!Schema::hasColumn('meetings', 'timezone'))            $table->string('timezone', 40)->default('America/Sao_Paulo');
            if (!Schema::hasColumn('meetings', 'organizer_user_id'))   $table->unsignedBigInteger('organizer_user_id')->nullable();
            if (!Schema::hasColumn('meetings', 'origin_type'))         $table->string('origin_type', 40)->nullable();
            if (!Schema::hasColumn('meetings', 'origin_id'))           $table->unsignedBigInteger('origin_id')->nullable();
            if (!Schema::hasColumn('meetings', 'external_meeting_id')) $table->string('external_meeting_id', 255)->nullable();
            if (!Schema::hasColumn('meetings', 'join_url'))            $table->text('join_url')->nullable();
            if (!Schema::hasColumn('meetings', 'provider_data'))       $table->json('provider_data')->nullable();
            if (!Schema::hasColumn('meetings', 'started_at'))          $table->timestampTz('started_at')->nullable();
            if (!Schema::hasColumn('meetings', 'ended_at'))            $table->timestampTz('ended_at')->nullable();
            if (!Schema::hasColumn('meetings', 'summary'))             $table->text('summary')->nullable();
            if (!Schema::hasColumn('meetings', 'ata'))                 $table->text('ata')->nullable();
            if (!Schema::hasColumn('meetings', 'transcript'))          $table->text('transcript')->nullable();
            if (!Schema::hasColumn('meetings', 'recording_url'))       $table->text('recording_url')->nullable();
            if (!Schema::hasColumn('meetings', 'transcript_url'))      $table->text('transcript_url')->nullable();
        });

        // Índices (idempotentes via IF NOT EXISTS — fora do Blueprint p/ não duplicar).
        DB::statement("CREATE INDEX IF NOT EXISTS meetings_origin_type_origin_id_index ON meetings (origin_type, origin_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS meetings_starts_at_index ON meetings (starts_at)");
        DB::statement("CREATE INDEX IF NOT EXISTS meetings_status_index ON meetings (status)");

        // Backfill: reuniões antigas ganham starts_at = meeting_date (continuam origin_type NULL = Central antiga).
        DB::statement("UPDATE meetings SET starts_at = meeting_date WHERE starts_at IS NULL AND meeting_date IS NOT NULL");

        Schema::table('meeting_participants', function (Blueprint $table) {
            if (!Schema::hasColumn('meeting_participants', 'customer_contact_id')) $table->unsignedBigInteger('customer_contact_id')->nullable();
            if (!Schema::hasColumn('meeting_participants', 'email'))               $table->string('email', 190)->nullable();
            if (!Schema::hasColumn('meeting_participants', 'name'))                $table->string('name', 190)->nullable();
            if (!Schema::hasColumn('meeting_participants', 'role'))                $table->string('role', 20)->default('required');
            if (!Schema::hasColumn('meeting_participants', 'response'))            $table->string('response', 12)->default('none');
            if (!Schema::hasColumn('meeting_participants', 'is_external'))         $table->boolean('is_external')->default(false);
        });

        if (!Schema::hasTable('meeting_events')) {
            Schema::create('meeting_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
                $table->string('event_type', 40);
                $table->json('meta')->nullable();
                $table->unsignedBigInteger('triggered_by')->nullable();
                $table->timestampTz('created_at')->nullable();
                $table->index('meeting_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_events');
        Schema::table('meeting_participants', function (Blueprint $table) {
            foreach (['customer_contact_id', 'email', 'name', 'role', 'response', 'is_external'] as $c) {
                if (Schema::hasColumn('meeting_participants', $c)) $table->dropColumn($c);
            }
        });
        Schema::table('meetings', function (Blueprint $table) {
            foreach ([
                'provider', 'status', 'starts_at', 'ends_at', 'duration_minutes', 'timezone',
                'organizer_user_id', 'origin_type', 'origin_id', 'external_meeting_id', 'join_url',
                'provider_data', 'started_at', 'ended_at', 'summary', 'ata', 'transcript',
                'recording_url', 'transcript_url',
            ] as $c) {
                if (Schema::hasColumn('meetings', $c)) $table->dropColumn($c);
            }
        });
    }
};
