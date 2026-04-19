<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contract_requests', function (Blueprint $table) {
            $table->foreignId('linked_project_id')->nullable()->constrained('projects')->nullOnDelete()->after('linked_coordinator_id');
        });
    }

    public function down(): void
    {
        Schema::table('contract_requests', function (Blueprint $table) {
            $table->dropForeign(['linked_project_id']);
            $table->dropColumn('linked_project_id');
        });
    }
};
