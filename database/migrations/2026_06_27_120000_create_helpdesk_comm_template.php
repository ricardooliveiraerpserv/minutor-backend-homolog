<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Template institucional de comunicação do Help Desk (layout global dos e-mails).
 * Linha única; os gatilhos usam automaticamente. Não altera o motor de automações.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_comm_template', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 120)->default('ERPSERV');
            $table->string('primary_color', 9)->default('#7c3aed');
            $table->string('font', 120)->default('Arial, Helvetica, sans-serif');
            $table->string('button_label', 60)->default('Abrir chamado');
            $table->string('footer_text', 255)->default('Central de Atendimento — ERPSERV Consultoria');
            $table->string('signature', 255)->default('Equipe de Atendimento');
            $table->boolean('show_minutor')->default(true); // "enviada via Minutor" discreto
            $table->timestamps();
        });

        DB::table('helpdesk_comm_template')->insert([
            'company_name' => 'ERPSERV', 'primary_color' => '#7c3aed',
            'font' => 'Arial, Helvetica, sans-serif', 'button_label' => 'Abrir chamado',
            'footer_text' => 'Central de Atendimento — ERPSERV Consultoria',
            'signature' => 'Equipe de Atendimento', 'show_minutor' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_comm_template');
    }
};
