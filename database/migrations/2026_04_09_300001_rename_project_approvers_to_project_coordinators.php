<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('project_approvers', 'project_coordinators');
    }

    public function down(): void
    {
        Schema::rename('project_coordinators', 'project_approvers');
    }
};
