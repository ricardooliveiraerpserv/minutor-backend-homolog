<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_contact_id')->nullable()->after('contract_contact_id');
            $table->foreign('customer_contact_id')->references('id')->on('customer_contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_contacts', function (Blueprint $table) {
            $table->dropForeign(['customer_contact_id']);
            $table->dropColumn('customer_contact_id');
        });
    }
};
