<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceDocRepoSetting extends Model
{
    protected $table = 'source_doc_repo_settings';

    protected $fillable = ['customer_id', 'repository', 'hidden', 'updated_by'];

    protected $casts = [
        'hidden' => 'boolean',
    ];
}
