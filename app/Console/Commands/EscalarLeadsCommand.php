<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CrmTask;
use App\Models\CrmCustomerEvent;
use App\Models\User;
use App\Notifications\LeadEscalationNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * ESCALONAMENTO AUTOMÁTICO — não depende da disciplina do vendedor.
 * Detecta leads em violação de governança (sem responsável, SLA de 1º contato estourado,
 * parados sem próxima ação) → loga `escalonado` no histórico + notifica os gestores comerciais.
 * Dedupe: não re-escalona o mesmo lead se já houve escalonamento nos últimos 3 dias.
 */
class EscalarLeadsCommand extends Command
{
    protected $signature = 'crm:escalonar-leads {--days=10 : Dias parado sem próxima ação} {--dry-run : Não grava nem notifica}';
    protected $description = 'Escalona leads negligenciados para os gestores comerciais';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dry = (bool) $this->option('dry-run');

        $itens = [];
        Customer::where('crm_status', 'lead')->with(['crmProfile', 'executive:id,name'])
            ->chunkById(200, function ($lote) use (&$itens, $days) {
                foreach ($lote as $c) {
                    $p = $c->crmProfile;
                    if ($p?->lost_at) continue;
                    $base = $p?->lead_created_at ?? $c->created_at;
                    $motivos = [];

                    if ($c->executive_id === null) $motivos[] = 'sem responsável';

                    $primeiroContato = CrmTask::where('customer_id', $c->id)->whereNotNull('concluida_at')->min('concluida_at');
                    if (!$primeiroContato && $base && Carbon::parse($base)->diffInHours(now()) > 24) $motivos[] = 'SLA 1º contato estourado';

                    $temAberta = CrmTask::where('customer_id', $c->id)->whereNull('concluida_at')->exists();
                    $diasSem = $p?->ultima_interacao_at ? (int) Carbon::parse($p->ultima_interacao_at)->diffInDays(now()) : null;
                    $diasParado = $diasSem ?? ($base ? (int) Carbon::parse($base)->diffInDays(now()) : 0);
                    if (!$temAberta && $diasParado >= $days) $motivos[] = "parado {$diasParado}d sem próxima ação";

                    if (!$motivos) continue;
                    $jaEscalado = CrmCustomerEvent::where('customer_id', $c->id)->where('event_type', 'escalonado')
                        ->where('created_at', '>=', now()->subDays(3))->exists();
                    $itens[] = ['customer_id' => $c->id, 'empresa' => $c->name, 'responsavel' => $c->executive?->name,
                        'motivos' => implode(', ', $motivos), 'recent' => $jaEscalado];
                }
            });

        $novos = collect($itens)->where('recent', false)->values();
        if (!$dry) {
            foreach ($novos as $i) {
                CrmCustomerEvent::log($i['customer_id'], 'escalonado', 'Escalonado: ' . $i['motivos'], ['motivos' => $i['motivos']]);
            }
            if ($novos->isNotEmpty()) {
                $gestores = User::whereIn('type', ['admin', 'administrativo', 'coordenador'])
                    ->where('enabled', true)->whereNotNull('email')->get();
                if ($gestores->isNotEmpty()) {
                    try { Notification::send($gestores, new LeadEscalationNotification($novos->all())); }
                    catch (\Throwable $e) { $this->warn('Falha ao notificar gestores: ' . $e->getMessage()); }
                }
            }
        }

        $this->info('Leads em violação: ' . count($itens) . ' | novos escalonados: ' . $novos->count() . ($dry ? ' (dry-run)' : ''));
        return self::SUCCESS;
    }
}
