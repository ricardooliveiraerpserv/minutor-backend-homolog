<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectContact extends Model
{
    protected $fillable = ['project_id', 'contract_contact_id', 'customer_contact_id', 'name', 'cargo', 'email', 'phone'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contractContact(): BelongsTo
    {
        return $this->belongsTo(ContractContact::class);
    }
}
