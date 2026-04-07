<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'parent_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'parent_id' => 'integer',
    ];

    /**
     * Relacionamento com categoria pai
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'parent_id');
    }

    /**
     * Relacionamento com subcategorias
     */
    public function children(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * Relacionamento com despesas
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Scope para categorias principais (sem pai)
     */
    public function scopeMainCategories($query)
    {
        return $query->whereNull('parent_id')->orderBy('sort_order');
    }

    /**
     * Scope para categorias ativas
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Verifica se é uma categoria principal
     */
    public function isMainCategory(): bool
    {
        return is_null($this->parent_id);
    }

    /**
     * Verifica se tem subcategorias
     */
    public function hasChildren(): bool
    {
        return $this->children()->count() > 0;
    }

    /**
     * Retorna o caminho completo da categoria
     */
    public function getFullPathAttribute(): string
    {
        if ($this->parent) {
            return $this->parent->full_path . ' > ' . $this->name;
        }
        
        return $this->name;
    }

    /**
     * Retorna todas as subcategorias (recursivo)
     */
    public function getAllChildren()
    {
        $children = collect([$this]);
        
        foreach ($this->children as $child) {
            $children = $children->merge($child->getAllChildren());
        }
        
        return $children;
    }
}
