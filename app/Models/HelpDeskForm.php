<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Help Desk — Formulário estruturado vinculado a um status (construtor de formulários). */
class HelpDeskForm extends Model
{
    protected $table = 'helpdesk_forms';

    protected $fillable = ['name', 'status_id', 'title', 'intro', 'show_logo', 'active'];

    protected $casts = ['show_logo' => 'boolean', 'active' => 'boolean'];

    public function status(): BelongsTo { return $this->belongsTo(HelpDeskStatus::class, 'status_id'); }
    public function fields(): HasMany { return $this->hasMany(HelpDeskFormField::class, 'form_id')->orderBy('order_index'); }
}
