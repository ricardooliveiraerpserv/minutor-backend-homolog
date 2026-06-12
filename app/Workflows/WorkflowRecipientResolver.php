<?php

namespace App\Workflows;

use App\Models\WorkflowExtraEmail;
use App\Models\WorkflowRecipient;

/**
 * Resolve os destinatários (To/Cc) de um workflow a partir da chave + contexto.
 *
 * Fonte da config: overrides em `workflow_recipients` (se houver linhas para a
 * chave) ou os defaults do registry (config/workflows.php). E-mails fixos extras
 * vêm de `workflow_extra_emails`. Cc é deduplicado contra o To.
 */
class WorkflowRecipientResolver
{
    public function __construct(private AudienceResolver $audiences) {}

    /**
     * @return array{to: array<int,string>, cc: array<int,string>}
     */
    public function resolve(string $key, array $ctx = []): array
    {
        $to = [];
        $cc = [];

        foreach ($this->channels($key) as $audience => $channel) {
            if ($channel !== 'to' && $channel !== 'cc') {
                continue;
            }
            $emails = $this->audiences->emails($audience, $ctx);
            if ($channel === 'to') {
                $to = array_merge($to, $emails);
            } else {
                $cc = array_merge($cc, $emails);
            }
        }

        // E-mails fixos extras configurados na Central.
        foreach (WorkflowExtraEmail::where('workflow_key', $key)->get() as $x) {
            if ($x->channel === 'to') {
                $to[] = $x->email;
            } else {
                $cc[] = $x->email;
            }
        }

        $to = $this->norm($to);
        $cc = array_values(array_diff($this->norm($cc), $to));

        // Nunca enviar com To vazio: se só sobrou Cc (ex.: aporte em filho, sem
        // cliente), os de cópia viram destinatários principais.
        if (empty($to) && !empty($cc)) {
            $to = $cc;
            $cc = [];
        }

        return ['to' => $to, 'cc' => $cc];
    }

    /**
     * Canais efetivos por audiência: override do banco OU default do registry.
     *
     * @return array<string, string> audience => channel(off|to|cc)
     */
    public function channels(string $key): array
    {
        $rows = WorkflowRecipient::where('workflow_key', $key)->get();
        if ($rows->isNotEmpty()) {
            return $rows->pluck('channel', 'audience')->all();
        }
        // chaves de workflow têm ponto → indexar o array (config() dot-notation quebra).
        $workflows = (array) config('workflows.workflows', []);
        return $workflows[$key]['audiences'] ?? [];
    }

    private function norm(array $emails): array
    {
        return collect($emails)
            ->filter()
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->filter(fn ($e) => $e !== '')
            ->unique()
            ->values()
            ->all();
    }
}
