<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tarefa-modelo de uma rotina (gera tasks individuais p/ cada usuário do grupo). */
class TaskGroupItem extends Model
{
    protected $table = 'task_group_items';

    protected $fillable = ['group_id', 'titulo', 'tipo', 'priority', 'recorrencia', 'recurrence_weekdays', 'hora_padrao'];

    protected $casts = ['recurrence_weekdays' => 'array'];

    public function group(): BelongsTo { return $this->belongsTo(TaskGroup::class, 'group_id'); }
    public function tasks(): HasMany { return $this->hasMany(Task::class, 'group_item_id'); }
}
