<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceDocCustomerSetting extends Model
{
    protected $table = 'source_doc_customer_settings';

    protected $fillable = ['customer_id', 'own_source', 'hidden', 'updated_by'];

    protected $casts = [
        'own_source' => 'boolean',
        'hidden' => 'boolean',
    ];
}
