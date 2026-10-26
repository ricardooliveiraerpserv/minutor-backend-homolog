<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um envio de e-mail de workflow (log central). Ver [[tela Envios por rotina]].
 */
class WorkflowEmailLog extends Model
{
    protected $fillable = [
        'routine_key', 'domain', 'label', 'subject',
        'recipients_to', 'recipients_cc', 'status', 'error',
        'customer_id', 'contract_id', 'project_id', 'company_id', 'sent_at',
    ];

    protected $casts = [
        'recipients_to' => 'array',
        'recipients_cc' => 'array',
        'sent_at'       => 'datetime',
    ];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function project()  { return $this->belongsTo(Project::class); }
    public function contract() { return $this->belongsTo(Contract::class); }

    /**
     * Registra um envio best-effort — NUNCA lança (não pode derrubar o envio real).
     * $ctx aceita objetos Contract/Project/Customer (ou ids) p/ vincular ao cliente.
     */
    public static function record(string $routineKey, string $status, array $data = []): void
    {
        try {
            $meta = (array) (config('workflows.workflows', [])[$routineKey] ?? []);
            static::create([
                'routine_key'   => $routineKey,
                'domain'        => $data['domain']   ?? ($meta['domain'] ?? null),
                'label'         => $data['label']    ?? ($meta['label'] ?? $routineKey),
                'subject'       => isset($data['subject']) ? mb_substr((string) $data['subject'], 0, 300) : null,
                'recipients_to' => array_values($data['to'] ?? []),
                'recipients_cc' => array_values($data['cc'] ?? []),
                'status'        => $status,
                'error'         => isset($data['error']) ? mb_substr((string) $data['error'], 0, 1000) : null,
                'customer_id'   => $data['customer_id'] ?? null,
                'contract_id'   => $data['contract_id'] ?? null,
                'project_id'    => $data['project_id'] ?? null,
                'company_id'    => $data['company_id'] ?? null,
                'sent_at'       => $data['sent_at'] ?? now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('workflow_email_log.record_failed', ['routine' => $routineKey, 'err' => $e->getMessage()]);
        }
    }

    /** Extrai customer/contract/project/company de um $ctx de workflow (best-effort). */
    public static function idsFromContext(array $ctx): array
    {
        $out = ['customer_id' => null, 'contract_id' => null, 'project_id' => null, 'company_id' => null];
        $c = $ctx['contract'] ?? null;
        $p = $ctx['project'] ?? null;
        $cust = $ctx['customer'] ?? null;
        if (is_object($c)) {
            $out['contract_id'] = $c->id ?? null;
            $out['customer_id'] = $c->customer_id ?? $out['customer_id'];
            $out['project_id']  = $c->project_id ?? $out['project_id'];
            $out['company_id']  = $c->company_id ?? $out['company_id'];
        }
        if (is_object($p)) {
            $out['project_id']  = $p->id ?? $out['project_id'];
            $out['customer_id'] = $out['customer_id'] ?? ($p->customer_id ?? null);
            $out['contract_id'] = $out['contract_id'] ?? ($p->contract_id ?? null);
            $out['company_id']  = $out['company_id'] ?? ($p->company_id ?? null);
        }
        if (is_object($cust)) {
            $out['customer_id'] = $out['customer_id'] ?? ($cust->id ?? null);
        }
        return $out;
    }
}
