<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'can_use_bot')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_use_bot')->default(false)->after('enabled')
                ->comment('Permissão para invocar o @bot dentro de conversas');
        });

        // Default: admin, executivo e coordenador podem usar @bot
        DB::table('users')->where(function ($q) {
            $q->where('type', 'admin')
              ->orWhere('is_executive', true)
              ->orWhere('type', 'coordenador');
        })->update(['can_use_bot' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'can_use_bot')) return;
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_use_bot');
        });
    }
};
