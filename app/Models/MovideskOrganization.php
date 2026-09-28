<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovideskOrganization extends Model
{
    protected $fillable = ['movidesk_id', 'name', 'cnpj', 'is_active', 'customer_id', 'project_id', 'hd_customer_id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Cliente Minutor vinculado especificamente para a integração de CHAMADOS (Help Desk). */
    public function hdCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'hd_customer_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
