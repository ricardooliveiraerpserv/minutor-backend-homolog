<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Registro (histórico) de um envio de comunicação externa a clientes. */
class Communication extends Model
{
    use \App\Models\Concerns\BelongsToCompany;   // isola por empresa ativa + carimba company_id

    protected $table = 'communications';

    protected $fillable = [
        'from_email',
        'tipo_comunicacao', 'title', 'message', 'structure', 'customer_ids', 'user_ids', 'external_emails',
        'all_customers', 'expires_at', 'lista_distribuicao_id', 'recipients_count', 'sent_by', 'hd_profile_ids', 'company_id',
    ];

    /** Vídeo opcional: o caminho fica oculto; o front recebe só o link ASSINADO. */
    protected $hidden = ['video_path'];
    protected $appends = ['video_url'];

    public function getVideoUrlAttribute(): ?string
    {
        if (empty($this->attributes['video_path'] ?? null)) return null;
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('communications.video', now()->addHours(12), [
            'communication' => $this->id, 'v' => substr(md5((string) $this->attributes['video_path']), 0, 8),
        ]);
    }

    protected $casts = [
        'customer_ids'    => 'array',
        'user_ids'        => 'array',
        'external_emails' => 'array',
        'hd_profile_ids'  => 'array',
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
            // Endereçado ao usuário (id ou e-mail avulso): sempre vê.
            $w->whereJsonContains('user_ids', $u->id);
            if ($u->email) $w->orWhereJsonContains('external_emails', mb_strtolower(trim($u->email)));
            // Por público amplo (todos / empresa): respeita o filtro de Perfil do Help Desk, se houver.
            $w->orWhere(function ($b) use ($u) {
                $b->where(function ($x) use ($u) {
                    $x->where('all_customers', true);
                    if ($u->customer_id) $x->orWhereJsonContains('customer_ids', $u->customer_id);
                })->where(function ($x) use ($u) {
                    $x->whereNull('hd_profile_ids')->orWhereRaw("jsonb_array_length(hd_profile_ids::jsonb) = 0");
                    if ($u->helpdesk_access_profile_id) $x->orWhereJsonContains('hd_profile_ids', (int) $u->helpdesk_access_profile_id);
                });
            });
        });
    }
}
