<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Departamento do Help Desk, pertencente a um cliente (customer). Ver migration
 * 2026_07_17_140000_create_helpdesk_departments.
 */
class HelpDeskDepartment extends Model
{
    use SoftDeletes;

    // Laravel inferiria 'help_desk_departments' (com _); a tabela é 'helpdesk_departments'.
    protected $table = 'helpdesk_departments';

    protected $fillable = ['customer_id', 'name', 'active', 'company_id'];

    protected $casts = ['active' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
