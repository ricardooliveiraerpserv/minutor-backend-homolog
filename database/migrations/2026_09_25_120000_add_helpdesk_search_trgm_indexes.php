<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Acelera a BUSCA GLOBAL do Help Desk.
 *
 * A busca usa ILIKE '%termo%' (wildcard à esquerda) em assunto, descrição, nº do chamado,
 * nome do solicitante e no CORPO das interações — além dos nomes de cliente/responsável/contato.
 * Com wildcard à esquerda o Postgres não usa índice btree e faz seq scan em todas as tabelas.
 * Índices GIN trigram (pg_trgm) tornam esse ILIKE indexável (para termos com ≥3 caracteres).
 */
return new class extends Migration
{
    // CREATE INDEX / CREATE EXTENSION não podem rodar dentro de transação em alguns casos.
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // trigram é específico do Postgres
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $indexes = [
            ['idx_hd_tickets_subject_trgm',        'helpdesk_tickets',          'subject'],
            ['idx_hd_tickets_description_trgm',    'helpdesk_tickets',          'description'],
            ['idx_hd_tickets_ticketnum_trgm',      'helpdesk_tickets',          'ticket_number'],
            ['idx_hd_tickets_requestername_trgm',  'helpdesk_tickets',          'requester_name'],
            ['idx_hd_comments_body_trgm',          'helpdesk_ticket_comments',  'body'],
            ['idx_customers_name_trgm',            'customers',                 'name'],
            ['idx_users_name_trgm',                'users',                     'name'],
            ['idx_customer_contacts_name_trgm',    'customer_contacts',         'name'],
        ];

        foreach ($indexes as [$name, $table, $column]) {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS {$name} ON {$table} USING gin (({$column}) gin_trgm_ops)"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'idx_hd_tickets_subject_trgm',
            'idx_hd_tickets_description_trgm',
            'idx_hd_tickets_ticketnum_trgm',
            'idx_hd_tickets_requestername_trgm',
            'idx_hd_comments_body_trgm',
            'idx_customers_name_trgm',
            'idx_users_name_trgm',
            'idx_customer_contacts_name_trgm',
        ] as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
