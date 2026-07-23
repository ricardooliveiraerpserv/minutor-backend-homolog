<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Help Desk — Formulário estruturado vinculado a um status (construtor de formulários). */
class HelpDeskForm extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $table = 'helpdesk_forms';

    protected $fillable = ['name', 'status_id', 'title', 'subtitle', 'intro', 'show_logo', 'active', 'locked'];

    protected $casts = ['show_logo' => 'boolean', 'active' => 'boolean', 'locked' => 'boolean'];

    public function status(): BelongsTo { return $this->belongsTo(HelpDeskStatus::class, 'status_id'); }
    public function fields(): HasMany { return $this->hasMany(HelpDeskFormField::class, 'form_id')->orderBy('order_index'); }
}
