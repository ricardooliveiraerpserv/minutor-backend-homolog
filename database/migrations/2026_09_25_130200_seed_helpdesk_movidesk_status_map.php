<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Semeia o de-para HD ↔ Movidesk com os status REAIS da conta Movidesk da Promax:
 *   New/Novo, InAttendance/Em atendimento, Stopped/(Pendente cliente|Pausado|Pendente aprovação|
 *   Agendado|Pendente Terceiros|Pendente TOTVS), Resolved/Resolvido, Closed/Fechado, Canceled/Cancelado.
 *
 * Dirigido por dados: usa só os status HD que EXISTIREM em cada empresa (dev2 tem 6; homolog 11),
 * com fallback quando o status alvo não existe naquela empresa. Idempotente por empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('helpdesk_movidesk_status_map')) return;

        // ENTRADA (Movidesk → HD): [base, text, key preferido, fallbacks...]
        $inbound = [
            ['New',          'Novo',               'novo'],
            ['InAttendance', 'Em atendimento',     'em_andamento'],
            ['Stopped',      'Pendente cliente',   'aguardando_cliente'],
            ['Stopped',      'Pausado',            'aguardando_cliente'],
            ['Stopped',      'Pendente aprovação', 'aguardando_cliente'],
            ['Stopped',      'Agendado',           'reuniao_agendada', 'aguardando_cliente'],
            ['Stopped',      'Pendente Terceiros', 'pendente_terceiros', 'aguardando_cliente'],
            ['Stopped',      'Pendente TOTVS',     'pendente_terceiros', 'aguardando_cliente'],
            ['Resolved',     'Resolvido',          'resolvido'],
            ['Closed',       'Fechado',            'fechado'],
            ['Canceled',     'Cancelado',          'cancelado'],
            // Redes de segurança por BASE (qualquer sub-status não mapeado acima):
            ['New',          null, 'novo'],
            ['InAttendance', null, 'em_andamento'],
            ['Stopped',      null, 'aguardando_cliente'],
            ['Resolved',     null, 'resolvido'],
            ['Closed',       null, 'fechado'],
            ['Canceled',     null, 'cancelado'],
        ];

        // SAÍDA (HD → Movidesk) — só usada na Fase 2 (hoje NÃO escrevemos no Movidesk):
        // [key HD, base, text]. Status HD sem par no Movidesk caem no base mais próximo.
        $outbound = [
            ['novo',               'New',          'Novo'],
            ['em_andamento',       'InAttendance', 'Em atendimento'],
            ['aguardando_cliente', 'Stopped',      'Pendente cliente'],
            ['pendente_terceiros', 'Stopped',      'Pendente Terceiros'],
            ['reuniao_agendada',   'Stopped',      'Agendado'],
            ['planejamento_gmud',  'InAttendance', 'Em atendimento'],
            ['em_desenvolvimento', 'InAttendance', 'Em atendimento'],
            ['solucao_gmud',       'Resolved',     'Resolvido'],
            ['resolvido',          'Resolved',     'Resolvido'],
            ['fechado',            'Closed',        'Fechado'],
            ['cancelado',          'Canceled',     'Cancelado'],
        ];

        // Agrupa status HD por empresa (company_id null = global).
        $statuses = DB::table('helpdesk_statuses')->get(['id', 'key', 'company_id']);
        $byCompany = [];
        foreach ($statuses as $s) {
            $byCompany[$s->company_id === null ? '_null' : (string) $s->company_id][$s->key] = $s->id;
        }

        $now = now();
        foreach ($byCompany as $cKey => $keyToId) {
            $companyId = $cKey === '_null' ? null : (int) $cKey;

            // Idempotente: não reescreve se já houver de-para para esta empresa.
            $exists = DB::table('helpdesk_movidesk_status_map')
                ->when($companyId === null, fn ($q) => $q->whereNull('company_id'))
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->exists();
            if ($exists) continue;

            $resolve = function (array $candidates) use ($keyToId): ?int {
                foreach ($candidates as $k) {
                    if (isset($keyToId[$k])) return $keyToId[$k];
                }
                return null;
            };

            $rows = [];

            foreach ($inbound as $spec) {
                [$base, $text] = [$spec[0], $spec[1]];
                $candidates = array_slice($spec, 2);
                $sid = $resolve($candidates);
                if ($sid === null) continue;
                $rows[] = [
                    'company_id' => $companyId, 'helpdesk_status_id' => $sid,
                    'movidesk_base_status' => $base, 'movidesk_status_text' => $text,
                    'is_outbound_default' => false, 'created_at' => $now, 'updated_at' => $now,
                ];
            }

            foreach ($outbound as [$key, $base, $text]) {
                if (!isset($keyToId[$key])) continue;
                $rows[] = [
                    'company_id' => $companyId, 'helpdesk_status_id' => $keyToId[$key],
                    'movidesk_base_status' => $base, 'movidesk_status_text' => $text,
                    'is_outbound_default' => true, 'created_at' => $now, 'updated_at' => $now,
                ];
            }

            if ($rows) DB::table('helpdesk_movidesk_status_map')->insert($rows);
        }
    }

    public function down(): void
    {
        if (DB::getSchemaBuilder()->hasTable('helpdesk_movidesk_status_map')) {
            DB::table('helpdesk_movidesk_status_map')->truncate();
        }
    }
};
