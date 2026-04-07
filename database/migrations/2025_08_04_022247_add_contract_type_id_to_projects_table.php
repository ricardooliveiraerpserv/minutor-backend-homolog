<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Verificar se precisa remover enum contract_type
        if (Schema::hasColumn('projects', 'contract_type')) {
            // Primeiro remover índices relacionados se existirem
            Schema::table('projects', function (Blueprint $table) {
                try {
                    $table->dropIndex(['contract_type']);
                } catch (Exception $e) {
                    // Ignora se o índice não existir
                }
                $table->dropColumn('contract_type');
            });
        }
        
        // Adicionar foreign key contract_type_id se não existir
        if (!Schema::hasColumn('projects', 'contract_type_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->foreignId('contract_type_id')
                      ->nullable()
                      ->after('service_type_id')
                      ->constrained('contract_types');
                      
                $table->index('contract_type_id');
            });
        }
        
        // Definir um valor padrão para registros existentes (se houver)
        // Usar o primeiro contract_type disponível
        $defaultContractTypeId = DB::table('contract_types')->first()?->id;
        if ($defaultContractTypeId) {
            DB::table('projects')
                ->whereNull('contract_type_id')
                ->update(['contract_type_id' => $defaultContractTypeId]);
        }
        
        // Tornar a coluna not null após definir valores
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('contract_type_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Remover foreign key e coluna
            if (Schema::hasColumn('projects', 'contract_type_id')) {
                $table->dropForeign(['contract_type_id']);
                $table->dropIndex(['contract_type_id']);
                $table->dropColumn('contract_type_id');
            }
            
            // Recriar enum contract_type
            $table->enum('contract_type', [
                'fixed_hours',
                'monthly_hours', 
                'closed',
                'on_demand',
                'saas'
            ])->after('service_type_id')->default('fixed_hours');
        });
    }
};
