<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Data de expiração do comunicado para a visualização do cliente dentro do Minutor (e-mail já enviado permanece). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('all_customers');
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
