<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Remove crm_goals: a meta comercial canônica é crm_sales_targets (periodo/user_id/valor_meta),
 * que já era lida pelo Dashboard e pelo CrmSalesTargetController. crm_goals foi duplicata efêmera
 * (sem dado relevante). crm_commission_rates permanece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('crm_goals');
    }

    public function down(): void
    {
        // Sem recriação: a fonte canônica é crm_sales_targets.
    }
};
