<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Meta comercial (quota) por competência — empresa (user_id null) ou vendedor. */
class CrmSalesTarget extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = ['periodo', 'user_id', 'valor_meta', 'created_by_id'];
    protected $casts = ['valor_meta' => 'decimal:2'];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
