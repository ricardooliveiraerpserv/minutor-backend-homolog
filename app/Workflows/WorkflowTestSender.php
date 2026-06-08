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
        // Microsoft Graph é o canal que entrega de fato (igual reajuste/fechamento).
        $graphFrom = config('services.graph.mailbox', env('GRAPH_MAILBOX', env('MAIL_FROM_ADDRESS')));
        if (\App\Services\GraphMailer::enabled() && $graphFrom) {
            \App\Services\GraphMailer::sendAs($graphFrom, [$to], [], $subject, $html);
            return;
        }

        // Fallback: mailer padrão da aplicação.
        Mail::html($html, fn ($m) => $m->to($to)->subject($subject));
    }
}
