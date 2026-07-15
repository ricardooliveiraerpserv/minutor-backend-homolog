<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** CRM — motivo de perda configurável (Preço, Concorrente, Sem orçamento, …). */
class CrmLossReason extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = ['name', 'ordem', 'active'];
    protected $casts = ['active' => 'boolean'];
}
