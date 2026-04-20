<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_request_id')->nullable()->after('contract_id');
            $table->foreign('contract_request_id')->references('id')->on('contract_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['contract_request_id']);
            $table->dropColumn('contract_request_id');
        });
    }
};
