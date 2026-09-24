<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('helpdesk_forms', function (Blueprint $t) {
            $t->string('subtitle', 200)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_forms', function (Blueprint $t) {
            $t->dropColumn('subtitle');
        });
    }
};
