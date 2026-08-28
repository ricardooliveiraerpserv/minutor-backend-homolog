<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Timesheet;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Lembrete NO FECHAMENTO: apontamentos em atraso aprovados p/ o cliente mas ainda NÃO
 * liberados para o consultor (consultant_released=false). Avisa o consultor (não está
 * sendo contabilizado) e o coordenador do projeto (precisa liberar). Roda na janela de
 * fechamento (fim/início do mês); fora dela sai sem agir, a não ser --force.
 */
class RemindConsultantRelease extends Command
{
    protected $signature = 'timesheets:remind-consultant-release {--force : ignora a janela de fechamento}';
    protected $description = 'Lembra consultores e coordenadores de apontamentos de atraso ainda não liberados p/ pagamento.';

    public function handle(): int
    {
        $now = Carbon::now('America/Sao_Paulo');
        $inWindow = $now->day <= 2 || $now->day >= 26; // janela de fechamento
        if (!$inWindow && !$this->option('force')) {
            $this->info('Fora da janela de fechamento — nada a fazer.');
            return self::SUCCESS;
        }

        $pending = Timesheet::with(['user:id,name,email', 'project:id,name'])
            ->whereNotNull('late_approved_at')
            ->where('consultant_released', false)
            ->whereNull('deleted_at')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nenhuma liberação pendente.');
            return self::SUCCESS;
        }

        // 1) Por CONSULTOR: refresca o pop-up + reenvia o e-mail (é lembrete → sempre envia).
        foreach ($pending->groupBy('user_id') as $userId => $items) {
            $owner = $items->first()->user;
            if (!$owner) continue;
            $n = $items->count();
            try {
                $url = '/timesheets?consultor_atraso=1';
                $msg = "Você tem {$n} apontamento(s) em atraso ainda NÃO contabilizado(s) no seu pagamento. Fale com o seu coordenador para liberar as horas antes do fechamento.";
                $attrs = [
                    'title' => 'Apontamento em atraso — não contabilizado', 'message' => $msg,
                    'type' => 'action', 'priority' => 'high', 'target_users' => [$owner->id],
                    'cta_label' => 'Ver apontamentos', 'cta_url' => $url, 'send_email' => false,
                    'visible' => true, 'created_by' => $owner->id, 'expires_at' => now()->addDays(30),
                ];
                $existing = AppNotification::where('type', 'action')->where('cta_url', $url)->where('visible', true)
                    ->whereJsonContains('target_users', $owner->id)->orderByDesc('id')->get()
                    ->first(fn ($x) => array_map('intval', $x->target_users ?? []) === [$owner->id]);
                $existing ? $existing->forceFill($attrs)->save() : AppNotification::create($attrs);

                if ($owner->email) {
                    app(\App\Workflows\WorkflowMailer::class)->send(
                        'timesheet.atraso_consultor',
                        ['consultant' => $owner, 'project' => $items->first()->project, 'actor' => $owner],
                        ['data' => optional($items->first()->date)->format('d/m/Y') ?? '', 'projeto' => $items->first()->project?->name ?? '—'],
                    );
                }
            } catch (\Throwable $e) {
                $this->warn("consultor {$userId}: {$e->getMessage()}");
            }
        }

        // 2) Por COORDENADOR do projeto: aviso com a contagem do que ele precisa liberar.
        $byCoord = [];
        foreach ($pending as $t) {
            $t->loadMissing('project.coordinators:id,name');
            foreach (($t->project?->coordinators ?? []) as $c) {
                $byCoord[$c->id] = ($byCoord[$c->id] ?? 0) + 1;
            }
        }
        foreach ($byCoord as $coordId => $count) {
            try {
                $url = '/timesheets/atrasos?tab=consultor';
                $attrs = [
                    'title' => 'Liberação de horas pendente', 'message' => "Há {$count} apontamento(s) em atraso aguardando você liberar as horas do consultor (não entram no pagamento até liberar).",
                    'type' => 'action', 'priority' => 'high', 'target_users' => [$coordId],
                    'cta_label' => 'Liberar horas', 'cta_url' => $url, 'send_email' => false,
                    'visible' => true, 'created_by' => $coordId, 'expires_at' => now()->addDays(30),
                ];
                $existing = AppNotification::where('type', 'action')->where('cta_url', $url)->where('visible', true)
                    ->whereJsonContains('target_users', $coordId)->orderByDesc('id')->get()
                    ->first(fn ($x) => array_map('intval', $x->target_users ?? []) === [$coordId]);
                $existing ? $existing->forceFill($attrs)->save() : AppNotification::create($attrs);
            } catch (\Throwable $e) {
                $this->warn("coordenador {$coordId}: {$e->getMessage()}");
            }
        }

        $this->info("Lembrete enviado: {$pending->count()} pendências, " . count($byCoord) . ' coordenador(es).');
        return self::SUCCESS;
    }
}
