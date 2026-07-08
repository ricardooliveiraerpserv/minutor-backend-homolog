<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Help Desk — Status do chamado (workflow configurável). */
class HelpDeskStatus extends Model
{
    protected $table = 'helpdesk_statuses';

    protected $fillable = [
        'key', 'label', 'color', 'sort_order',
        'is_default', 'is_open', 'is_resolved', 'is_terminal', 'sla_paused', 'active',
    ];

    protected $casts = [
        'sort_order'  => 'integer',
        'is_default'  => 'boolean',
        'is_open'     => 'boolean',
        'is_resolved' => 'boolean',
        'is_terminal' => 'boolean',
        'sla_paused'  => 'boolean',
        'active'      => 'boolean',
    ];

    public function tickets(): HasMany { return $this->hasMany(HelpDeskTicket::class, 'status_id'); }

    /** Status inicial padrão de um ticket novo. */
    public static function default(): ?self
    {
        return static::where('is_default', true)->orderBy('sort_order')->first()
            ?? static::orderBy('sort_order')->first();
    }
}
