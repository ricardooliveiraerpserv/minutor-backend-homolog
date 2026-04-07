<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Expense;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Obtém todas as despesas que usam este método de pagamento (usando code)
     */
    public function getExpensesAttribute()
    {
        return Expense::where('payment_method', $this->code)->get();
    }

    /**
     * Conta quantas despesas usam este método de pagamento
     */
    public function expensesCount(): int
    {
        return Expense::where('payment_method', $this->code)->count();
    }

    /**
     * Scope para métodos ativos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

