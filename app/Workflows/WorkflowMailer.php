<?php

namespace App\Workflows;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envio REAL de um workflow: resolve os destinatários (Central) e renderiza o
 * modelo editável (assunto/corpo + variáveis) no layout padrão `emails.workflow`,
 * entregando via Microsoft Graph (mesmo canal de fechamento/reajuste) com fallback
 * para o mailer padrão. Espelha o WorkflowTestSender, mas com dados reais.
 */
class WorkflowMailer
{
    public function __construct(
        private WorkflowConfigService $config,
        private WorkflowRecipientResolver $resolver,
    ) {}

    /**
     * @param string $key   Chave do workflow (config/workflows.php).
     * @param array  $ctx   Contexto p/ resolver audiências (contract, project, actor, ...).
     * @param array  $vars  Valores das variáveis do template (ex.: ['valor' => 'R$ 100,00']).
     */
    public function send(string $key, array $ctx, array $vars, ?string $ctaUrl = null): bool
    {
        $rcpt = $this->resolver->resolve($key, $ctx);
        if (empty($rcpt['to'])) {
            return false;
        }

        ['subject' => $subject, 'html' => $html] = $this->renderHtml($key, $vars, $ctaUrl);

        try {
            $graphFrom = config('services.graph.mailbox', env('GRAPH_MAILBOX', env('MAIL_FROM_ADDRESS')));
            if (\App\Services\GraphMailer::enabled() && $graphFrom) {
                \App\Services\GraphMailer::sendAs($graphFrom, $rcpt['to'], $rcpt['cc'], $subject, $html);
            } else {
                Mail::html($html, function ($m) use ($rcpt, $subject) {
                    $m->to($rcpt['to'])->subject($subject);
                    if (!empty($rcpt['cc'])) {
                        $m->cc($rcpt['cc']);
                    }
                });
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning("Workflow [{$key}] falhou ao enviar", ['err' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Renderiza EXATAMENTE o assunto/HTML que seriam enviados (mesmo layout do e-mail
     * real), para exibir a prévia na tela. @return array{subject:string, html:string}
     */
    public function renderHtml(string $key, array $vars, ?string $ctaUrl = null): array
    {
        $meta = (array) (config('workflows.workflows', [])[$key] ?? []);
        $tpl  = $this->config->template($key);

        $subject = WorkflowConfigService::render($tpl['subject'], $vars) ?: ($meta['label'] ?? $key);
        $corpo   = WorkflowConfigService::render($tpl['body'], $vars);

        $dados = [];
        foreach (($tpl['variables'] ?? []) as $var => $desc) {
            if (isset($vars[$var]) && $vars[$var] !== '') {
                $dados[$desc] = $vars[$var];
            }
        }

        $html = view('emails.workflow', [
            'titulo'  => $meta['label'] ?? $key,
            'eyebrow' => $meta['domain'] ?? 'Workflow',
            'accent'  => $this->config->accent($key),
            'corpo'   => $corpo,
            'dados'   => $dados,
            'cardUrl' => $ctaUrl ?: (rtrim((string) config('app.frontend_url', 'https://app.minutor.com.br'), '/') . '/pagamento-despesas'),
            'rodape'  => null,
        ])->render();

        return ['subject' => $subject, 'html' => $html];
    }
}
