<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('movidesk_tickets', function (Blueprint $table) {
            $table->string('base_status')->nullable()->after('status');
            $table->string('origin')->nullable()->after('base_status');
            $table->string('owner_email')->nullable()->after('responsavel');
            $table->string('owner_team')->nullable()->after('owner_email');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->after('owner_team');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete()->after('user_id');
            $table->timestamp('created_date')->nullable()->after('customer_id');
            $table->timestamp('closed_in')->nullable()->after('created_date');
            $table->timestamp('resolved_in')->nullable()->after('closed_in');
            $table->timestamp('sla_response_date')->nullable()->after('resolved_in');
            $table->timestamp('sla_real_response_date')->nullable()->after('sla_response_date');
            $table->timestamp('sla_solution_date')->nullable()->after('sla_real_response_date');
            $table->integer('sla_response_time')->nullable()->after('sla_solution_date');
            $table->integer('sla_solution_time')->nullable()->after('sla_response_time');
            $table->timestamp('portal_synced_at')->nullable()->after('sla_solution_time');

            $table->index('base_status');
            $table->index('created_date');
            $table->index(['user_id', 'created_date']);
            $table->index(['customer_id', 'created_date']);
        });
    }

    public function down(): void
    {
        Schema::table('movidesk_tickets', function (Blueprint $table) {
            $table->dropColumn([
                'base_status', 'origin', 'owner_email', 'owner_team',
                'user_id', 'customer_id',
                'created_date', 'closed_in', 'resolved_in',
                'sla_response_date', 'sla_real_response_date', 'sla_solution_date',
                'sla_response_time', 'sla_solution_time', 'portal_synced_at',
            ]);
        });
    }
};
