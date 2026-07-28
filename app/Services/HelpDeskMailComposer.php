<?php

namespace App\Services;

use App\Models\HelpDeskCommTemplate;
use App\Models\HelpDeskTicket;

/**
 * Monta o e-mail INSTITUCIONAL do Help Desk a partir de (mensagem + blocos), usando o
 * Template Institucional global. O admin informa só assunto+mensagem+blocos; o layout
 * (logo, cabeçalho, assinatura, rodapé, cores) é montado aqui. NÃO altera o motor de gatilhos.
 *
 * Compat: send_email com `body` legado continua sendo renderizado direto (sem este composer).
 */
class HelpDeskMailComposer
{
    /** Blocos disponíveis p/ inserir (chave => rótulo). */
    public const BLOCKS = [
        'ticket_data'  => 'Dados do chamado',
        'summary'      => 'Resumo do chamado',
        'last_public'  => 'Última interação pública',
        'customer'     => 'Dados do cliente',
        'assignee'     => 'Dados do responsável',
        'sla'          => 'SLA',
        'button'       => 'Botão "Abrir chamado"',
        'assignee_signature' => 'Assinatura do consultor',
    ];

    /**
     * Logo via cid (e-mail) ou data: (prévia). $audience define a quem é a saudação:
     * 'responsavel' → cumprimenta o agente; senão → o solicitante/cliente (NÃO o agente).
     */
    public static function compose(string $message, array $blocks, HelpDeskTicket $ticket, ?string $logoSrc = null, string $audience = 'cliente', ?string $notifTitle = null, ?string $notifSubtitle = null): string
    {
        $tpl   = HelpDeskCommTemplate::current();
        $logo  = $logoSrc ?? ('cid:' . HelpDeskMailFooter::LOGO_CID);
        $color = $tpl->primary_color ?: '#7c3aed';
        $font  = $tpl->font ?: 'Arial, Helvetica, sans-serif';
        // Saudação: cliente → nome do solicitante; responsável → nome do agente; interno → genérica.
        $greetName = $audience === 'responsavel'
            ? trim((string) optional($ticket->assignee)->name)
            : ($audience === 'cliente' ? trim((string) ($ticket->solicitanteName() ?? '')) : '');
        $greeting = $greetName !== '' ? 'Olá ' . e($greetName) . ',' : 'Olá,';
        $msgHtml  = nl2br(e(HelpDeskTriggerEngine::render($message, $ticket)));
        $hasMsg   = trim(strip_tags((string) $msgHtml)) !== '';

        // Blocos opcionais do admin. O 'button' vira o CTA fixo abaixo — não duplicamos aqui.
        $blocksHtml = '';
        foreach ($blocks as $b) {
            if ((string) $b === 'button') continue;
            $blocksHtml .= self::renderBlock((string) $b, $ticket, $color);
        }

        // Dados do chamado (chrome do card — placeholders/mensagem NÃO mudam).
        $company    = e($tpl->company_name);
        $num        = e($ticket->ticket_number ?: ('#' . $ticket->id));
        $sm         = self::statusMeta($ticket);
        $barColor   = $sm['breached'] ? '#ef4444' : $sm['color'];
        $clienteVal = optional($ticket->customer)->name;
        $agenteVal  = optional($ticket->assignee)->name;
        // Servidor roda em UTC; exibe no fuso de São Paulo (mesma convenção do HelpDeskTicketController).
        $atualizado = $ticket->updated_at ? $ticket->updated_at->copy()->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : null;
        $aberto     = $ticket->created_at ? $ticket->created_at->copy()->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : null;
        $ctaUrl     = HelpDeskTriggerEngine::render('{ticket.url}', $ticket);
        $ctaLabel   = e($tpl->button_label ?: 'Acompanhar chamado');

        // Chamado RESOLVIDO (aguardando aceite): botões Aceitar (verde) / Recusar (vermelho) via link
        // assinado — o cliente decide direto do e-mail, SEM login. Encerra ou reabre "Em atendimento".
        $acceptReject = '';
        if (optional($ticket->status)->is_resolved && !optional($ticket->status)->is_terminal) {
            $aUrl = \App\Http\Controllers\HelpDeskAcceptController::actionUrl($ticket->id, 'accept');
            $rUrl = \App\Http\Controllers\HelpDeskAcceptController::actionUrl($ticket->id, 'reject');
            $acceptReject = '<tr><td align="center" style="padding:22px 28px 0">'
                . '<div style="font-size:13px;color:#4b5563;margin-bottom:12px">A solução resolveu? Você pode responder direto por aqui:</div>'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td style="padding:0 6px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="#16a34a" style="border-radius:8px"><a href="' . e($aUrl) . '" style="display:inline-block;padding:13px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px">&#10003; Aceitar e encerrar</a></td></tr></table></td>'
                . '<td style="padding:0 6px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="#ef4444" style="border-radius:8px"><a href="' . e($rUrl) . '" style="display:inline-block;padding:13px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px">&#10007; Recusar solução</a></td></tr></table></td>'
                . '</tr></table>'
                . '</td></tr>';
        }
        $minutor    = $tpl->show_minutor
            ? '<div style="font-size:11px;line-height:1.5;color:#9ca3af;margin-top:4px">Mensagem automática enviada pelo Minutor.</div>' : '';

        // Layout SaaS premium (TOTVS/Zendesk/Jira-like), identidade ERPSERV: tabelas + estilos inline
        // p/ máxima compatibilidade (Outlook/Gmail/Apple Mail/Thunderbird/mobile).
        return ''
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f5f7"><tr><td align="center" style="padding:24px 12px">'
        .   '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border:1px solid #e6e8ec;border-radius:12px;font-family:' . $font . ';color:#1f2937">'
        // ── CABEÇALHO compacto: logo + título/subtítulo + linha inferior discreta ──
        .     '<tr><td style="padding:18px 28px;border-bottom:1px solid #eef0f3">'
        .       '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        .         '<td valign="middle" style="padding-right:14px"><img src="' . $logo . '" alt="' . $company . '" height="34" style="height:34px;width:auto;display:block;border:0" /></td>'
        .         '<td valign="middle" style="padding-left:14px;border-left:1px solid #e6e8ec">'
        .           '<div style="font-size:15px;font-weight:700;color:#111827;line-height:1.2">Central de Atendimento</div>'
        .           '<div style="font-size:12px;color:#6b7280;line-height:1.3;margin-top:2px">Minutor Help Desk</div>'
        .         '</td>'
        .       '</tr></table>'
        .     '</td></tr>'
        // ── SAUDAÇÃO + BLOCO DE NOTIFICAÇÃO (ícone + título + descrição) — o "porquê" em 2 segundos ──
        .     '<tr><td style="padding:24px 28px 4px">'
        .       ($greeting !== 'Olá,' ? '<p style="margin:0 0 14px;font-size:15px;font-weight:600;color:#4b5563">' . $greeting . '</p>' : '')
        .       (($notifTitle !== null && trim($notifTitle) !== '')
                    ? '<div style="font-size:22px;font-weight:800;line-height:1.25;color:#111827">' . e($notifTitle) . '</div>'
                        . (($notifSubtitle !== null && trim($notifSubtitle) !== '') ? '<div style="margin-top:6px;font-size:14px;line-height:1.55;color:#6b7280">' . e($notifSubtitle) . '</div>' : '')
                        . '<div style="border-top:1px solid #eef0f3;margin-top:18px;line-height:0;font-size:0">&nbsp;</div>'
                    : '')
        .     '</td></tr>'
        // ── CARD DO CHAMADO: card único, barra de status à esquerda, 2 colunas ──
        .     '<tr><td style="padding:18px 28px 4px">'
        .       '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        .         '<td width="4" bgcolor="' . $barColor . '" style="width:4px;background:' . $barColor . ';border-radius:10px 0 0 10px;font-size:0;line-height:0">&nbsp;</td>'
        .         '<td style="padding:18px 20px;background:#f8f9fb;border:1px solid #e6e8ec;border-left:0;border-radius:0 10px 10px 0">'
        .           '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        .             '<td valign="top">'
        .               '<div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#8a94a6;font-weight:700;margin-bottom:2px">Chamado</div>'
        .               '<div style="font-size:24px;line-height:1.1;font-weight:800;color:#111827">' . $num . '</div>'
        .             '</td>'
        .             '<td valign="top" align="right">' . self::statusBadge($sm) . '</td>'
        .           '</tr></table>'
        .           '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px">'
        .             '<tr><td width="50%" valign="top" style="padding:7px 12px 7px 0">' . self::cardCell('Cliente', $clienteVal) . '</td>'
        .                 '<td width="50%" valign="top" style="padding:7px 0">' . self::cardCell('Consultor', $agenteVal) . '</td></tr>'
        .             '<tr><td width="50%" valign="top" style="padding:7px 12px 7px 0">' . self::cardCell('Última atualização', $atualizado) . '</td>'
        .                 '<td width="50%" valign="top" style="padding:7px 0">' . self::cardCell('Data de abertura', $aberto) . '</td></tr>'
        .           '</table>'
        .         '</td>'
        .       '</tr></table>'
        .     '</td></tr>'
        // ── MENSAGEM DO GATILHO (só quando há texto) + blocos opcionais ──
        .     (($hasMsg || $blocksHtml !== '')
                ? '<tr><td style="padding:16px 28px 4px">'
                    . ($hasMsg
                        ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                            . '<td width="4" bgcolor="' . $color . '" style="width:4px;background:' . $color . ';border-radius:10px 0 0 10px;font-size:0;line-height:0">&nbsp;</td>'
                            . '<td style="padding:16px 18px;background:#f8f9fb;border:1px solid #e6e8ec;border-left:0;border-radius:0 10px 10px 0;font-size:14px;line-height:1.65;color:#1f2937">' . $msgHtml . '</td>'
                            . '</tr></table>'
                        : '')
                    . $blocksHtml
                    . '</td></tr>'
                : '')
        // ── ACEITE / RECUSA (só quando resolvido) — decide direto do e-mail, sem login ──
        .     $acceptReject
        // ── BOTÃO CTA grande e centralizado ──
        .     '<tr><td align="center" style="padding:24px 28px 6px">'
        .       '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        .         '<td align="center" bgcolor="' . $color . '" style="border-radius:8px">'
        .           '<a href="' . e($ctaUrl) . '" style="display:inline-block;padding:14px 34px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px">' . $ctaLabel . '</a>'
        .         '</td>'
        .       '</tr></table>'
        .       '<div style="font-size:12px;line-height:1.5;color:#9ca3af;margin-top:10px">Ou responda diretamente a este e-mail.</div>'
        .     '</td></tr>'
        // ── ASSINATURA enxuta ──
        .     '<tr><td style="padding:18px 28px 22px">'
        .       '<div style="padding-top:16px;border-top:1px solid #eef0f3;font-size:14px;line-height:1.6;color:#374151">'
        .         '<b style="color:#111827">' . e($tpl->signature) . '</b><br>' . $company . ' Consultoria<br>'
        .         '<a href="https://www.erpserv.com.br" style="color:' . $color . ';text-decoration:none">www.erpserv.com.br</a>'
        .       '</div>'
        .     '</td></tr>'
        // ── RODAPÉ pequeno (sem logo) ──
        .     '<tr><td style="padding:16px 28px;background:#fafbfc;border-top:1px solid #eef0f3;border-radius:0 0 12px 12px">'
        .       '<div style="font-size:12px;line-height:1.5;color:#6b7280;font-weight:600">' . $company . ' Consultoria</div>'
        .       '<div style="font-size:12px;line-height:1.5;color:#9ca3af">Central de Atendimento</div>'
        .       $minutor
        .     '</td></tr>'
        .   '</table>'
        . '</td></tr></table>';
    }

    /** Célula rótulo+valor do card do chamado (rótulo pequeno em maiúsculas + valor). */
    private static function cardCell(string $label, ?string $value): string
    {
        $v = ($value !== null && trim($value) !== '') ? e($value) : '—';
        return '<div style="font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#8a94a6;font-weight:700;margin-bottom:2px">' . e($label) . '</div>'
            . '<div style="font-size:14px;color:#1f2937;font-weight:600;line-height:1.4">' . $v . '</div>';
    }

    /** Cor + emoji + rótulo do status (barra do card e badge). SLA estourado sinaliza vermelho. */
    private static function statusMeta(HelpDeskTicket $ticket): array
    {
        $st    = $ticket->status;
        $key   = (string) optional($st)->key;
        $label = trim((string) optional($st)->label) ?: '—';
        $map = [
            'novo'               => ['#3b82f6', '🔵'],
            'em_andamento'       => ['#3b82f6', '🔵'],
            'em_desenvolvimento' => ['#3b82f6', '🔵'],
            'planejamento_gmud'  => ['#8b5cf6', '🟣'],
            'solucao_gmud'       => ['#8b5cf6', '🟣'],
            'aguardando_cliente' => ['#f59e0b', '🟠'],
            'pendente_terceiros' => ['#f59e0b', '🟠'],
            'resolvido'          => ['#16a34a', '🟢'],
            'fechado'            => ['#6b7280', '⚫'],
            'cancelado'          => ['#6b7280', '⚫'],
        ];
        [$c, $emoji] = $map[$key] ?? [(string) (optional($st)->color ?: '#6b7280'), '🔵'];
        $breached = (!optional($st)->is_resolved && !optional($st)->is_terminal) ? (bool) $ticket->resolution_breached : false;
        return ['color' => $c, 'emoji' => $emoji, 'label' => $label, 'breached' => $breached];
    }

    /** Badge de status (pílula com borda na cor + emoji) + badge extra "SLA estourado" quando aplicável. */
    private static function statusBadge(array $sm): string
    {
        $c = $sm['color'];
        $badge = '<span style="display:inline-block;background:#ffffff;border:1px solid ' . $c . ';color:' . $c . ';border-radius:999px;padding:5px 12px;font-size:12px;font-weight:700;line-height:1;white-space:nowrap">' . $sm['emoji'] . ' ' . e($sm['label']) . '</span>';
        if (!empty($sm['breached'])) {
            $badge .= '<br><span style="display:inline-block;margin-top:6px;background:#fef2f2;border:1px solid #ef4444;color:#ef4444;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:700;line-height:1;white-space:nowrap">🔴 SLA estourado</span>';
        }
        return $badge;
    }

    /**
     * Layout institucional GENÉRICO (sem chamado): título + mensagem. Usado p/ notificações.
     * Cabeçalho = FAIXA da cor da marca + LOGO BRANCO (sobrevive ao dark mode: cor de marca não
     * é invertida pelos clientes e o logo é imagem). Corpo claro com tema ESCURO intencional via
     * media query (clientes que honram). Default do logo = cid do logo branco.
     */
    /** Marcador de corte: o cliente escreve ACIMA desta linha; o ingestor descarta o que vier abaixo. */
    public const REPLY_DELIMITER = '##– Não escreva abaixo desta linha –##';

    /**
     * Cabeçalho de status do e-mail de ATUALIZAÇÃO (estilo portal TOTVS/Movidesk): marcador de
     * "não escreva abaixo desta linha" + nº, data de abertura e último status + "sua solicitação
     * foi atualizada". Vai no TOPO do e-mail — assim, quando o cliente responder, o texto novo
     * dele fica acima do marcador e o histórico citado é cortado na ingestão.
     */
    public static function updateHeaderHtml(HelpDeskTicket $ticket): string
    {
        $tz       = 'America/Sao_Paulo';
        $num      = e((string) ($ticket->ticket_number ?: ('#' . $ticket->id)));
        $abertura = $ticket->created_at ? $ticket->created_at->copy()->timezone($tz)->format('d/m/Y \à\s H:i') . ' BRT' : '—';
        $status   = e((string) (optional($ticket->status)->label ?: '—'));
        $resp     = e((string) (optional($ticket->assignee)->name ?: 'Não atribuído'));
        $ref      = trim((string) $ticket->external_ticket_ref);
        $stKey    = (string) optional($ticket->status)->key;
        // Prazo de entrega = data informada no chamado (agendamento). All-day → só a data.
        $schedTxt = $ticket->scheduled_until
            ? \Illuminate\Support\Carbon::parse($ticket->scheduled_until)->timezone('America/Sao_Paulo')->format($ticket->scheduled_all_day ? 'd/m/Y' : 'd/m/Y \à\s H:i') . ($ticket->scheduled_all_day ? '' : ' BRT')
            : '';
        // Aviso automático ao cliente conforme o status — mesma caixa destacada.
        $avStyle = 'font-size:14px;color:#374151;background:#f5f3ff;border-left:3px solid #7c3aed;padding:10px 12px;border-radius:6px;margin:0 0 16px';
        $fornec  = $ref !== '' ? trim((string) preg_replace('/\s*#.*$/', '', $ref)) : '';
        $aviso = '';
        if ($stKey === 'aguardando_cliente') {
            $aviso = '<div style="' . $avStyle . '">⏳ <strong>Aguardamos o seu retorno</strong> para darmos continuidade ao atendimento do seu chamado.</div>';
        } elseif ($stKey === 'em_desenvolvimento') {
            $aviso = '<div style="' . $avStyle . '">🛠️ Sua solicitação está <strong>em desenvolvimento</strong>.'
                . ($schedTxt !== '' ? ' A previsão de entrega é <strong>' . e($schedTxt) . '</strong>.' : '')
                . '</div>';
        }
        // Obs.: "Pendente terceiros" NÃO tem aviso de cabeçalho — a mensagem (caixa) já é a própria
        // interação enviada pelo fluxo de fornecedor (evita a mensagem em dobro).
        $nome     = e((string) ($ticket->solicitanteName() ?: $ticket->requester_name ?: 'cliente'));

        return '<div style="font-family:Arial,Helvetica,sans-serif;color:#111827">'
            . '<div style="color:#9ca3af;font-size:11px;margin:0 0 12px">' . e(self::REPLY_DELIMITER) . '</div>'
            . '<div style="font-size:13px;color:#374151;line-height:1.6;margin:0 0 10px">'
            .   '<strong>Solicitação nº:</strong> ' . $num . '<br>'
            .   '<strong>Data de abertura:</strong> ' . e($abertura) . '<br>'
            .   '<strong>Último status:</strong> ' . $status . '<br>'
            .   '<strong>Responsável:</strong> ' . $resp
            .   (($ref !== '' && $stKey === 'pendente_terceiros') ? '<br><strong>Fornecedor:</strong> ' . e($ref) : '')
            . '</div>'
            . '<div style="border-top:1px solid #e5e7eb;margin:12px 0 14px"></div>'
            . '<div style="font-size:14px;font-weight:bold;color:#111827;margin:0 0 18px;padding:0 0 18px;border-bottom:1px solid #e5e7eb">Olá ' . $nome . ',<br>Sua solicitação nº ' . $num . ' foi atualizada.</div>'
            . $aviso
            . '</div>';
    }

    /**
     * Remove imagens do HTML (logos/assinaturas/prints). Usado no bloco de histórico: cada resposta
     * embute o fio inteiro e o treatBody transforma cada imagem em anexo inline (cid) — com muitas
     * interações isso estoura o limite de PARTES do Exchange (máx. 250 body parts → NDR 554 5.3.4).
     */
    private static function noImg(string $html): string
    {
        return (string) preg_replace('#<img\b[^>]*>#i', '<span style="color:#9ca3af">[imagem]</span>', $html);
    }

    /** Botões Aceitar/Recusar quando o chamado está RESOLVIDO (aguardando aceite) — o cliente decide do e-mail, sem login. */
    public static function acceptButtonsHtml(HelpDeskTicket $ticket): string
    {
        if (!(optional($ticket->status)->is_resolved && !optional($ticket->status)->is_terminal)) return '';
        $aUrl = \App\Http\Controllers\HelpDeskAcceptController::actionUrl($ticket->id, 'accept');
        $rUrl = \App\Http\Controllers\HelpDeskAcceptController::actionUrl($ticket->id, 'reject');
        return '<div style="margin:22px 0 6px;border-top:1px solid #e5e7eb;padding-top:16px">'
            . '<div style="font-size:13px;color:#4b5563;margin:0 0 12px">A solução resolveu o seu chamado? Você pode responder direto por aqui:</div>'
            . '<a href="' . e($aUrl) . '" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:700;color:#ffffff;background:#16a34a;text-decoration:none;border-radius:8px;margin:0 8px 8px 0">&#10003; Aceitar e encerrar</a>'
            . '<a href="' . e($rUrl) . '" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:700;color:#ffffff;background:#ef4444;text-decoration:none;border-radius:8px;margin:0 8px 8px 0">&#10007; Recusar solução</a>'
            . '</div>';
    }

    /**
     * Histórico da conversa visível ao cliente (abertura + interações públicas), do mais recente
     * ao mais antigo, para embutir no corpo do e-mail de atualização (estilo Movidesk: "um e-mail
     * único mantendo o histórico"). Datas no fuso de São Paulo. Exclui o comentário atual, se dado.
     */
    public static function conversationHistoryHtml(HelpDeskTicket $ticket, ?int $excludeCommentId = null): string
    {
        $tz = 'America/Sao_Paulo';
        $items = [];

        // Abertura (mensagem original do cliente).
        if (trim(strip_tags((string) $ticket->description)) !== '') {
            $items[] = [
                'when' => $ticket->created_at,
                'who'  => (string) ($ticket->solicitanteName() ?: $ticket->requester_name ?: 'Cliente'),
                'html' => self::noImg(self::richHtml((string) $ticket->description)),
            ];
        }

        // Interações visíveis ao cliente (agente e cliente).
        // Só as últimas 15 interações — bound de tamanho/partes do e-mail (evita o limite do Exchange).
        $comments = $ticket->comments()->where('visibility', 'customer')->orderByDesc('id')->limit(15)->get()->sortBy('id')->values();
        foreach ($comments as $c) {
            if ($excludeCommentId && (int) $c->id === (int) $excludeCommentId) continue;
            $who = $c->author_user_id
                ? (optional(\App\Models\User::find($c->author_user_id))->name ?: 'Suporte ERPSERV')
                : (string) ($ticket->solicitanteName() ?: $ticket->requester_name ?: 'Cliente');
            $items[] = ['when' => $c->created_at, 'who' => $who, 'html' => self::noImg(self::richHtml((string) $c->body))];
        }

        if (empty($items)) return '';
        $items = array_reverse($items); // mais recente primeiro

        $rows = '';
        foreach ($items as $it) {
            $ts = $it['when'] ? $it['when']->copy()->timezone($tz)->format('d/m/Y H:i') : '';
            $rows .= '<div style="border-left:3px solid #e5e7eb;padding:6px 0 6px 12px;margin:0 0 12px">'
                . '<div style="font-size:12px;color:#6b7280;margin:0 0 4px"><strong style="color:#374151">' . e($it['who']) . '</strong> · ' . e($ts) . '</div>'
                . '<div style="font-size:14px;color:#111827;line-height:1.5">' . $it['html'] . '</div>'
                . '</div>';
        }

        return '<div style="margin:22px 0 0;border-top:1px solid #e5e7eb;padding-top:14px">'
            . '<div style="font-size:12px;font-weight:bold;color:#374151;text-transform:uppercase;letter-spacing:.05em;margin:0 0 12px">Histórico da conversa</div>'
            . $rows . '</div>';
    }

    public static function composeSimple(string $title, string $messageHtml, ?string $logoSrc = null, ?string $headerSubtitle = null): string
    {
        $tpl   = HelpDeskCommTemplate::current();
        $logo  = $logoSrc ?? ('cid:' . HelpDeskMailFooter::LOGO_WHITE_CID);
        $color = $tpl->primary_color ?: '#7c3aed';
        $font  = $tpl->font ?: 'Arial, Helvetica, sans-serif';
        $minutor = $tpl->show_minutor
            ? '<div class="hde-foot" style="font-size:11px;color:#9ca3af;margin-top:4px">Mensagem automática · enviada via <span style="color:#6b7280;font-weight:600">Minutor</span></div>' : '';
        // Subtítulo opcional na FAIXA ROXA (ex.: nº do chamado + título) — texto branco abaixo do logo.
        $subHtml = ($headerSubtitle !== null && trim($headerSubtitle) !== '')
            ? '<div style="margin-top:10px;color:#ffffff;font-size:14px;font-weight:700;line-height:1.3">' . e($headerSubtitle) . '</div>'
            : '';

        $inner = '<div class="hde-outer" style="margin:0;padding:0;background:#f3f4f6">'
            . '<div class="hde-card" style="max-width:600px;margin:0 auto;background:#ffffff;font-family:' . $font . ';color:#1f2937;border-radius:10px;overflow:hidden;border:1px solid #e9ebef">'
            // Cabeçalho colorido (marca) + logo branco — legível em claro e escuro.
            .   '<div class="hde-head" style="background:' . $color . ';padding:18px 24px" bgcolor="' . $color . '"><img src="' . $logo . '" alt="' . e($tpl->company_name) . '" style="height:30px;width:auto;display:block;border:0" />' . $subHtml . '</div>'
            .   '<div style="padding:22px 24px">'
            .     '<h2 class="hde-title" style="margin:0 0 12px;font-size:18px;color:#111827">' . e($title) . '</h2>'
            .     '<div class="hde-text" style="font-size:14px;line-height:1.6;color:#1f2937">' . $messageHtml . '</div>'
            .   '</div>'
            .   '<div class="hde-footwrap" style="padding:14px 24px;background:#fafafa;border-top:1px solid #eef0f3"><div class="hde-foot" style="font-size:12px;color:#6b7280">' . e($tpl->footer_text) . '</div>' . $minutor . '</div>'
            . '</div></div>';

        return self::wrapDocument($inner, $color);
    }

    /** Anexo inline do logo BRANCO (cabeçalho do e-mail de notificação). */
    public static function inlineAssetsSimple(): array
    {
        $logo = HelpDeskMailFooter::inlineWhiteLogo();
        return $logo ? [$logo] : [];
    }

    /**
     * Documento completo. Mantemos o corpo CLARO (card branco) e o cabeçalho com a FAIXA de marca
     * + logo branco — que funciona nos dois modos. NÃO forçamos um tema escuro via media query: ela
     * segue o OS (prefers-color-scheme), não o tema do cliente de e-mail; com OS escuro + cliente
     * claro o card escuro vazava indevidamente. Em clientes escuros, a auto-inversão deixa o card
     * escuro legível e a faixa roxa + logo branco continuam nítidos.
     */
    private static function wrapDocument(string $bodyHtml, string $brand = '#7c3aed'): string
    {
        return '<!doctype html><html lang="pt-BR"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark">'
            . '<meta name="supported-color-schemes" content="light dark">'
            . '<style>body{margin:0;padding:0;background:#f3f4f6}</style>'
            . '</head><body>' . $bodyHtml . '</body></html>';
    }

    /** Anexo inline do logo (cid) p/ o envio. */
    public static function inlineAssets(): array
    {
        $logo = HelpDeskMailFooter::inlineLogo();
        return $logo ? [$logo] : [];
    }

    // ── Blocos ────────────────────────────────────────────────────────────────
    private static function renderBlock(string $block, HelpDeskTicket $ticket, string $color): string
    {
        return match ($block) {
            'ticket_data' => self::blockTable('Dados do chamado', [
                'Número'      => (string) $ticket->ticket_number,
                'Cliente'     => (string) optional($ticket->customer)->name,
                'Solicitante' => trim(((string) $ticket->solicitanteName()) . ($ticket->solicitanteEmail() ? ' · ' . $ticket->solicitanteEmail() : '')),
                'Status'      => (string) optional($ticket->status)->label,
                'Prioridade'  => ucfirst((string) $ticket->priority),
                'Categoria'   => (string) optional($ticket->category)->name,
                'Serviço'     => (string) optional($ticket->service)->name,
                'Responsável' => (string) optional($ticket->assignee)->name,
            ]),
            'summary' => self::blockBox('Resumo', self::richHtml((string) $ticket->description)),
            'last_public' => self::blockBox('Última interação', self::richHtml((string) optional(
                $ticket->comments()->where('visibility', 'customer')->orderByDesc('id')->first()
            )->body)),
            'customer' => self::blockTable('Cliente', [
                'Empresa' => (string) optional($ticket->customer)->name,
                'Contato' => (string) $ticket->solicitanteName(),
                'E-mail'  => (string) $ticket->solicitanteEmail(),
            ]),
            'assignee' => self::blockTable('Responsável', [
                'Agente' => (string) optional($ticket->assignee)->name,
                'E-mail' => (string) optional($ticket->assignee)->email,
                'Equipe' => (string) optional($ticket->team)->name,
            ]),
            'sla' => self::blockTable('SLA', [
                '1ª resposta' => $ticket->first_response_due_at ? $ticket->first_response_due_at->copy()->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '—',
                'Resolução'   => $ticket->resolution_due_at ? $ticket->resolution_due_at->copy()->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '—',
                'Situação'    => $ticket->resolution_breached ? 'SLA vencido' : 'Dentro do prazo',
            ]),
            'button' => self::blockButton($ticket, $color),
            'assignee_signature' => self::assigneeSignature($ticket),
            default  => '',
        };
    }

    /** Assinatura do consultor responsável (rica) — usada quando a interação é da equipe. */
    private static function assigneeSignature(HelpDeskTicket $ticket): string
    {
        $u = $ticket->assignee;
        if (!$u) return '';
        $data = \App\Services\SignatureRenderer::resolveFor($u);
        if (!\App\Services\SignatureRenderer::hasData($data)) return '';
        $html = \App\Services\SignatureRenderer::render($data, 'data', true, 'light', false);
        return '<div style="margin-top:16px;padding-top:14px;border-top:1px solid #eef0f3">' . $html . '</div>';
    }

    private static function blockTitle(string $t): string
    {
        return '<div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;margin:20px 0 8px">' . e($t) . '</div>';
    }

    /** @param array<string,string> $rows */
    private static function blockTable(string $title, array $rows): string
    {
        $tr = '';
        foreach ($rows as $k => $v) {
            if (trim((string) $v) === '') continue;
            $tr .= '<tr>'
                . '<td style="padding:6px 10px;background:#f9fafb;border:1px solid #eef0f3;font-size:13px;color:#6b7280;white-space:nowrap">' . e($k) . '</td>'
                . '<td style="padding:6px 10px;border:1px solid #eef0f3;font-size:13px;color:#111827">' . e($v) . '</td>'
                . '</tr>';
        }
        if ($tr === '') return '';
        return self::blockTitle($title) . '<table style="border-collapse:collapse;width:100%">' . $tr . '</table>';
    }

    private static function blockBox(string $title, string $html): string
    {
        return self::blockTitle($title)
            . '<div style="padding:10px 12px;background:#f9fafb;border:1px solid #eef0f3;border-radius:8px;font-size:13px;line-height:1.5;color:#374151">' . $html . '</div>';
    }

    private static function blockButton(HelpDeskTicket $ticket, string $color): string
    {
        $tpl = HelpDeskCommTemplate::current();
        $url = HelpDeskTriggerEngine::render('{ticket.url}', $ticket);
        return '<div style="margin:22px 0 4px">'
            . '<a href="' . e($url) . '" style="display:inline-block;background:' . $color . ';color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:10px 22px;border-radius:8px">' . e($tpl->button_label) . '</a>'
            . '</div>';
    }

    private static function plain(string $html): string
    {
        $html = preg_replace('/<\s*(br|\/p|\/div|\/li)\s*\/?>/i', "\n", $html) ?? $html;
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Conteúdo FIEL ao chamado (preserva formatação, imagens e assinatura). Remove só o
     * perigoso (script/iframe). Mantém as imagens inline (data:) — viram cid no envio.
     */
    private static function richHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '—';
        $html = preg_replace('/<\s*(script|iframe|object|embed|style)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? $html;
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? $html; // on* handlers
        return '<div style="max-width:100%;overflow-x:auto">' . $html . '</div>';
    }

    /**
     * Converte imagens inline `data:` do HTML em anexos INLINE (cid) — pro e-mail renderizar
     * a imagem/assinatura do chamado (clientes bloqueiam data:). Retorna [html, anexos].
     *
     * @return array{0:string, 1:array<int,array{name:string,mime:string,bytes:string,cid:string}>}
     */
    public static function inlineImages(string $html): array
    {
        $atts = []; $i = 0;
        $html = preg_replace_callback(
            '/<img\b[^>]*\bsrc=["\']data:(image\/[a-zA-Z0-9.+-]+);base64,([^"\']+)["\'][^>]*>/i',
            function ($m) use (&$atts, &$i) {
                $bytes = base64_decode($m[2], true);
                if ($bytes === false || $bytes === '') return '';
                $i++;
                $ext = match (strtolower($m[1])) { 'image/png' => 'png', 'image/jpeg', 'image/jpg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', default => 'img' };
                $cid = "ticketimg{$i}@minutor";
                $atts[] = ['name' => "img{$i}.{$ext}", 'mime' => strtolower($m[1]), 'bytes' => $bytes, 'cid' => $cid];
                // preserva o resto da tag <img> trocando só o src
                $tag = preg_replace('/\bsrc=["\']data:[^"\']*["\']/i', 'src="cid:' . $cid . '"', $m[0]) ?? ('<img src="cid:' . $cid . '" style="max-width:100%">');
                return $tag;
            },
            $html
        ) ?? $html;
        // background-image:url('data:image/...') → cid. É o caso da FOTO redonda da assinatura,
        // que virou background de <span> (p/ o Apple Mail não pôr borda no dark mode). O Exchange
        // descarta data: em CSS, então convertemos aqui como já fazemos com <img>.
        $html = preg_replace_callback(
            '/background-image:\s*url\(\s*([\'"]?)data:(image\/[a-zA-Z0-9.+-]+);base64,([^\'")]+)\1\s*\)/i',
            function ($m) use (&$atts, &$i) {
                $bytes = base64_decode($m[3], true);
                if ($bytes === false || $bytes === '') return $m[0];
                $i++;
                $ext = match (strtolower($m[2])) { 'image/png' => 'png', 'image/jpeg', 'image/jpg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', default => 'img' };
                $cid = "ticketbg{$i}@minutor";
                $atts[] = ['name' => "bg{$i}.{$ext}", 'mime' => strtolower($m[2]), 'bytes' => $bytes, 'cid' => $cid];
                return "background-image:url('cid:{$cid}')";
            },
            $html
        ) ?? $html;
        return [$html, $atts];
    }
}
