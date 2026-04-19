<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contract_requests', function (Blueprint $table) {
            $table->string('kanban_column')->default('backlog')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('contract_requests', function (Blueprint $table) {
            $table->dropColumn('kanban_column');
        });
    }
};
