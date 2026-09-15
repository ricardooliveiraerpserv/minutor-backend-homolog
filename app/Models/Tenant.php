<?php

namespace App\Models;

use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

/**
 * Registro de tenant (landlord) — vive no schema `public`.
 * `schema` = schema Postgres onde ficam as tabelas isoladas do tenant (ex.: 'conecta').
 * O grupo ERPSERV/BIZIFY é o schema `public` (sem tenant ativo).
 */
class Tenant extends SpatieTenant
{
    protected $guarded = [];
}
