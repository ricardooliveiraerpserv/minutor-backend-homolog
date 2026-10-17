<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Log dos tickets enviados (copiados) para a Conecta na migração de cliente.
 * 1 linha por ticket de origem (chave source_system+external_ref). Ver
 * [[project_minutor_conecta_client_migration]].
 */
class ConectaTicketMigrationLog extends Model
{
    protected $table = 'conecta_ticket_migration_log';

    protected $fillable = [
        'customer_id', 'erpserv_ticket_id', 'movidesk_id', 'source_system', 'external_ref',
        'subject', 'status_text', 'kind', 'conecta_ticket_id', 'result', 'error_msg',
        'attempts', 'comments_sent', 'attachments_sent', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function ticket()
    {
        return $this->belongsTo(HelpDeskTicket::class, 'erpserv_ticket_id');
    }
}
