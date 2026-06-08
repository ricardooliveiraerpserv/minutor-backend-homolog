<?php

namespace App\Workflows;

use App\Models\WorkflowExtraEmail;
use App\Models\WorkflowRecipient;
use App\Models\WorkflowTemplate;
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
     * Modelo EFETIVO (override do banco OU default do registry) de um workflow.
     *
     * @return array{subject:string, body:string, variables:array, default_subject:string, default_body:string}
     */
    public function template(string $key): array
    {
        $defaults = (array) (config('workflows.templates', [])[$key] ?? []);
        $row = WorkflowTemplate::where('workflow_key', $key)->first();

        return [
            'subject'         => $row->subject ?? ($defaults['subject'] ?? ''),
            'body'            => $row->body ?? ($defaults['body'] ?? ''),
            'variables'       => $defaults['variables'] ?? [],
            'default_subject' => $defaults['subject'] ?? '',
            'default_body'    => $defaults['body'] ?? '',
        ];
    }

    /** Cor de destaque (hex) de um workflow, conforme o domínio. */
    public function accent(string $key): string
    {
        $perWorkflow = config('workflows.workflow_accents', [])[$key] ?? null;
        if ($perWorkflow) {
            return $perWorkflow;
        }
        $domain = config('workflows.workflows', [])[$key]['domain'] ?? 'Outros';
        return config('workflows.domain_accents', [])[$domain] ?? '#22C55E';
    }

    /** Substitui {var} pelos valores. */
    public static function render(string $text, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        // Remove placeholders não preenchidos.
        return trim(preg_replace('/\{[a-z_]+\}/', '', $text));
    }

    /**
     * Audiências aplicáveis a um workflow: o requisito (any-of) é satisfeito pelo
     * contexto que o workflow fornece. Audiência sem requisito é sempre aplicável.
     *
     * @return array<int, string>
     */
    public function availableAudiences(string $key): array
    {
        $context  = (array) (config('workflows.context', [])[$key] ?? []);
        $requires = (array) config('workflows.audience_requires', []);

        $out = [];
        foreach (array_keys($this->audiences()) as $aud) {
            $need = (array) ($requires[$aud] ?? []);
            if (empty($need) || array_intersect($need, $context)) {
                $out[] = $aud;
            }
        }
        return $out;
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
            $channels = $this->resolver->channels($key);          // efetivo (override ou default)
            $registryDefaults = (array) ($meta['audiences'] ?? []); // recomendados do registry

            // Mostra os recomendados + qualquer audiência adicionada via Central (override).
            $audienceKeys = array_values(array_unique(array_merge(
                array_keys($registryDefaults),
                array_keys($channels),
            )));

            $audiences = [];
            foreach ($audienceKeys as $aud) {
                if (!isset($audienceLabels[$aud])) {
                    continue; // audiência fora do catálogo global
                }
                $default = $registryDefaults[$aud] ?? 'off';
                $audiences[] = [
                    'audience'    => $aud,
                    'label'       => $audienceLabels[$aud],
                    'channel'     => $channels[$aud] ?? $default,
                    'default'     => $default,
                    'recommended' => array_key_exists($aud, $registryDefaults),
                ];
            }

            // Audiências que FAZEM SENTIDO neste workflow (contexto satisfaz o requisito).
            $available = [];
            foreach ($this->availableAudiences($key) as $aud) {
                $available[] = ['audience' => $aud, 'label' => $audienceLabels[$aud] ?? $aud];
            }

            $out[] = [
                'key'         => $key,
                'label'       => $meta['label'] ?? $key,
                'domain'      => $meta['domain'] ?? 'Outros',
                'description' => $meta['description'] ?? null,
                'audiences'   => $audiences,
                'available'   => $available,
                'template'    => $this->template($key),
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
    public function save(string $key, array $audiences, array $extraEmails, ?string $subject = null, ?string $body = null): void
    {
        $workflows = (array) config('workflows.workflows', []);
        $meta = $workflows[$key] ?? null;
        if (!$meta) {
            abort(404, 'Workflow desconhecido.');
        }

        // Modelo (título + texto) — grava override quando enviado.
        if ($subject !== null || $body !== null) {
            WorkflowTemplate::updateOrCreate(
                ['workflow_key' => $key],
                ['subject' => $subject, 'body' => $body],
            );
        }
        // Qualquer audiência do catálogo global pode ser incluída em qualquer
        // workflow pela Central — sem depender de código.
        $validAudiences = array_keys($this->audiences());

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
