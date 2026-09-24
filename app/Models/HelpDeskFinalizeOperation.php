<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Help Desk — registro de idempotência de uma operação "Finalizar Atendimento" (R1). */
class HelpDeskFinalizeOperation extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $table = 'helpdesk_finalize_operations';

    protected $fillable = ['idempotency_key', 'helpdesk_ticket_id', 'user_id', 'status_code', 'response'];

    protected $casts = ['response' => 'array', 'status_code' => 'integer'];
}
