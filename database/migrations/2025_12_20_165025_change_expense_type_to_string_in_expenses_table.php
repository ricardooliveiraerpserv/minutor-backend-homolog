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
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite não suporta ALTER COLUMN diretamente
            // Precisamos recriar a tabela sem a constraint enum

            // 1. Criar nova tabela temporária
            DB::statement('
                CREATE TABLE expenses_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    project_id INTEGER NOT NULL,
                    expense_category_id INTEGER NOT NULL,
                    expense_date DATE NOT NULL,
                    description TEXT NOT NULL,
                    amount DECIMAL(10,2) NOT NULL,
                    expense_type VARCHAR(255) NOT NULL,
                    payment_method VARCHAR(255) NOT NULL,
                    receipt_path VARCHAR(255) NULL,
                    receipt_original_name VARCHAR(255) NULL,
                    status VARCHAR(255) NOT NULL DEFAULT "pending",
                    rejection_reason TEXT NULL,
                    charge_client BOOLEAN NOT NULL DEFAULT 0,
                    reviewed_by INTEGER NULL,
                    reviewed_at TIMESTAMP NULL,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                    FOREIGN KEY (expense_category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
                    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
                )
            ');

            // 2. Copiar dados da tabela antiga para a nova
            DB::statement('
                INSERT INTO expenses_new
                SELECT * FROM expenses
            ');

            // 3. Remover tabela antiga
            DB::statement('DROP TABLE expenses');

            // 4. Renomear tabela nova
            DB::statement('ALTER TABLE expenses_new RENAME TO expenses');

            // 5. Recriar índices
            DB::statement('CREATE INDEX expenses_user_id_expense_date_index ON expenses(user_id, expense_date)');
            DB::statement('CREATE INDEX expenses_project_id_expense_date_index ON expenses(project_id, expense_date)');
            DB::statement('CREATE INDEX expenses_status_index ON expenses(status)');
            DB::statement('CREATE INDEX expenses_expense_date_index ON expenses(expense_date)');
            DB::statement('CREATE INDEX expenses_expense_type_index ON expenses(expense_type)');
            DB::statement('CREATE INDEX expenses_reviewed_by_index ON expenses(reviewed_by)');

        } else {
            // Para MySQL/PostgreSQL, podemos usar change()
            Schema::table('expenses', function (Blueprint $table) {
                $table->string('expense_type')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // Para reverter no SQLite, precisaríamos recriar com enum
            // Mas isso pode causar problemas se houver dados com valores diferentes
            // Por segurança, vamos apenas alterar para string novamente (já está como string)
            // Se precisar reverter completamente, seria necessário recriar a tabela com enum
        } else {
            // Para MySQL/PostgreSQL
            Schema::table('expenses', function (Blueprint $table) {
                $table->enum('expense_type', ['corporate_card', 'reimbursement'])->change();
            });
        }
    }
};
