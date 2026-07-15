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
        public bool $aviso = false,      // modo aviso prévio: reajuste no próximo mês (estimativa)
    ) {
    }

    /** Texto padrão do corpo por modo (semeia o editor no FE). mode: reajuste|estorno|aviso */
    public static function defaultMensagem(?string $contrato, float $percentual, string $indice, ?string $periodoFormatado, string $mode = 'reajuste'): string
    {
        $indiceLabel = $indice === 'IGPM' ? 'IGP-M' : $indice;
        $ref  = $contrato ? " ({$contrato})" : '';
        $per  = $periodoFormatado ? " no período de {$periodoFormatado}" : '';
        $pct  = number_format($percentual, 2, ',', '.');
        if ($mode === 'estorno') {
            return "Informamos o estorno do reajuste anteriormente aplicado ao seu contrato{$ref}. "
                 . "O valor volta ao praticado antes do reajuste, conforme abaixo. Pedimos desconsiderar o comunicado anterior.";
        }
        if ($mode === 'aviso') {
            return "Informamos que o seu contrato{$ref} passará por reajuste a partir do próximo mês, conforme o índice {$indiceLabel}. "
                 . "A estimativa atual é de aproximadamente +{$pct}% (acumulado até o momento). "
                 . "Importante: este percentual ainda NÃO é o definitivo — o valor final depende do índice fechado do próximo mês, que confirmaremos na aplicação.";
        }
        return "Em conformidade com o seu contrato{$ref}, informamos o reajuste do valor contratado, "
             . "calculado pela variação acumulada do índice {$indiceLabel}{$per}, correspondente a +{$pct}%.";
    }

    public function envelope(): Envelope
    {
        $ref = $this->contrato ? " — {$this->contrato}" : '';
        $tipo = $this->aviso ? 'Aviso de reajuste contratual (próximo mês)'
              : ($this->estorno ? 'Estorno de reajuste contratual' : 'Comunicado de reajuste contratual');
        return new Envelope(subject: "{$tipo}{$ref}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contracts.reajuste-cliente');
    }
}
