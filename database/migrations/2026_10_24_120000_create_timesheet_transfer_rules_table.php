<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('timesheet_transfer_rules')) {
            return;
        }
        Schema::create('timesheet_transfer_rules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('source_customer_id');   // cliente ORIGEM (X)
            $t->unsignedBigInteger('target_project_id');    // projeto DESTINO (Y)
            $t->string('date_field', 20)->default('servico'); // 'servico' (timesheets.date) | 'digitacao' (created_at)
            $t->date('date_from')->nullable();   // "de" (opcional)
            $t->date('date_until')->nullable();  // "até" (opcional = sem fim)
            $t->boolean('active')->default(true);
            $t->boolean('override_manual')->default(false); // false = respeita apontamento editado na mão
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['source_customer_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_transfer_rules');
    }
};
