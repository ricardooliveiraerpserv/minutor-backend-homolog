<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Override por audiência (na ausência de linhas, o resolver usa o default do registry).
        Schema::create('workflow_recipients', function (Blueprint $t) {
            $t->id();
            $t->string('workflow_key', 80);
            $t->string('audience', 60);
            $t->string('channel', 8)->default('off'); // off | to | cc
            $t->timestamps();
            $t->unique(['workflow_key', 'audience']);
            $t->index('workflow_key');
        });

        // E-mails fixos extras por workflow (ex.: financeiro@, gestor@).
        Schema::create('workflow_extra_emails', function (Blueprint $t) {
            $t->id();
            $t->string('workflow_key', 80);
            $t->string('email');
            $t->string('channel', 8)->default('cc'); // to | cc
            $t->timestamps();
            $t->index('workflow_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_extra_emails');
        Schema::dropIfExists('workflow_recipients');
    }
};
