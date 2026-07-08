<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Justificativa de ticket vinculada a um status. PEGADINHA: $table explícito. */
class HelpDeskTicketJustification extends Model
{
    use SoftDeletes;

    protected $table = 'helpdesk_ticket_justifications';

    protected $fillable = ['status_id', 'name', 'availability', 'active', 'sort_order'];

    protected $casts = ['active' => 'boolean', 'sort_order' => 'integer'];

    public function status(): BelongsTo { return $this->belongsTo(HelpDeskStatus::class, 'status_id'); }
}
