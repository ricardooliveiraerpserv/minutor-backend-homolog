<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lista de distribuição reutilizável (clientes + usuários + e-mails externos) da Central de Comunicação. */
class DistributionList extends Model
{
    protected $table = 'distribution_lists';

    protected $fillable = ['nome', 'owner_id', 'customer_ids', 'user_ids', 'external_emails'];

    protected $casts = [
        'customer_ids'    => 'array',
        'user_ids'        => 'array',
        'external_emails' => 'array',
    ];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
}
