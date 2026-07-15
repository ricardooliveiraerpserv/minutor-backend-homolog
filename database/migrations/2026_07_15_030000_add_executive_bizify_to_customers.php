<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-empresa: cliente é compartilhado entre ERPSERV e Bizify, mas o EXECUTIVO
 * de conta difere por empresa. `executive_id` passa a ser o executivo ERPSERV (label)
 * e ganhamos `executive_bizify_id` para o executivo Bizify. As telas filtram pelo
 * executivo da empresa ATIVA (Bizify → executive_bizify_id, senão executive_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'executive_bizify_id')) {
                $table->unsignedBigInteger('executive_bizify_id')->nullable()->after('executive_id');
                $table->foreign('executive_bizify_id')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'executive_bizify_id')) {
                $table->dropForeign(['executive_bizify_id']);
                $table->dropColumn('executive_bizify_id');
            }
        });
    }
};
