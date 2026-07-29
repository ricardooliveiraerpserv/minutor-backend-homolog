<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Destinatário de um bloco: e-mail de usuário cliente cadastrado (user_id) ou manual. */
class CommunicationGroupRecipient extends Model
{
    protected $table = 'communication_group_recipients';
    protected $fillable = ['block_id', 'user_id', 'email', 'name', 'kind'];

    public function block(): BelongsTo { return $this->belongsTo(CommunicationGroupBlock::class, 'block_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
