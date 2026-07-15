<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Perfil de assinatura reutilizável por e-mail (P-E.2.4). */
class CrmSignatureProfile extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = ['email', 'name', 'cpf', 'cargo', 'image', 'times_used', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime', 'times_used' => 'integer'];

    /** Salva/atualiza o perfil pela 1ª assinatura (ou reuso). Mantém dados antigos se vierem vazios. */
    public static function lembrar(?string $email, ?string $name, ?string $cpf, ?string $cargo, ?string $image): ?self
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') return null;
        $row = static::firstOrNew(['email' => $email]);
        if ($name) $row->name = $name;
        if ($cpf) $row->cpf = $cpf;
        if ($cargo) $row->cargo = $cargo;
        if ($image && str_starts_with($image, 'data:image/')) $row->image = $image;
        $row->times_used = (int) $row->times_used + 1;
        $row->last_used_at = now();
        $row->save();
        return $row;
    }

    public static function porEmail(?string $email): ?self
    {
        $email = mb_strtolower(trim((string) $email));
        return $email === '' ? null : static::where('email', $email)->first();
    }
}
