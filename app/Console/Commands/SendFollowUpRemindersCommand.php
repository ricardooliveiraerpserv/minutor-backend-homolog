<?php

namespace App\Console\Commands;

use App\Mail\FollowUpReminderMail;
use App\Models\FollowUp;
use App\Models\FollowUpReminder;
use App\Services\FollowUpN8nNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Cobranças automáticas de Follow Up: 5/3/1 dias antes, no vencimento e em atraso.
 * Pula waiting_third (SLA pausado) e concluídos/cancelados. Idempotente via
 * follow_up_reminders (não reenvia o mesmo kind; "overdue" 1x por dia).
 */
class SendFollowUpRemindersCommand extends Command
{
    protected $signature = 'followups:send-reminders {--dry : Apenas lista, não envia}';
    protected $description = 'Envia lembretes/cobranças dos Follow Ups com prazo';

    public function handle(FollowUpN8nNotifier $n8n): int
    {
        $today = Carbon::now()->startOfDay();
        $dry = (bool) $this->option('dry');

        // Abertos (pending/in_progress) com prazo — open() exclui waiting_third/concluído/cancelado.
        $items = FollowUp::open()->whereNotNull('due_date')->with(['responsible', 'project', 'customer'])->get();
        $sent = 0;

        foreach ($items as $f) {
            // datas em startOfDay → diferença é nº inteiro de dias; (int) evita falha do === com float (Carbon 3)
            $diff = (int) $today->diffInDays(Carbon::parse($f->due_date)->startOfDay(), false); // <0 = atrasado
            $kind = match (true) {
                $diff === 5 => 'd5',
                $diff === 3 => 'd3',
                $diff === 1 => 'd1',
                $diff === 0 => 'due',
                $diff <  0  => 'overdue',
                default     => null,
            };
            if ($kind === null) continue;

            // Idempotência: d5/d3/d1/due 1x (o dia exato só ocorre uma vez); overdue 1x/dia.
            $already = FollowUpReminder::where('follow_up_id', $f->id)->where('kind', $kind)
                ->when($kind === 'overdue', fn ($q) => $q->whereDate('sent_on', $today->toDateString()))
                ->exists();
            if ($already) continue;

            $this->line(($dry ? '[dry] ' : '') . "#{$f->id} {$kind} — {$f->title}");
            if ($dry) { $sent++; continue; }

            FollowUpReminder::create(['follow_up_id' => $f->id, 'kind' => $kind, 'sent_on' => $today->toDateString()]);

            if ($email = optional($f->responsible)->email) {
                try { Mail::to($email)->send(new FollowUpReminderMail($f, $kind)); }
                catch (\Throwable $e) { $this->warn("e-mail falhou #{$f->id}: {$e->getMessage()}"); }
            }
            $n8n->notify($f, $kind);
            $sent++;
        }

        $this->info(($dry ? '[dry] ' : '') . "{$sent} cobrança(s) processada(s).");
        return self::SUCCESS;
    }
}
