<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Grupo de reunião: conjunto nomeado de usuários p/ preencher os Envolvidos de uma reunião. */
class MeetingGroup extends Model
{
    use SoftDeletes;

    protected $table = 'meeting_groups';
    protected $fillable = ['name', 'created_by_id'];

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_id'); }
    public function members(): BelongsToMany { return $this->belongsToMany(User::class, 'meeting_group_members', 'group_id', 'user_id')->withTimestamps(); }
}
