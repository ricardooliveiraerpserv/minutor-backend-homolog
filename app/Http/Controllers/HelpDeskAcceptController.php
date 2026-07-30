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

    /**
     * O chamado está aguardando o aceite do cliente? SÓ então os links (aceitar E recusar)
     * valem. Após ENCERRAR (status "fechado" = is_terminal), ambos expiram — antes a trava
     * usava só is_resolved, mas "fechado" também tem is_resolved=true, deixando o Recusar ativo.
     */
    private function awaitingAcceptance(HelpDeskTicket $ticket): bool
    {
        $st = $ticket->status;
        return $st && $st->is_resolved && !$st->is_terminal;
    }

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

        if (!$this->awaitingAcceptance($ticket)) {
            return $this->page('Chamado já atualizado', '<p>Este chamado não está mais aguardando seu aceite '
                . '(status atual: <b>' . e(optional($ticket->status)->label ?: '—') . '</b>). Nenhuma ação é necessária.</p>');
        }

        $num = e($ticket->ticket_number ?: ('#' . $ticket->id));
        if ($acao === 'reject') {
            $post = URL::temporarySignedRoute('hd.reject.do', now()->addDays(30), ['ticket' => $ticket->id]);
            $body = '<p>Conte pra gente o que faltou na solução do chamado <b>' . $num . '</b>. '
                . 'Ao enviar, o chamado volta para <b>Em atendimento</b> e nossa equipe retoma o tratamento.</p>'
                . '<form method="post" action="' . e($post) . '" enctype="multipart/form-data" style="margin-top:16px">'
                . '<textarea id="reason" name="reason" required maxlength="2000" rows="5" placeholder="Descreva o que não resolveu…" '
                . 'style="width:100%;box-sizing:border-box;padding:12px;border:1px solid #d1d5db;border-radius:10px;font-size:15px;font-family:inherit"></textarea>'
                . '<div style="margin-top:12px;text-align:left">'
                . '<label style="font-size:13px;color:#6b7280;display:block;margin-bottom:6px">Anexar print ou arquivo (opcional) — dica: pode <b>colar (Ctrl+V)</b> uma imagem aqui.</label>'
                . '<input id="anexos" type="file" name="anexos[]" multiple accept="image/*,.pdf,.docx,.xlsx,.txt,.csv" style="font-size:14px">'
                . '<div id="anexInfo" style="font-size:12px;color:#16a34a;margin-top:6px"></div>'
                . '</div>'
                . '<button type="submit" style="margin-top:14px;background:#ef4444;color:#fff;border:0;border-radius:10px;padding:14px 28px;font-size:15px;font-weight:700;cursor:pointer">Enviar recusa</button>'
                . '</form>'
                . '<script>(function(){var inp=document.getElementById("anexos"),info=document.getElementById("anexInfo");if(!inp)return;function upd(){info.textContent=inp.files.length?inp.files.length+" arquivo(s) anexado(s).":"";}document.addEventListener("paste",function(e){var items=(e.clipboardData||{}).items||[],dt=new DataTransfer(),i;for(i=0;i<inp.files.length;i++)dt.items.add(inp.files[i]);var added=0;for(i=0;i<items.length;i++){if(items[i].type&&items[i].type.indexOf("image")===0){var fl=items[i].getAsFile();if(fl){dt.items.add(new File([fl],"print-"+Date.now()+".png",{type:fl.type||"image/png"}));added++;}}}if(added){try{inp.files=dt.files;}catch(_){}upd();}});inp.addEventListener("change",upd);})();</script>';
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
        if (!$this->awaitingAcceptance($ticket)) {
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
                \App\Services\HelpDeskTriggerEngine::queue('status_changed', $ticket->fresh(), ['via' => 'email_aceite']);
            } finally {
                $companyCtx->forget();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('HelpDesk: e-mail de encerramento (aceite) falhou: ' . $e->getMessage());
        }

        return $this->page(
            'Chamado encerrado!',
            '<p>Prontinho! O chamado <b>' . e($ticket->ticket_number ?: ('#' . $ticket->id))
                . '</b> foi <b>encerrado</b>. Muito obrigado pelo seu retorno — ficamos felizes em ter ajudado! 🎉</p>'
                . '<p style="color:#6b7280;font-size:13px;margin-top:14px">Você já pode fechar esta página.</p>',
            '🚀',
            '#16a34a'
        );
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
        if (!$this->awaitingAcceptance($ticket)) {
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
            'author_user_id'    => null, // cliente pelo e-mail (sem login) — interação do cliente
            'author_contact_id' => $ticket->customer_contact_id, // QUEM recusou (contato do cliente), quando houver
            'body'              => 'Solução recusada pelo cliente: ' . mb_substr($reason, 0, 2000),
            'visibility'        => 'customer',
            'channel'           => 'email',
            'form_kind'         => 'rejection', // marcador estruturado → card vermelho "Solução recusada" no timeline
        ]);
        HelpDeskTicketEvent::log($ticket->id, 'reopened', ['to_value' => $em->label, 'meta' => ['via' => 'email', 'motivo' => $reason]]);
        HelpDeskTicketEvent::log($ticket->id, 'status_changed', ['field' => 'status', 'from_value' => $old?->key, 'to_value' => $em->key, 'meta' => ['via' => 'email']]);
        HelpDeskTicketEvent::log($ticket->id, 'comment', ['meta' => ['comment_id' => $comment->id, 'via' => 'email']]);

        // Anexos enviados pelo cliente (print/arquivo) → grava na interação (visibility=customer).
        // Sem login no link: usa o assignee (ou um admin) como ator do upload (internalStaff passa).
        $files = $request->file('anexos', []);
        if (!empty($files)) {
            $actor = $ticket->assignee ?: \App\Models\User::where('type', 'admin')->where('enabled', true)->orderBy('id')->first();
            if ($actor) {
                $svc = app(\App\Attachments\AttachmentService::class);
                foreach ((array) $files as $file) {
                    if (!$file || !$file->isValid()) continue;
                    try {
                        $svc->store($actor, [
                            'entity_type' => 'HELPDESK_TICKET_COMMENT',
                            'entity_id'   => $comment->id,
                            'category'    => str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : 'attachment',
                            'visibility'  => 'customer',
                            'file'        => $file,
                        ], $request);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('HelpDesk: anexo da recusa falhou: ' . $e->getMessage());
                    }
                }
            }
        }

        // Avisos DETERMINÍSTICOS de recusa (equipe + cliente) — NÃO dependem de gatilho configurado,
        // que era o motivo de "não recebi o e-mail da recusa". Vai pela fila 'emails' (throttle/retry).
        try {
            \App\Jobs\SendHelpDeskRejectionEmailsJob::dispatch($ticket->id, $reason)->onQueue('emails');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('HelpDesk: dispatch dos avisos de recusa falhou: ' . $e->getMessage());
        }
        // Além dos avisos garantidos, ainda dispara os gatilhos configurados (se houver) para
        // qualquer automação extra que o admin tenha montado na reabertura.
        try {
            \App\Services\HelpDeskTriggerEngine::queue('status_changed', $ticket->fresh(), ['via' => 'email_recusa', 'reopened' => true]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('HelpDesk: gatilho de recusa falhou: ' . $e->getMessage());
        }

        return $this->page(
            'Recebemos sua recusa',
            '<p>Obrigado pelo retorno. O chamado <b>' . e($ticket->ticket_number ?: ('#' . $ticket->id))
                . '</b> voltou para <b>Em atendimento</b> e nossa equipe já foi avisada para dar continuidade.</p>'
                . '<p style="color:#6b7280;font-size:13px;margin-top:14px">Você já pode fechar esta página.</p>',
            '🔧'
        );
    }

    /**
     * Página HTML branded (autossuficiente) — logo + tudo CENTRALIZADO.
     * $icon: emoji grande de destaque (ex.: 🚀) para telas de sucesso. $accent: cor do topo.
     */
    private function page(string $title, string $bodyHtml, string $icon = '', string $accent = self::BRAND)
    {
        $logo = \App\Services\HelpDeskMailFooter::whiteLogoDataUri();
        $logoImg = $logo !== ''
            ? '<img src="' . $logo . '" alt="ERPSERV" style="height:34px;width:auto;display:inline-block;border:0" />'
            : '<div style="color:#fff;font-weight:800;font-size:20px;letter-spacing:.02em">ERPSERV</div>';
        $iconHtml = $icon !== '' ? '<div style="font-size:60px;line-height:1;margin:2px 0 12px">' . $icon . '</div>' : '';
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title></head>'
            . '<body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
            . '<div style="max-width:520px;margin:8vh auto;padding:0 16px">'
            . '<div style="background:#fff;border:1px solid #e6e8ec;border-radius:16px;overflow:hidden;box-shadow:0 12px 34px rgba(17,24,39,.10)">'
            . '<div style="background:' . $accent . ';padding:22px 24px;text-align:center">' . $logoImg . '</div>'
            . '<div style="padding:32px 28px;text-align:center">'
            . $iconHtml
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#8a94a6">Central de Atendimento · ERPSERV</div>'
            . '<h1 style="margin:8px 0 16px;font-size:22px;color:#111827">' . e($title) . '</h1>'
            . '<div style="font-size:15px;line-height:1.65;color:#374151">' . $bodyHtml . '</div>'
            . '</div></div>'
            . '<div style="text-align:center;font-size:12px;color:#9ca3af;margin-top:16px">Mensagem da Central de Atendimento ERPSERV · Minutor</div>'
            . '</div></body></html>';
        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
