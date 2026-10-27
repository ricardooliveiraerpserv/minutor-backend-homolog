<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liberação de despesa por competência (bloco de despesas do Fechamento).
 * Escopo: customer_id/project_id/user_id nulos = "qualquer". Fecha sozinha às 23:59
 * do dia da abertura (auto_close_at) ou manualmente (closed_at).
 */
class ExpenseOpenPeriod extends Model
{
    protected $fillable = [
        'year_month', 'customer_id', 'project_id', 'user_id',
        'opened_by', 'closed_by', 'closed_at', 'auto_close_at', 'note',
    ];

    protected $casts = [
        'closed_at'     => 'datetime',
        'auto_close_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->closed_at === null
            && ($this->auto_close_at === null || $this->auto_close_at->isFuture());
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
