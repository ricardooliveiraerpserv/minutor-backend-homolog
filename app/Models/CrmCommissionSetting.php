<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Política Padrão de comissão da empresa (singleton por company_id). */
class CrmCommissionSetting extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = ['company_id', 'percentual_padrao', 'base_calculo', 'pagamento', 'forma_calculo'];
    protected $casts = ['percentual_padrao' => 'decimal:2'];
}
