<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Justificativas de ticket — motivos VINCULADOS A UM STATUS (ex.: ao mover p/ "Cancelado",
 * escolher "Aberto em duplicidade"). Estilo Movidesk. Cada justificativa pertence a um status
 * e tem disponibilidade (público/interno).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_ticket_justifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('status_id'); // a qual status esta justificativa se aplica
            $table->string('name', 160);
            $table->string('availability', 24)->default('public_and_internal'); // public_and_internal | public | internal
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index('status_id');
            $table->index('active');
        });

        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('helpdesk_tickets', 'justification_id')) {
                $table->unsignedBigInteger('justification_id')->nullable()->after('status_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('helpdesk_tickets', 'justification_id')) {
                $table->dropColumn('justification_id');
            }
        });
        Schema::dropIfExists('helpdesk_ticket_justifications');
    }
};
