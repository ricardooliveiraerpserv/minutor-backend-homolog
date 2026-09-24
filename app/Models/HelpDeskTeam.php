<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Help Desk — Fila/Equipe de atendimento. Membros = usuários internos do Minutor. */
class HelpDeskTeam extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    use SoftDeletes;

    protected $table = 'helpdesk_teams';

    protected $fillable = [
        'name', 'slug', 'description', 'color', 'lead_user_id', 'active', 'sort_order',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'sort_order' => 'integer',
    ];

    public function lead(): BelongsTo  { return $this->belongsTo(User::class, 'lead_user_id'); }
    public function tickets(): HasMany { return $this->hasMany(HelpDeskTicket::class, 'team_id'); }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'helpdesk_team_user', 'helpdesk_team_id', 'user_id')
            ->withPivot('role')->withTimestamps();
    }
}
