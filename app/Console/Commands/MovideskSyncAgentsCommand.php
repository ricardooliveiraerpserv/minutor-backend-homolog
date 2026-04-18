<?php

namespace App\Console\Commands;

use App\Models\MovideskAgent;
use App\Models\MovideskTicket;
use App\Models\User;
use App\Services\MovideskService;
use Illuminate\Console\Command;

class MovideskSyncAgentsCommand extends Command
{
    protected $signature   = 'movidesk:sync-agents';
    protected $description = 'Sincroniza agentes do Movidesk (status ativo/inativo) via /persons';

    public function handle(MovideskService $service): int
    {
        $this->info('Buscando emails únicos dos responsáveis nos tickets...');

        $emails = MovideskTicket::whereNotNull('owner_email')
            ->distinct()
            ->pluck('owner_email')
            ->map(fn($e) => strtolower(trim($e)))
            ->filter()
            ->values();

        $this->info(count($emails) . ' emails únicos encontrados. Buscando no Movidesk (1 por vez)...');

        $usersByEmail = User::whereNotNull('email')
            ->get()
            ->keyBy(fn($u) => strtolower(trim($u->email)));

        $agents = $service->fetchAgents($emails->toArray());
        $this->info(count($agents) . ' agentes retornados pelo Movidesk.');

        $saved = 0;
        foreach ($agents as $agent) {
            $userId = $usersByEmail[strtolower($agent['email'])]->id ?? null;

            MovideskAgent::updateOrCreate(
                ['movidesk_id' => (string) $agent['id']],
                [
                    'name'      => $agent['name'],
                    'email'     => $agent['email'],
                    'is_active' => $agent['isActive'],
                    'team'      => $agent['team'],
                    'user_id'   => $userId,
                ]
            );
            $saved++;
        }

        $this->info("{$saved} agentes sincronizados.");
        return self::SUCCESS;
    }
}
