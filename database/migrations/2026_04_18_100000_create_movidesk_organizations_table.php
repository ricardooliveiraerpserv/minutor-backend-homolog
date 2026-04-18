<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movidesk_organizations', function (Blueprint $table) {
            $table->id();
            $table->string('movidesk_id')->unique();
            $table->string('name');
            $table->string('cnpj', 20)->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movidesk_organizations');
    }
};
