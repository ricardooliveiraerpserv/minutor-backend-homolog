<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * De-para de status HD ↔ Movidesk (por empresa). Ver migration de criação/seed.
 * Sem BelongsToCompany de propósito: a integração roda fora do contexto de um usuário
 * (job/scheduler) e consulta empresas explicitamente.
 */
class HelpDeskMovideskStatusMap extends Model
{
    protected $table = 'helpdesk_movidesk_status_map';

    protected $fillable = [
        'company_id', 'helpdesk_status_id',
        'movidesk_base_status', 'movidesk_status_text', 'is_outbound_default',
    ];

    protected $casts = [
        'is_outbound_default' => 'boolean',
    ];

    /**
     * ENTRADA: resolve o status HD (id) para um par (base, text) do Movidesk, dentro da empresa.
     * Casa 1º por (base + text) exato (case-insensitive), depois só por base. Null se nada casar.
     */
    public static function resolveInbound(?int $companyId, ?string $base, ?string $text): ?int
    {
        $rows = static::query()
            ->when($companyId === null, fn ($q) => $q->whereNull('company_id'))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('is_outbound_default', false)
            ->get(['helpdesk_status_id', 'movidesk_base_status', 'movidesk_status_text']);

        $base = mb_strtolower(trim((string) $base));
        $text = mb_strtolower(trim((string) $text));

        // 1) match exato base+text
        foreach ($rows as $r) {
            if ($r->movidesk_status_text !== null
                && mb_strtolower(trim($r->movidesk_base_status)) === $base
                && mb_strtolower(trim($r->movidesk_status_text)) === $text) {
                return (int) $r->helpdesk_status_id;
            }
        }
        // 2) match só por base (linha com text null)
        foreach ($rows as $r) {
            if ($r->movidesk_status_text === null
                && mb_strtolower(trim($r->movidesk_base_status)) === $base) {
                return (int) $r->helpdesk_status_id;
            }
        }
        return null;
    }
}
