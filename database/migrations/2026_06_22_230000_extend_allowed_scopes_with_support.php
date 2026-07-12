<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adiciona 'support' aos agents que já tinham TODOS os 9 scopes anteriores
     * (sentinel "tinha tudo liberado"). Não toca em agents com config customizada.
     */
    public function up(): void
    {
        $oldFull = ['customer', 'project', 'contract', 'financial', 'billing', 'payroll', 'bankhours', 'approvals', 'overview'];
        $newFull = array_merge($oldFull, ['support']);

        // No PostgreSQL bot_agents.allowed_scopes é jsonb.
        $agents = DB::table('bot_agents')->whereNotNull('allowed_scopes')->get(['id', 'allowed_scopes']);
        foreach ($agents as $a) {
            $current = is_string($a->allowed_scopes) ? (json_decode($a->allowed_scopes, true) ?: []) : $a->allowed_scopes;
            if (! is_array($current)) continue;
            sort($current); $sortedOld = $oldFull; sort($sortedOld);
            if ($current === $sortedOld) {
                DB::table('bot_agents')->where('id', $a->id)->update([
                    'allowed_scopes' => json_encode($newFull),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Sem reverso seguro — manter como está.
    }
};
