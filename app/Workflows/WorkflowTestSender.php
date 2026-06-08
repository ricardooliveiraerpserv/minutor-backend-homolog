<?php

namespace App\Workflows;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Envia um e-mail de TESTE de um workflow para um endereço, usando o modelo
 * (título + texto) atual e variáveis de exemplo. Mantém o layout padrão.
 */
class WorkflowTestSender
{
    /** Valores de exemplo por variável (fallback = [descrição]). */
    private const SAMPLES = [
        'codigo' => 'ABC123-25', 'cliente' => 'Cliente Exemplo', 'projeto' => 'Projeto Exemplo',
        'horas' => '40,00', 'saldo' => '620,00 h', 'data' => null /* hoje */,
        'de' => 'Backlog', 'para' => 'Em análise', 'autor' => 'Maria Souza', 'titulo' => 'Card Exemplo',
        'periodo' => '05/2026', 'consultor' => 'Consultor Exemplo', 'parceiro' => 'Parceiro Exemplo',
        'status' => 'Rejeitado', 'motivo' => 'Ajustar a descrição.', 'assunto' => 'Retorno do cliente',
    ];

    public function __construct(private WorkflowConfigService $config) {}

    public function send(string $key, string $email): void
    {
        $meta = (array) (config('workflows.workflows', [])[$key] ?? []);
        $tpl  = $this->config->template($key);

        // Variáveis de exemplo a partir do que o modelo declara.
        $vars = [];
        $dados = [];
        foreach (($tpl['variables'] ?? []) as $var => $desc) {
            $sample = array_key_exists($var, self::SAMPLES)
                ? (self::SAMPLES[$var] ?? now()->format('d/m/Y'))
                : "[{$desc}]";
            $vars[$var] = $sample;
            $dados[$desc] = $sample;
        }

        $subject = WorkflowConfigService::render($tpl['subject'], $vars) ?: ($meta['label'] ?? $key);
        $corpo   = WorkflowConfigService::render($tpl['body'], $vars);

        $html = view('emails.workflow', [
            'titulo'  => $meta['label'] ?? $key,
            'eyebrow' => 'TESTE • ' . ($meta['domain'] ?? 'Workflow'),
            'corpo'   => $corpo,
            'dados'   => $dados,
            'cardUrl' => rtrim((string) config('app.frontend_url', 'https://app.minutor.com.br'), '/'),
            'rodape'  => "E-mail de TESTE do workflow \"{$subject}\". Os dados acima são apenas exemplos.",
        ])->render();

        $this->dispatch($email, "[Minutor • TESTE] {$subject}", $html);
    }

    /**
     * Entrega: usa Microsoft Graph quando há credenciais (funciona local, onde o
     * mailer é 'log'); caso contrário, o mailer padrão da aplicação (prod).
     */
    private function dispatch(string $to, string $subject, string $html): void
    {
        $tenant = config('workflows.graph.tenant', env('GRAPH_TENANT_ID'));
        $client = config('workflows.graph.client', env('GRAPH_CLIENT_ID'));
        $secret = env('GRAPH_CLIENT_SECRET');
        $mailbox = env('GRAPH_MAILBOX', env('MAIL_FROM_ADDRESS'));

        if ($tenant && $client && $secret && $mailbox) {
            $token = Http::asForm()->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'client_id' => $client, 'client_secret' => $secret,
                'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
            ])->json('access_token');

            if ($token) {
                $resp = Http::withToken($token)->post("https://graph.microsoft.com/v1.0/users/{$mailbox}/sendMail", [
                    'message' => [
                        'subject' => $subject,
                        'body' => ['contentType' => 'HTML', 'content' => $html],
                        'toRecipients' => [['emailAddress' => ['address' => $to]]],
                    ],
                    'saveToSentItems' => false,
                ]);
                if ($resp->successful()) {
                    return;
                }
            }
        }

        // Fallback: mailer padrão (em prod, Graph; em dev sem creds, log).
        Mail::html($html, fn ($m) => $m->to($to)->subject($subject));
    }
}
