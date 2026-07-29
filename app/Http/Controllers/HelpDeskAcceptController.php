<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketEvent;
use App\Services\HelpDeskSlaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Aceite/Recusa da solução pelo CLIENTE direto do e-mail — SEM login no Minutor.
 * O acesso é garantido por LINK ASSINADO temporário (magic link); nenhuma sessão é necessária.
 *
 * Segurança: os botões do e-mail abrem uma PÁGINA de confirmação (GET, sem efeito colateral) e a
 * ação (encerrar / reabrir) só ocorre no POST confirmado — evita que scanners/pré-carregadores de
 * e-mail acionem o encerramento por engano. NÃO altera o motor de gatilhos nem o fluxo interno.
 */
class HelpDeskAcceptController extends Controller
{
    private const BRAND = '#7c3aed';

    /** Link assinado (30 dias) da tela de aceite/recusa — usado nos botões do e-mail. */
    public static function actionUrl(int $ticketId, string $acao): string
    {
        // O link precisa apontar SEMPRE para o host público do backend (APP_URL). A request
        // que gera o e-mail chega proxiada pelo frontend (host minutor-frontend + porta interna
        // :10000), então sem forçar o root o link sairia inacessível ao cliente (ERR_CONNECTION_RESET).
        // A assinatura confere na validação porque o clique bate direto no backend (TrustProxies=*).
        URL::forceRootUrl(config('app.url'));
        URL::forceScheme('https');
        try {
            return URL::temporarySignedRoute('hd.accept', now()->addDays(30), ['ticket' => $ticketId, 'acao' => $acao]);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
    }

    /** Página (GET): confirmação de encerramento (verde) ou formulário de recusa (vermelho). */
    public function show(Request $request, HelpDeskTicket $ticket)
    {
        abort_unless($request->hasValidSignature(), 403, 'Link inválido ou expirado.');
        $acao = $request->query('acao') === 'reject' ? 'reject' : 'accept';

        if (!optional($ticket->status)->is_resolved) {
            return $this->page('Chamado já atualizado', '<p>Este chamado não está mais aguardando seu aceite '
                . '(status atual: <b>' . e(optional($ticket->status)->label ?: '—') . '</b>). Nenhuma ação é necessária.</p>');
        }

        $num = e($ticket->ticket_number ?: ('#' . $ticket->id));
        if ($acao === 'reject') {
            $post = URL::temporarySignedRoute('hd.reject.do', now()->addDays(30), ['ticket' => $ticket->id]);
            $body = '<p>Conte pra gente o que faltou na solução do chamado <b>' . $num . '</b>. '
                . 'Ao enviar, o chamado volta para <b>Em atendimento</b> e nossa equipe retoma o tratamento.</p>'
                . '<form method="post" action="' . e($post) . '" style="margin-top:16px">'
                . '<textarea name="reason" required maxlength="2000" rows="5" placeholder="Descreva o que não resolveu…" '
                . 'style="width:100%;box-sizing:border-box;padding:12px;border:1px solid #d1d5db;border-radius:10px;font-size:15px;font-family:inherit"></textarea>'
                . '<button type="submit" style="margin-top:14px;background:#ef4444;color:#fff;border:0;border-radius:10px;padding:14px 28px;font-size:15px;font-weight:700;cursor:pointer">Enviar recusa</button>'
                . '</form>';
            return $this->page('Recusar solução · ' . $num, $body);
        }

        $post = URL::temporarySignedRoute('hd.accept.do', now()->addDays(30), ['ticket' => $ticket->id]);
        $reject = self::actionUrl($ticket->id, 'reject');
        $body = '<p>Você está confirmando que a solução do chamado <b>' . $num . '</b> resolveu. '
            . 'Ao confirmar, o chamado será <b>encerrado</b>.</p>'
            . '<form method="post" action="' . e($post) . '" style="margin-top:16px">'
            . '<button type="submit" style="background:#16a34a;color:#fff;border:0;border-radius:10px;padding:14px 28px;font-size:15px;font-weight:700;cursor:pointer">Confirmar encerramento</button>'
            . '</form>'
            . '<p style="margin-top:16px;font-size:13px"><a href="' . e($reject) . '" style="color:#ef4444">A solução não resolveu? Recusar e reabrir</a></p>';
        return $this->page('Confirmar encerramento · ' . $num, $body);
    }

    /** POST: encerra o chamado (aceite). */
    public function accept(Request $request, HelpDeskTicket $ticket)
    {
        abort_unless($request->hasValidSignature(), 403, 'Link inválido ou expirado.');
        if (!optional($ticket->status)->is_resolved) {
            return $this->page('Chamado já atualizado', '<p>Este chamado não está mais aguardando aceite. Obrigado!</p>');
        }
        $fechado = HelpDeskStatus::where('key', 'fechado')->first();
        abort_unless($fechado, 500, 'Status "fechado" não configurado.');
        $old = $ticket->status;
        $ticket->status_id = $fechado->id;
        if (!$ticket->closed_at) $ticket->closed_at = now();
        $ticket->last_activity_at = now();
        app(HelpDeskSlaService::class)->computeBreaches($ticket);
        $ticket->save();
        HelpDeskTicketEvent::log($ticket->id, 'closed', ['to_value' => $fechado->label, 'meta' => ['via' => 'email', 'aceite_cliente' => true]]);
        HelpDeskTicketEvent::log($ticket->id, 'status_changed', ['field' => 'status', 'from_value' => $old?->key, 'to_value' => $fechado->key, 'meta' => ['via' => 'email']]);

        // Cliente aceitou pelo e-mail → dispara os gatilhos de encerramento (ex.: "Chamado encerrado
        // → cliente") pra enviar o e-mail de encerramento. Sem actor_email: o próprio cliente recebe.
        // CompanyContext fixado na empresa do chamado → não casa o gatilho duplicado de outra empresa.
        try {
            $companyCtx = app(\App\Services\CompanyContext::class);
            $companyCtx->set($ticket->company_id);
            try {
                \App\Services\HelpDeskTriggerEngine::dispatch('status_changed', $ticket->fresh(), ['via' => 'email_aceite']);
            } finally {
                $companyCtx->forget();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('HelpDesk: e-mail de encerramento (aceite) falhou: ' . $e->getMessage());
        }

        return $this->page('Chamado encerrado', '<p>Prontinho! O chamado <b>' . e($ticket->ticket_number ?: ('#' . $ticket->id))
            . '</b> foi encerrado. Obrigado pelo retorno. 👍</p><p style="color:#6b7280;font-size:13px;margin-top:12px">Você já pode fechar esta página.</p>');
    }

    /** POST: recusa a solução com motivo → chamado volta para "Em atendimento". */
    public function reject(Request $request, HelpDeskTicket $ticket)
    {
        abort_unless($request->hasValidSignature(), 403, 'Link inválido ou expirado.');
        $reason = trim((string) $request->input('reason'));
        if ($reason === '') {
            return $this->page('Informe o motivo', '<p>Por favor, descreva o que não resolveu para reabrirmos o chamado. '
                . 'Volte ao e-mail e clique novamente em "Recusar".</p>');
        }
        if (!optional($ticket->status)->is_resolved) {
            return $this->page('Chamado já atualizado', '<p>Este chamado não está mais aguardando aceite. Nossa equipe já pode ter retomado o atendimento.</p>');
        }
        $em = HelpDeskStatus::where('key', 'em_andamento')->first();
        abort_unless($em, 500, 'Status "em atendimento" não configurado.');
        $old = $ticket->status;
        $ticket->status_id   = $em->id;
        $ticket->reopened_at = now();
        $ticket->resolved_at = null;
        $ticket->reopen_count = (int) $ticket->reopen_count + 1;
        $ticket->last_activity_at = now();
        app(HelpDeskSlaService::class)->computeBreaches($ticket);
        $ticket->save();
        $comment = $ticket->comments()->create([
            'author_user_id' => null, // cliente pelo e-mail (sem login) — interação do cliente
            'body'           => 'Solução recusada pelo cliente: ' . mb_substr($reason, 0, 2000),
            'visibility'     => 'customer',
            'channel'        => 'email',
        ]);
        HelpDeskTicketEvent::log($ticket->id, 'reopened', ['to_value' => $em->label, 'meta' => ['via' => 'email', 'motivo' => $reason]]);
        HelpDeskTicketEvent::log($ticket->id, 'status_changed', ['field' => 'status', 'from_value' => $old?->key, 'to_value' => $em->key, 'meta' => ['via' => 'email']]);
        HelpDeskTicketEvent::log($ticket->id, 'comment', ['meta' => ['comment_id' => $comment->id, 'via' => 'email']]);

        return $this->page('Recebemos sua recusa', '<p>Obrigado pelo retorno. O chamado <b>' . e($ticket->ticket_number ?: ('#' . $ticket->id))
            . '</b> voltou para <b>Em atendimento</b> e nossa equipe já foi avisada para dar continuidade.</p>'
            . '<p style="color:#6b7280;font-size:13px;margin-top:12px">Você já pode fechar esta página.</p>');
    }

    /** Página HTML branded (autossuficiente) — sem dependências externas. */
    private function page(string $title, string $bodyHtml)
    {
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title></head>'
            . '<body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
            . '<div style="max-width:520px;margin:6vh auto;padding:0 16px">'
            . '<div style="background:#fff;border:1px solid #e6e8ec;border-radius:12px;overflow:hidden">'
            . '<div style="height:4px;background:' . self::BRAND . '"></div>'
            . '<div style="padding:26px 28px">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#8a94a6">Central de Atendimento · ERPSERV</div>'
            . '<h1 style="margin:8px 0 14px;font-size:20px;color:#111827">' . e($title) . '</h1>'
            . '<div style="font-size:15px;line-height:1.6">' . $bodyHtml . '</div>'
            . '</div></div>'
            . '<div style="text-align:center;font-size:12px;color:#9ca3af;margin-top:16px">Mensagem da Central de Atendimento ERPSERV · Minutor</div>'
            . '</div></body></html>';
        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
