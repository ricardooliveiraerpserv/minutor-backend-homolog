<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tipo de contato (follow-up) — cadastro configurável. */
class CrmContactType extends Model
{
    protected $table = 'crm_contact_types';

    protected $fillable = ['nome', 'slug', 'ordem', 'ativo'];

    protected $casts = ['ordem' => 'integer', 'ativo' => 'boolean'];
}
