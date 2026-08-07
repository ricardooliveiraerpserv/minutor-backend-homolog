<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** CRM — motivo de descarte do funil de prospecção (com repescagem opcional). */
class CrmDiscardReason extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = ['name', 'ordem', 'dias_repescagem', 'active'];
    protected $casts = ['active' => 'boolean', 'ordem' => 'integer', 'dias_repescagem' => 'integer'];
}
