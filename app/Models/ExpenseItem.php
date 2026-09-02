<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de uma despesa. Cada item tem categoria, descrição, valor e comprovante
 * próprio (anexo pela camada global, entity_type 'EXPENSE_ITEM', category
 * 'receipt'). O total da despesa (Expense::amount) é a soma dos itens.
 */
class ExpenseItem extends Model
{
    use HasFactory;
    use \App\Attachments\Concerns\HasGlobalAttachments;

    // Chave do registry global de anexos (comprovante por item).
    public static function attachmentEntityType(): string { return 'EXPENSE_ITEM'; }

    protected $fillable = [
        'expense_id',
        'expense_category_id',
        'description',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'formatted_amount',
        'receipt_url',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** URL do comprovante do item — 100% via camada Attachment. */
    public function getReceiptUrlAttribute(): ?string
    {
        $url = $this->attachmentUrl('receipt');
        if ($url === null) return null;
        return rtrim(config('app.url'), '/') . $url;
    }

    public function getFormattedAmountAttribute(): string
    {
        return 'R$ ' . number_format((float) $this->amount, 2, ',', '.');
    }
}
