<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail da Proposta Comercial (CRM).
 *
 * De  = caixa do usuário logado (Send As via Microsoft Graph) quando habilitado;
 *       senão, conta padrão (mail.from) COM o nome do usuário logado.
 * Reply-To = quem enviou (+ financeiro, se houver) — fallback p/ a conta From.
 * To/CC  = definidos pelo controller (Mail::to()->cc()) ou pelo GraphMailer.
 *
 * Corpo enxuto (mesmo padrão do fechamento); a proposta completa vai no anexo PDF.
 */
class CrmProposalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $clienteName,
        public string $senderName,
        public string $subjectLine,
        public ?string $portalUrl = null,   // Portal de Propostas — o e-mail leva o LINK, não o anexo.
        public ?string $codigo = null,
        public ?string $tipoLabel = null,
        public ?string $valorTotal = null,
        public ?string $validade = null,
        public ?string $senderEmail = null,
        public ?string $financeiroCc = null,
        public ?string $mensagem = null,
        // Anexo do PDF é OPCIONAL (padrão: não anexa — o cliente acessa pelo Portal).
        public ?string $pdfPath = null,
        public ?string $pdfFileName = null,
        public bool $withAttachments = false,
        // From sobrescrito quando o remetente envia COMO ele mesmo.
        public ?string $fromAddress = null,
        public ?string $fromName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $from = config('mail.from.address');
        $replyTo = [];
        if ($this->senderEmail) {
            $replyTo[] = new Address($this->senderEmail, $this->senderName);
        }
        if ($this->financeiroCc) {
            $replyTo[] = new Address($this->financeiroCc, 'Comercial ERPSERV');
        }
        if (empty($replyTo)) {
            $replyTo[] = new Address($from, $this->senderName);
        }

        return new Envelope(
            from: $this->fromAddress
                ? new Address($this->fromAddress, $this->fromName ?? $this->senderName)
                : new Address($from, config('mail.from.name', 'ERPSERV Consultoria')),
            replyTo: $replyTo,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.crm.proposta',
            with: [
                'clienteName'     => $this->clienteName,
                'senderName'      => $this->senderName,
                'codigo'          => $this->codigo,
                'tipoLabel'       => $this->tipoLabel,
                'valorTotal'      => $this->valorTotal,
                'validade'        => $this->validade,
                'mensagem'        => $this->mensagem ?? '',
                'portalUrl'       => $this->portalUrl,
                'withAttachments' => $this->withAttachments,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if (!$this->withAttachments || !$this->pdfPath || !is_file($this->pdfPath)) {
            return [];
        }
        return [
            Attachment::fromPath($this->pdfPath)
                ->as($this->pdfFileName ?: 'Proposta.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
