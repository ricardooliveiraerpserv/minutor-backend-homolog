<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Help Desk — Campo de um formulário. ftype: section|text|richtext|checkbox|date|time. */
class HelpDeskFormField extends Model
{
    protected $table = 'helpdesk_form_fields';

    protected $fillable = ['form_id', 'order_index', 'key', 'ftype', 'label', 'hint', 'required', 'min_chars'];

    protected $casts = ['order_index' => 'integer', 'required' => 'boolean', 'min_chars' => 'integer'];

    public function form(): BelongsTo { return $this->belongsTo(HelpDeskForm::class, 'form_id'); }
}
