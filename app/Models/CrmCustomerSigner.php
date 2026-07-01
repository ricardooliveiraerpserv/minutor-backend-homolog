<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Caderno de participantes por cliente — memória de validadores/assinantes (P-E.2.2). */
class CrmCustomerSigner extends Model
{
    protected $fillable = ['customer_id', 'name', 'email', 'cargo', 'parte', 'roles', 'is_active'];

    protected $casts = [
        'roles'     => 'array',
        'is_active' => 'boolean',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'customer_id'); }

    /**
     * Grava/atualiza o participante no caderno do cliente (merge de papéis; cargo/parte sobrescrevem).
     * Reativa entrada arquivada se o e-mail voltar a ser usado.
     */
    public static function lembrar(int $customerId, string $name, string $email, array $roles, ?string $cargo, ?string $parte): self
    {
        $email = mb_strtolower(trim($email));
        $row = static::firstOrNew(['customer_id' => $customerId, 'email' => $email]);
        $row->name = $name;
        $row->roles = array_values(array_unique(array_merge((array) ($row->roles ?? []), $roles)));
        if ($cargo !== null && $cargo !== '') $row->cargo = $cargo;
        if ($parte !== null && $parte !== '') $row->parte = $parte;
        $row->is_active = true;
        $row->save();
        return $row;
    }
}
