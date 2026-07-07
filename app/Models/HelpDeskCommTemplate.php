<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Template institucional de comunicação (linha única). PEGADINHA: $table explícito. */
class HelpDeskCommTemplate extends Model
{
    protected $table = 'helpdesk_comm_template';

    protected $fillable = [
        'company_name', 'primary_color', 'font', 'button_label', 'footer_text', 'signature', 'show_minutor',
        'ticket_prefix', 'ticket_padding', 'ticket_sequence',
    ];

    protected $casts = ['show_minutor' => 'boolean', 'ticket_padding' => 'integer', 'ticket_sequence' => 'integer'];

    protected $appends = ['ticket_next'];

    /** Próximo número (p/ exibir/editar o "iniciador"). */
    public function getTicketNextAttribute(): int
    {
        return (int) $this->ticket_sequence + 1;
    }

    /** A configuração atual (cria com defaults se não existir). */
    public static function current(): self
    {
        return static::first() ?? static::create([]);
    }
}
