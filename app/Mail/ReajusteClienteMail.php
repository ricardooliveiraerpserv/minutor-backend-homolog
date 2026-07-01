<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Comunicado de reajuste contratual enviado AO CLIENTE no momento da aplicação.
 * Tom formal, tema claro (igual aos e-mails de cliente). Informa índice, período,
 * percentual e o valor anterior → novo, com data de vigência.
 */
class ReajusteClienteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $cliente,
        public ?string $contrato,
        public float $valorAnterior,
        public float $valorNovo,
        public float $percentual,
        public string $indice,
        public ?string $periodoFormatado,
        public string $vigencia,
        public ?string $mensagem = null, // corpo editável (substitui o texto padrão)
        public bool $estorno = false,    // modo estorno: comunica o cancelamento do reajuste
    ) {
    }

    /** Texto padrão do corpo (semeia o editor no FE). */
    public static function defaultMensagem(?string $contrato, float $percentual, string $indice, ?string $periodoFormatado): string
    {
        $indiceLabel = $indice === 'IGPM' ? 'IGP-M' : $indice;
        $ref  = $contrato ? " ({$contrato})" : '';
        $per  = $periodoFormatado ? " no período de {$periodoFormatado}" : '';
        $pct  = number_format($percentual, 2, ',', '.');
        return "Em conformidade com o seu contrato{$ref}, informamos o reajuste do valor contratado, "
             . "calculado pela variação acumulada do índice {$indiceLabel}{$per}, correspondente a +{$pct}%.";
    }

    public function envelope(): Envelope
    {
        $ref = $this->contrato ? " — {$this->contrato}" : '';
        $tipo = $this->estorno ? 'Estorno de reajuste contratual' : 'Comunicado de reajuste contratual';
        return new Envelope(subject: "{$tipo}{$ref}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contracts.reajuste-cliente');
    }
}
