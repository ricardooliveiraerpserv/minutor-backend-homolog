<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Expense;

class ExpenseType extends Model
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
     * Obtém todas as despesas deste tipo (usando code)
     */
    public function getExpensesAttribute()
    {
        return Expense::where('expense_type', $this->code)->get();
    }

    /**
     * Conta quantas despesas usam este tipo
     */
    public function expensesCount(): int
    {
        return Expense::where('expense_type', $this->code)->count();
    }

    /**
     * Scope para tipos ativos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
