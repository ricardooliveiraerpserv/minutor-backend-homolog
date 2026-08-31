<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log de cobranças enviadas (idempotência do scheduler). `sent_on` = data do envio
 * pra permitir 1 envio por dia do tipo "overdue" sem reenviar d5/d3/d1/due.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('follow_up_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follow_up_id')->constrained('follow_ups')->cascadeOnDelete();
            $table->string('kind', 12); // d5 | d3 | d1 | due | overdue
            $table->date('sent_on');
            $table->timestamp('created_at')->useCurrent();

            // d5/d3/d1/due: uma vez por follow-up; overdue: uma vez por dia.
            $table->unique(['follow_up_id', 'kind', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_reminders');
    }
};
