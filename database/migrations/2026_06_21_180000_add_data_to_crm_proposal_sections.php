<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajuste arquitetural — Proposal Theme Renderer.
 * Cada seção passa a guardar também um `data` estruturado (snapshot), usado pelos renderers
 * visuais do Portal (ícones/badges/cards/banners/grids). O `content` HTML segue como fallback.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_proposal_sections', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_proposal_sections', 'data')) {
                $table->json('data')->nullable()->after('content');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_proposal_sections', function (Blueprint $table) {
            if (Schema::hasColumn('crm_proposal_sections', 'data')) $table->dropColumn('data');
        });
    }
};
