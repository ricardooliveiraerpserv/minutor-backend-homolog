<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('helpdesk_form_fields', function (Blueprint $t) {
            // Automação condicional: { "when": "<key do checkbox>", "value": "não se aplica" }.
            // Quando o checkbox referenciado está marcado, este campo recebe `value` e trava.
            $t->json('rule')->nullable()->after('min_chars');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_form_fields', function (Blueprint $t) {
            $t->dropColumn('rule');
        });
    }
};
