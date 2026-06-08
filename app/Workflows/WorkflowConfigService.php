<?php

namespace App\Workflows;

use App\Models\WorkflowExtraEmail;
use App\Models\WorkflowRecipient;
use Illuminate\Support\Facades\DB;

/**
 * Leitura/escrita da configuração dos workflows para a Central (admin).
 */
class WorkflowConfigService
{
    public function __construct(private WorkflowRecipientResolver $resolver) {}

    /** Catálogo de audiências (key => label). */
    public function audiences(): array
    {
        return (array) config('workflows.audiences', []);
    }

    /**
     * Lista todos os workflows do registry com a config EFETIVA (override ou default)
     * + e-mails extras, agrupável por domínio no FE.
     */
    public function all(): array
    {
        $audienceLabels = $this->audiences();
        $extras = WorkflowExtraEmail::all()->groupBy('workflow_key');

        $out = [];
        foreach ((array) config('workflows.workflows', []) as $key => $meta) {
            $channels = $this->resolver->channels($key);

            $audiences = [];
            foreach (($meta['audiences'] ?? []) as $aud => $default) {
                $audiences[] = [
                    'audience' => $aud,
                    'label'    => $audienceLabels[$aud] ?? $aud,
                    'channel'  => $channels[$aud] ?? $default, // efetivo
                    'default'  => $default,
                ];
            }

            $out[] = [
                'key'         => $key,
                'label'       => $meta['label'] ?? $key,
                'domain'      => $meta['domain'] ?? 'Outros',
                'description' => $meta['description'] ?? null,
                'audiences'   => $audiences,
                'extra_emails' => ($extras[$key] ?? collect())
                    ->map(fn ($x) => ['email' => $x->email, 'channel' => $x->channel])
                    ->values()->all(),
            ];
        }
        return $out;
    }

    /**
     * Salva a config de um workflow: canais por audiência + e-mails extras.
     *
     * @param array<string,string> $audiences audience => off|to|cc
     * @param array<int,array{email:string,channel:string}> $extraEmails
     */
    public function save(string $key, array $audiences, array $extraEmails): void
    {
        $workflows = (array) config('workflows.workflows', []);
        $meta = $workflows[$key] ?? null;
        if (!$meta) {
            abort(404, 'Workflow desconhecido.');
        }
        $validAudiences = array_keys($meta['audiences'] ?? []);

        DB::transaction(function () use ($key, $audiences, $extraEmails, $validAudiences) {
            WorkflowRecipient::where('workflow_key', $key)->delete();
            foreach ($audiences as $aud => $channel) {
                if (!in_array($aud, $validAudiences, true)) {
                    continue;
                }
                $channel = in_array($channel, ['off', 'to', 'cc'], true) ? $channel : 'off';
                WorkflowRecipient::create([
                    'workflow_key' => $key,
                    'audience'     => $aud,
                    'channel'      => $channel,
                ]);
            }

            WorkflowExtraEmail::where('workflow_key', $key)->delete();
            foreach ($extraEmails as $x) {
                $email = strtolower(trim((string) ($x['email'] ?? '')));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $channel = in_array(($x['channel'] ?? 'cc'), ['to', 'cc'], true) ? $x['channel'] : 'cc';
                WorkflowExtraEmail::create([
                    'workflow_key' => $key,
                    'email'        => $email,
                    'channel'      => $channel,
                ]);
            }
        });
    }
}
