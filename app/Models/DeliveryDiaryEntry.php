<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entrada do "Diário da Atividade" (por entrega do cronograma).
 * Texto (opcional) + anexos (via FASE 11, entity_type = DELIVERY_DIARY_ENTRY).
 */
class DeliveryDiaryEntry extends Model
{
    use SoftDeletes;

    protected $fillable = ['delivery_id', 'user_id', 'body'];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(StageDelivery::class, 'delivery_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Anexos desta entrada (infra FASE 11). SoftDeletes do Attachment já filtra apagados. */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'entity_id')
            ->where('entity_type', self::attachmentEntityType());
    }

    public static function attachmentEntityType(): string
    {
        return 'DELIVERY_DIARY_ENTRY';
    }
}
