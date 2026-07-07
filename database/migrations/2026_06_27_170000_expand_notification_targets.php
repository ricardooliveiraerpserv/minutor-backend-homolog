<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Alvos extras da notificação: tipo de contratação, cliente específico + envio por e-mail. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            $table->json('target_contract_types')->nullable()->after('target_users'); // ['clt','cooperado','pj']
            $table->foreignId('target_customer_id')->nullable()->after('target_contract_types')->constrained('customers')->nullOnDelete();
            $table->boolean('send_email')->default(true)->after('cta_url');
        });
    }

    public function down(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_customer_id');
            $table->dropColumn(['target_contract_types', 'send_email']);
        });
    }
};
