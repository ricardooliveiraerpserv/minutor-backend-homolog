<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-E.2.0 — Aprovação + Assinatura digital no Portal (sem ferramenta externa).
 * Evidências de aprovação e de assinatura (nome/CPF/cargo + imagem desenhada + IP/UA/hash/versão).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_proposal_participants', function (Blueprint $table) {
            $cols = [
                'cargo' => fn () => $table->string('cargo', 120)->nullable(),
                'approval_comment' => fn () => $table->text('approval_comment')->nullable(),
                'approval_ip' => fn () => $table->string('approval_ip', 64)->nullable(),
                'approval_user_agent' => fn () => $table->text('approval_user_agent')->nullable(),
                'sign_name' => fn () => $table->string('sign_name', 160)->nullable(),
                'sign_cpf' => fn () => $table->string('sign_cpf', 20)->nullable(),
                'sign_cargo' => fn () => $table->string('sign_cargo', 120)->nullable(),
                'sign_image' => fn () => $table->longText('sign_image')->nullable(),       // data-URL do traço
                'sign_ip' => fn () => $table->string('sign_ip', 64)->nullable(),
                'sign_user_agent' => fn () => $table->text('sign_user_agent')->nullable(),
                'sign_doc_hash' => fn () => $table->string('sign_doc_hash', 191)->nullable(),
                'sign_doc_version' => fn () => $table->unsignedSmallInteger('sign_doc_version')->nullable(),
            ];
            foreach ($cols as $name => $make) {
                if (!Schema::hasColumn('crm_proposal_participants', $name)) $make();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_proposal_participants', function (Blueprint $table) {
            foreach (['cargo', 'approval_comment', 'approval_ip', 'approval_user_agent', 'sign_name', 'sign_cpf', 'sign_cargo', 'sign_image', 'sign_ip', 'sign_user_agent', 'sign_doc_hash', 'sign_doc_version'] as $c) {
                if (Schema::hasColumn('crm_proposal_participants', $c)) $table->dropColumn($c);
            }
        });
    }
};
