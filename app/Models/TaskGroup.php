<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Rotina de equipe: grupo de tarefas-modelo que geram tasks diárias p/ os usuários vinculados. */
class TaskGroup extends Model
{
    protected $table = 'task_groups';

    protected $fillable = ['nome', 'descricao', 'owner_id', 'active', 'start_date', 'end_date'];

    protected $casts = ['active' => 'boolean', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function items(): HasMany { return $this->hasMany(TaskGroupItem::class, 'group_id'); }
    public function users(): BelongsToMany { return $this->belongsToMany(User::class, 'task_group_users', 'group_id', 'user_id'); }
}
