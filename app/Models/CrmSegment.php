<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmSegment extends Model
{
    protected $fillable = ['name', 'ordem', 'active'];
    protected $casts = ['active' => 'boolean', 'ordem' => 'integer'];
}
