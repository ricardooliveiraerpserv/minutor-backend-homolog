<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Remove o canal divergente project_client_comments — a conversa global do
 * projeto passou a usar a MESMA tabela/endpoints de PROD (contract_request_messages
 * keyada por project_id, visibility='client', via ProjectCommentController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('project_client_comments');
    }

    public function down(): void
    {
        // Sem recriação: a tabela foi descontinuada.
    }
};
