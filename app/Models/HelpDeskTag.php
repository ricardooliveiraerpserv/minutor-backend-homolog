<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Help Desk — Tag de classificação livre de chamados. */
class HelpDeskTag extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $table = 'helpdesk_tags';

    protected $fillable = ['name', 'color'];
    // (table explícito porque Laravel inferiria help_desk_tags)

    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(HelpDeskTicket::class, 'helpdesk_ticket_tag', 'helpdesk_tag_id', 'ticket_id')
            ->withTimestamps();
    }
}
