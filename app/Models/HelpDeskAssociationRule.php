<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Regra de associação domínio → organização/perfil. PEGADINHA: $table explícito. */
class HelpDeskAssociationRule extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    use SoftDeletes;

    protected $table = 'helpdesk_association_rules';

    protected $fillable = ['domain', 'customer_id', 'access_profile_id', 'classification', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function accessProfile(): BelongsTo { return $this->belongsTo(HelpDeskAccessProfile::class, 'access_profile_id'); }

    /** Normaliza um domínio (minúsculo, sem @, sem espaços). */
    public static function normalize(string $domain): string
    {
        $d = strtolower(trim($domain));
        if (str_contains($d, '@')) $d = substr(strrchr($d, '@'), 1);
        return $d;
    }

    /** Resolve a regra (habilitada) a partir de um e-mail completo — p/ ingestão futura. */
    public static function matchEmail(string $email): ?self
    {
        $domain = self::normalize($email);
        return $domain === '' ? null : static::where('enabled', true)->where('domain', $domain)->first();
    }
}
