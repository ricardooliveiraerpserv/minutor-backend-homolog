<?php

namespace App\Models;

use App\Attachments\Concerns\HasGlobalAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Help Desk — Chamado (ticket). Núcleo do módulo.
 *
 * Base local: todos os vínculos (customer, contato, contrato, projeto, usuários)
 * apontam para entidades LOCAIS do Minutor. source_system/external_ref são apenas
 * ganchos para integração futura (a sincronização alimenta esta tabela).
 */
class HelpDeskTicket extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    use SoftDeletes;
    use HasGlobalAttachments;

    protected $table = 'helpdesk_tickets';

    protected $fillable = [
        'ticket_number', 'subject', 'description',
        'customer_id', 'customer_contact_id', 'requester_user_id', 'requester_name', 'requester_email', 'cc_emails', 'contract_id', 'project_id',
        'category_id', 'service_id', 'justification_id', 'status_id', 'priority', 'channel', 'level', 'assignee_id', 'team_id',
        'sla_policy_id', 'first_response_due_at', 'resolution_due_at',
        'first_responded_at', 'resolved_at', 'closed_at', 'reopened_at',
        'first_response_breached', 'resolution_breached', 'reopen_count',
        'last_activity_at', 'source_system', 'external_ref', 'graph_thread_msg_id', 'created_by_id',
        'scheduled_until', 'scheduled_all_day', 'sla_paused_at', 'sla_ever_paused',
        'reopen_scheduled_at', 'reopen_scheduled_note', 'reopen_scheduled_by_id',
        'previous_ticket_id', 'merged_into_id',
    ];

    protected $casts = [
        'first_response_due_at'   => 'datetime',
        'resolution_due_at'       => 'datetime',
        'first_responded_at'      => 'datetime',
        'resolved_at'             => 'datetime',
        'closed_at'               => 'datetime',
        'reopened_at'             => 'datetime',
        'last_activity_at'        => 'datetime',
        'scheduled_until'         => 'datetime',
        'scheduled_all_day'       => 'boolean',
        'sla_paused_at'           => 'datetime',
        'sla_ever_paused'         => 'boolean',
        'reopen_scheduled_at'     => 'datetime',
        'first_response_breached' => 'boolean',
        'resolution_breached'     => 'boolean',
        'reopen_count'            => 'integer',
        'cc_emails'               => 'array',
    ];

    public const PRIORITIES = ['baixa', 'normal', 'alta', 'urgente'];
    public const CHANNELS   = ['portal', 'email', 'telefone', 'interno', 'movidesk'];

    /** Chave no AttachableEntitiesRegistry. */
    public static function attachmentEntityType(): string
    {
        return 'HELPDESK_TICKET';
    }

    /**
     * Nome do solicitante: contato vinculado → contato cadastrado com o MESMO e-mail
     * (mesmo sem vínculo) → nome capturado do e-mail. Para exibir o nome quando o
     * solicitante já existe no cadastro, em vez do endereço cru.
     */
    public function solicitanteName(): ?string
    {
        $name = optional($this->contact)->name ?: optional($this->requester)->name;
        if (!$name && $this->requester_email) {
            $email = mb_strtolower($this->requester_email);
            // Contato de cliente OU usuário interno (agente ERPSERV também pode ser solicitante).
            $name = CustomerContact::whereRaw('lower(email) = ?', [$email])->value('name')
                ?: User::whereRaw('lower(email) = ?', [$email])->value('name');
        }
        return $name ?: $this->requester_name;
    }

    public function solicitanteEmail(): ?string
    {
        return optional($this->contact)->email ?: $this->requester_email;
    }

    // ── Vínculos locais ──────────────────────────────────────────────────────
    public function customer(): BelongsTo  { return $this->belongsTo(Customer::class); }
    public function contact(): BelongsTo   { return $this->belongsTo(CustomerContact::class, 'customer_contact_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requester_user_id'); }
    public function contract(): BelongsTo  { return $this->belongsTo(Contract::class); }
    public function project(): BelongsTo   { return $this->belongsTo(Project::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_id'); }

    // ── Classificação / roteamento ───────────────────────────────────────────
    public function category(): BelongsTo  { return $this->belongsTo(HelpDeskCategory::class, 'category_id'); }
    public function service(): BelongsTo   { return $this->belongsTo(HelpDeskService::class, 'service_id'); }
    public function justification(): BelongsTo { return $this->belongsTo(HelpDeskTicketJustification::class, 'justification_id'); }
    public function status(): BelongsTo    { return $this->belongsTo(HelpDeskStatus::class, 'status_id'); }
    /** Chamado ANTERIOR (encerrado) do qual este é continuação — resposta de e-mail a ticket fechado. */
    public function previousTicket(): BelongsTo { return $this->belongsTo(HelpDeskTicket::class, 'previous_ticket_id'); }
    /** Chamados de CONTINUAÇÃO abertos a partir DESTE (quando ele estava encerrado). */
    public function continuations(): HasMany { return $this->hasMany(HelpDeskTicket::class, 'previous_ticket_id'); }
    /** Chamado de DESTINO ao qual ESTE foi mesclado (null = não mesclado). */
    public function mergedInto(): BelongsTo { return $this->belongsTo(HelpDeskTicket::class, 'merged_into_id'); }
    /** Chamados de ORIGEM mesclados NESTE (o "Tickets Mesclados" do destino). */
    public function mergedTickets(): HasMany { return $this->hasMany(HelpDeskTicket::class, 'merged_into_id'); }
    /** Está mesclado a outro chamado? */
    public function isMerged(): bool { return $this->merged_into_id !== null; }
    public function assignee(): BelongsTo  { return $this->belongsTo(User::class, 'assignee_id'); }
    public function team(): BelongsTo      { return $this->belongsTo(HelpDeskTeam::class, 'team_id'); }
    public function slaPolicy(): BelongsTo { return $this->belongsTo(HelpDeskSlaPolicy::class, 'sla_policy_id'); }

    // ── Sub-coleções ─────────────────────────────────────────────────────────
    public function comments(): HasMany { return $this->hasMany(HelpDeskTicketComment::class, 'ticket_id'); }
    public function events(): HasMany   { return $this->hasMany(HelpDeskTicketEvent::class, 'ticket_id'); }
    public function watchers(): HasMany { return $this->hasMany(HelpDeskTicketWatcher::class, 'ticket_id'); }
    public function timesheets(): HasMany { return $this->hasMany(Timesheet::class, 'helpdesk_ticket_id'); }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(HelpDeskTag::class, 'helpdesk_ticket_tag', 'ticket_id', 'helpdesk_tag_id')
            ->withTimestamps();
    }
}
