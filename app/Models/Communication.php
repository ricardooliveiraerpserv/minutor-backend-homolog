<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Registro (histórico) de um envio de comunicação externa a clientes. */
class Communication extends Model
{
    protected $table = 'communications';

    protected $fillable = [
        'tipo_comunicacao', 'title', 'message', 'structure', 'customer_ids', 'user_ids', 'external_emails',
        'all_customers', 'expires_at', 'lista_distribuicao_id', 'recipients_count', 'sent_by',
    ];

    protected $casts = [
        'customer_ids'    => 'array',
        'user_ids'        => 'array',
        'external_emails' => 'array',
        'structure'       => 'array',
        'all_customers'   => 'boolean',
        'expires_at'      => 'datetime',
    ];

    public const TIPOS = ['aviso', 'formal', 'marketing'];

    public function sentBy(): BelongsTo { return $this->belongsTo(User::class, 'sent_by'); }

    public function reads(): HasMany { return $this->hasMany(CommunicationRead::class); }

    /** Comunicados ainda visíveis (sem data de expiração OU expiração futura). */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Comunicados que um usuário-cliente pode ver (todos, pelo seu id, pelo customer dele, ou pelo seu e-mail avulso). */
    public function scopeForClient(Builder $q, User $u): Builder
    {
        return $q->where(function ($w) use ($u) {
            $w->where('all_customers', true)
              ->orWhereJsonContains('user_ids', $u->id);
            if ($u->customer_id) $w->orWhereJsonContains('customer_ids', $u->customer_id);
            // Também aparece se foi enviado ao e-mail do usuário (mesmo via campo "e-mails avulsos").
            if ($u->email) $w->orWhereJsonContains('external_emails', mb_strtolower(trim($u->email)));
        });
    }
}
