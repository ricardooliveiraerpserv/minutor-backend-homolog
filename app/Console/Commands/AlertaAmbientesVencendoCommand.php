<?php

namespace App\Console\Commands;

use App\Models\EnvCertificate;
use App\Models\EnvCredential;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Cofre de Ambientes — digest diário de vencimentos: certificados vencendo/vencidos
 * e senhas com rotação vencida. Só metadados CLARO (nome/validade), NUNCA segredos.
 *
 * --dry-run: monta e mostra o resumo SEM enviar e-mail (uso em dev/homolog).
 * Destinatários vêm da Central de Workflows (chave environments.alerts).
 */
class AlertaAmbientesVencendoCommand extends Command
{
    protected $signature = 'environments:alertas-vencimento {--days=30 : Janela em dias} {--dry-run : Não envia e-mail, só mostra o resumo}';

    protected $description = 'Alerta de certificados e senhas do Cofre de Ambientes vencendo na janela.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $limit = Carbon::today()->addDays($days)->endOfDay();

        $certs = EnvCertificate::with('environment:id,name,customer_id', 'environment.customer:id,name')
            ->whereNotNull('valid_to')
            ->where('valid_to', '<=', $limit)
            ->orderBy('valid_to')
            ->get();

        $passwords = EnvCredential::with('environment:id,name')
            ->whereNotNull('rotate_every_days')
            ->whereNotNull('last_rotated_at')
            ->get()
            ->filter(fn ($c) => $c->last_rotated_at->copy()->addDays($c->rotate_every_days)->lte($limit));

        if ($certs->isEmpty() && $passwords->isEmpty()) {
            $this->info('Nada vencendo na janela de ' . $days . ' dias.');
            return self::SUCCESS;
        }

        $html = $this->buildHtml($certs, $passwords, $days);

        if ($this->option('dry-run')) {
            $this->info('[dry-run] ' . $certs->count() . ' certificado(s) e ' . $passwords->count() . ' senha(s) na janela. E-mail NÃO enviado.');
            $this->line(strip_tags(str_replace(['</li>', '</h3>'], "\n", $html)));
            return self::SUCCESS;
        }

        $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('environments.alerts', []);
        $to = $rcpt['to'] ?? [];
        $cc = $rcpt['cc'] ?? [];
        if (empty($to)) {
            $this->warn('Sem destinatários na Central (environments.alerts). Nada enviado.');
            return self::SUCCESS;
        }

        $subject = 'Cofre de Ambientes — vencimentos em até ' . $days . ' dias';
        $graphFrom = config('services.graph.mailbox');
        if (\App\Services\GraphMailer::enabled() && $graphFrom) {
            \App\Services\GraphMailer::sendAs($graphFrom, $to, $cc, $subject, $html);
        } else {
            Mail::html($html, function ($m) use ($to, $cc, $subject) {
                $m->to($to)->subject($subject);
                if (! empty($cc)) {
                    $m->cc($cc);
                }
            });
        }

        $this->info('Alerta enviado a ' . implode(', ', $to) . ": {$certs->count()} cert(s), {$passwords->count()} senha(s).");
        return self::SUCCESS;
    }

    private function buildHtml($certs, $passwords, int $days): string
    {
        $hoje = Carbon::today();
        $rows = '';
        foreach ($certs as $c) {
            $d = (int) round($hoje->diffInDays($c->valid_to, false));
            $tag = $d <= 0 ? 'VENCIDO' : "{$d} dias";
            $rows .= "<li><b>Certificado:</b> {$c->name} — {$c->environment?->customer?->name} / {$c->environment?->name} — vence {$c->valid_to?->format('d/m/Y')} (<b>{$tag}</b>)</li>";
        }
        foreach ($passwords as $c) {
            $next = $c->last_rotated_at->copy()->addDays($c->rotate_every_days);
            $d = (int) round($hoje->diffInDays($next, false));
            $tag = $d <= 0 ? 'VENCIDA' : "{$d} dias";
            $rows .= "<li><b>Trocar senha:</b> {$c->label} — {$c->environment?->name} — desde {$next->format('d/m/Y')} (<b>{$tag}</b>)</li>";
        }

        return "<div style=\"font-family:sans-serif;color:#0f172a\">"
            . "<h3>Vencimentos do Cofre de Ambientes (próximos {$days} dias)</h3>"
            . "<ul>{$rows}</ul>"
            . "<p style=\"font-size:12px;color:#64748b\">Alerta automático. Acesse o Cofre de Ambientes para renovar.</p></div>";
    }
}
