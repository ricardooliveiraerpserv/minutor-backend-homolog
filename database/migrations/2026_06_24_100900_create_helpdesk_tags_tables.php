<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — Tags de classificação livre + pivot ticket↔tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('helpdesk_tags')) {
            Schema::create('helpdesk_tags', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80)->unique();
                $table->string('color', 16)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('helpdesk_ticket_tag')) {
            Schema::create('helpdesk_ticket_tag', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained('helpdesk_tickets')->cascadeOnDelete();
                $table->foreignId('helpdesk_tag_id')->constrained('helpdesk_tags')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['ticket_id', 'helpdesk_tag_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_ticket_tag');
        Schema::dropIfExists('helpdesk_tags');
    }
};
