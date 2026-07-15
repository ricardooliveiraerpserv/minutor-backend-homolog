<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** CRM — origem de lead configurável (Site, Google, Indicação, Parceiro, …). */
class CrmLeadSource extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = ['name', 'ordem', 'active'];
    protected $casts = ['active' => 'boolean'];
}
