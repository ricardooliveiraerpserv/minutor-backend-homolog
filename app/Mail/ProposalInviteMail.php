<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Convite individual de PARTICIPANTE da Proposta (P-B.1).
 *
 * Cada participante recebe seu LINK exclusivo do Portal (`/p/{token}?pt={participant_token}`),
 * com os papéis dele (revisar / aprovar / assinar). Sem cópia manual de links pelo comercial.
 * From = caixa do usuário que convidou (Send As) quando configurado; senão, conta padrão.
 */
class ProposalInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $participantName,
        public string $papeisLabel,
        public string $clienteName,
        public string $senderName,
        public string $subjectLine,
        public string $portalUrl,
        public ?string $codigo = null,
        public ?string $tipoLabel = null,
        public ?string $valorTotal = null,
        public ?string $validade = null,
        public ?string $senderEmail = null,
        public ?string $mensagem = null,
        public bool $isReenvio = false,
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
        } else {
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
            view: 'emails.crm.convite-participante',
            with: [
                'participantName' => $this->participantName,
                'papeisLabel'     => $this->papeisLabel,
                'clienteName'     => $this->clienteName,
                'senderName'      => $this->senderName,
                'codigo'          => $this->codigo,
                'tipoLabel'       => $this->tipoLabel,
                'valorTotal'      => $this->valorTotal,
                'validade'        => $this->validade,
                'mensagem'        => $this->mensagem ?? '',
                'portalUrl'       => $this->portalUrl,
                'isReenvio'       => $this->isReenvio,
            ],
        );
    }
}
