<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Follow Up — camada transversal de acompanhamento de pendências/compromissos.
 * Origem polimórfica via FKs nullable (empresa/projeto/etapa/atividade). Soft delete
 * (regra do projeto: pendência nunca é apagada fisicamente).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();

            // pending | in_progress | waiting_third | completed | cancelled
            $table->string('status', 24)->default('pending');
            // subtipo quando waiting_third: client | partner | supplier | approval
            $table->string('waiting_subtype', 24)->nullable();

            $table->string('category', 24)->default('outro');
            $table->string('priority', 12)->default('media'); // baixa|media|alta|critica
            $table->date('due_date')->nullable();

            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Origem (todas opcionais)
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('project_stages')->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained('stage_deliveries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->integer('kanban_order')->default(0);
            $table->timestamp('sla_paused_at')->nullable(); // marca a entrada em waiting_third

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('due_date');
            $table->index('responsible_user_id');
            $table->index('project_id');
            $table->index('customer_id');
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
