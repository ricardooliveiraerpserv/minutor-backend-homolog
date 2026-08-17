<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cost_centers')) {
            Schema::create('cost_centers', function (Blueprint $t) {
                $t->id();
                $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $t->string('code');
                $t->string('description');
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->softDeletes();
                $t->index(['customer_id', 'active']);
            });
        }

        if (!Schema::hasTable('project_cost_center_allocations')) {
            Schema::create('project_cost_center_allocations', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $t->foreignId('cost_center_id')->constrained('cost_centers')->cascadeOnDelete();
                $t->decimal('percentual', 5, 2)->default(0);   // % do valor total do projeto
                $t->unsignedInteger('position')->default(0);
                $t->timestamps();
                $t->index('project_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_cost_center_allocations');
        Schema::dropIfExists('cost_centers');
    }
};
