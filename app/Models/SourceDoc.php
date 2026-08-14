<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Documentação viva de um fonte (owner/repository/path). */
class SourceDoc extends Model
{
    protected $fillable = ['customer_id', 'owner', 'repository', 'path', 'objetivo', 'estrutura', 'tabelas'];

    public function changes(): HasMany
    {
        return $this->hasMany(SourceDocChange::class)->orderBy('id');
    }
}
