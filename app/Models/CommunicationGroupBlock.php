<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bloco de um grupo — representa um cliente e agrega seus destinatários. */
class CommunicationGroupBlock extends Model
{
    protected $table = 'communication_group_blocks';
    protected $fillable = ['group_id', 'customer_id', 'label', 'sort_order'];

    public function group(): BelongsTo { return $this->belongsTo(CommunicationGroup::class, 'group_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function recipients(): HasMany { return $this->hasMany(CommunicationGroupRecipient::class, 'block_id')->orderBy('name'); }
}
