<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Gatilho (automação) do Help Desk. PEGADINHA: $table explícito. */
class HelpDeskTrigger extends Model
{
    use SoftDeletes;

    protected $table = 'helpdesk_triggers';

    protected $fillable = [
        'name', 'enabled', 'event', 'condition_logic', 'conditions', 'actions',
        'recipe', 'run_order', 'created_by_id',
    ];

    protected $casts = [
        'enabled'    => 'boolean',
        'conditions' => 'array',
        'actions'    => 'array',
        'run_order'  => 'integer',
    ];

    /** Eventos que disparam avaliação dos gatilhos. */
    public const EVENTS = [
        'ticket_created'  => 'Quando um chamado é aberto',
        'comment_added'   => 'Quando há uma interação (resposta)',
        'status_changed'  => 'Quando o status muda',
        'field_changed'   => 'Quando um campo é alterado',
        'assigned'        => 'Quando o responsável muda',
        'idle_in_status'  => 'Quando fica parado num status por X tempo',
        'merged'          => 'Quando chamados são mesclados',
    ];

    public const OPERATORS = [
        'eq' => 'é', 'neq' => 'não é', 'in' => 'é um de', 'not_in' => 'não é nenhum de',
        'contains' => 'contém', 'not_contains' => 'não contém', 'starts_with' => 'começa com',
        'gte' => '≥ (no mínimo)', 'lte' => '≤ (no máximo)',
        'is_true' => 'sim', 'is_false' => 'não',
    ];

    /**
     * Catálogo de condições — campo, rótulo, tipo (define o controle no builder),
     * fonte de opções e operadores válidos. É o que dá as "infinitas possibilidades".
     *
     * type: text | number | bool | enum | select. source: chave da lista em meta().
     *
     * @return array<int, array<string,mixed>>
     */
    public static function conditionCatalog(): array
    {
        return [
            ['key' => 'subject',                 'label' => 'Assunto',                    'type' => 'text',   'operators' => ['contains', 'not_contains', 'starts_with', 'eq', 'neq']],
            ['key' => 'description',             'label' => 'Descrição / mensagem',       'type' => 'text',   'operators' => ['contains', 'not_contains']],
            ['key' => 'channel',                 'label' => 'Aberto via (canal)',         'type' => 'enum',   'source' => 'channels',       'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['key' => 'received_account',        'label' => 'Recebido em (conta de e-mail)', 'type' => 'select', 'source' => 'accounts',    'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['key' => 'requester_email',         'label' => 'Solicitante (e-mail)',       'type' => 'text',   'operators' => ['eq', 'neq', 'contains', 'not_contains']],
            ['key' => 'status_id',               'label' => 'Status',                     'type' => 'select', 'source' => 'statuses',       'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['key' => 'priority',                'label' => 'Urgência',                   'type' => 'enum',   'source' => 'priorities',     'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['key' => 'level',                   'label' => 'Nível',                      'type' => 'text',   'operators' => ['eq', 'neq']],
            ['key' => 'category_id',             'label' => 'Categoria',                  'type' => 'select', 'source' => 'categories',     'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['key' => 'service_id',              'label' => 'Serviço',                    'type' => 'select', 'source' => 'services',       'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['key' => 'team_id',                 'label' => 'Equipe',                     'type' => 'select', 'source' => 'teams',          'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['key' => 'assignee_id',             'label' => 'Responsável',                'type' => 'select', 'source' => 'agents',         'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['key' => 'has_assignee',            'label' => 'Tem responsável',            'type' => 'bool',   'operators' => ['is_true', 'is_false']],
            ['key' => 'is_reassignment',         'label' => 'É transferência (já tinha responsável)', 'type' => 'bool', 'operators' => ['is_true', 'is_false']],
            ['key' => 'customer_id',             'label' => 'Cliente (ID)',               'type' => 'number', 'operators' => ['eq', 'neq']],
            ['key' => 'justification_id',        'label' => 'Justificativa',              'type' => 'select', 'source' => 'justifications', 'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['key' => 'has_tag',                 'label' => 'Tem a tag',                  'type' => 'select', 'source' => 'tags',           'operators' => ['eq', 'neq']],
            ['key' => 'first_response_breached', 'label' => 'SLA 1ª resposta estourado',  'type' => 'bool',   'operators' => ['is_true', 'is_false']],
            ['key' => 'resolution_breached',     'label' => 'SLA resolução estourado',    'type' => 'bool',   'operators' => ['is_true', 'is_false']],
            ['key' => 'reopen_count',            'label' => 'Nº de reaberturas',          'type' => 'number', 'operators' => ['gte', 'lte', 'eq']],
            ['key' => 'comment_by',              'label' => 'Interação feita por',        'type' => 'enum',   'source' => 'commentBy',      'operators' => ['eq', 'neq']],
            ['key' => 'visibility',              'label' => 'Visibilidade da interação',  'type' => 'enum',   'source' => 'visibilities',   'operators' => ['eq', 'neq']],
            ['key' => 'is_continuation',         'label' => 'É continuação (de chamado encerrado)', 'type' => 'bool', 'operators' => ['is_true', 'is_false']],
            ['key' => 'idle_hours',              'label' => 'Tempo parado (horas)',       'type' => 'number', 'operators' => ['gte', 'lte']],
        ];
    }

    /** Tipos de ação suportados. */
    public const ACTION_TYPES = [
        'send_email'    => 'Enviar e-mail',
        'change_status' => 'Mudar status',
        'set_field'     => 'Alterar campo',
        'add_tag'       => 'Adicionar tag',
        'remove_tag'    => 'Remover tag',
        'assign'        => 'Atribuir responsável/equipe',
    ];

    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_id'); }
}
