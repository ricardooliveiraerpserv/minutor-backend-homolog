<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensagem de parabéns de aniversário entre usuários (registra remetente). */
class BirthdayMessage extends Model
{
    protected $table = 'birthday_messages';

    protected $fillable = ['from_user_id', 'to_user_id', 'message'];

    public function fromUser(): BelongsTo { return $this->belongsTo(User::class, 'from_user_id'); }
    public function toUser(): BelongsTo { return $this->belongsTo(User::class, 'to_user_id'); }
}
