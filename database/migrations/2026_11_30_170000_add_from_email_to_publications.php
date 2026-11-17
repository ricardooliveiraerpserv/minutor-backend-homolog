<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Remetente escolhido no formulário (publicações e comunicações com cliente). Null = não-responder.
return new class extends Migration {
    public function up(): void
    {
        foreach (['notifications_center', 'communications'] as $t) {
            if (!Schema::hasColumn($t, 'from_email')) {
                Schema::table($t, fn (Blueprint $b) => $b->string('from_email', 190)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['notifications_center', 'communications'] as $t) {
            Schema::table($t, fn (Blueprint $b) => $b->dropColumn('from_email'));
        }
    }
};
