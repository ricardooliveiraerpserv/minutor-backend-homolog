<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuração dos campos cadastrais do formulário por tipo (editável pelo
 * admin na rotina de Configuração de Formulários).
 */
class SkillFormConfig extends Model
{
    protected $fillable = ['type', 'fields'];

    protected $casts = ['fields' => 'array'];
}
