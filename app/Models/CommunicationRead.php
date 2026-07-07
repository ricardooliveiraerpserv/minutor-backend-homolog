<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Marca de leitura de um comunicado por um usuário-cliente. */
class CommunicationRead extends Model
{
    protected $table = 'communication_reads';

    protected $fillable = ['communication_id', 'user_id', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public $timestamps = true;
}
