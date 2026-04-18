<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('project_name')->nullable()->after('customer_id');
            $table->unsignedBigInteger('parent_project_id')->nullable()->after('project_id');
            $table->foreign('parent_project_id')->references('id')->on('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['parent_project_id']);
            $table->dropColumn(['project_name', 'parent_project_id']);
        });
    }
};
