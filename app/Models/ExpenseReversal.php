<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseReversal extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'reversed_by',
        'reversal_reason',
        'original_approver_id',
        'original_approval_date',
    ];

    protected $casts = [
        'original_approval_date' => 'datetime',
    ];

    /**
     * Relacionamento com a despesa
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * Relacionamento com quem estornou
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * Relacionamento com quem aprovou originalmente
     */
    public function originalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_approver_id');
    }
}
