<?php

namespace App\Services;

use App\Models\HelpDeskStatus;
use App\Models\SystemSetting;

/**
 * Colunas do Kanban do PORTAL DO CLIENTE — configuração GLOBAL (vale para todos os clientes).
 *
 * O gestor define quais STATUS entram em cada coluna. Guardado como JSON no `system_settings`
 * (com cache). Cada coluna: { label, cor, fallback, rule, statuses:[key,...] }.
 *  - `fallback` recebe os status que não foram mapeados em nenhuma coluna (comportamento antigo).
 *  - `rule` = coluna ESPECIAL, que não casa por status: hoje só 'scheduled' (captura todo chamado
 *    COM agendamento, independente do status). O chamado agendado fica só nessa coluna.
 */
class HelpDeskPortalColumns
{
    public const SETTING_KEY = 'helpdesk.portal_kanban_columns';

    /** Config atual: salva (normalizada) ou o default histórico. */
    public static function get(): array
    {
        $saved = SystemSetting::get(self::SETTING_KEY);

        return is_array($saved) && count($saved) > 0
            ? self::normalize($saved)
            : self::default();
    }

    /** Persiste a config global (normalizada) e devolve o resultado salvo. */
    public static function save(array $columns): array
    {
        $clean = self::normalize($columns);
        SystemSetting::set(self::SETTING_KEY, $clean, 'json', 'helpdesk', 'Colunas do Kanban do portal do cliente');

        return $clean;
    }

    /** Default = mesmo mapeamento que era fixo (hardcoded) no frontend do portal. */
    public static function default(): array
    {
        return [
            ['label' => 'Pendente ERPSERV',   'cor' => '#3b82f6', 'fallback' => true,  'statuses' => ['novo', 'em_andamento', 'planejamento_gmud', 'em_desenvolvimento']],
            ['label' => 'Pendente cliente',   'cor' => '#f59e0b', 'fallback' => false, 'statuses' => ['aguardando_cliente']],
            ['label' => 'Pendente terceiros', 'cor' => '#a855f7', 'fallback' => false, 'statuses' => ['pendente_terceiros']],
            ['label' => 'Resolvido',          'cor' => '#16a34a', 'fallback' => false, 'statuses' => ['resolvido', 'solucao_gmud']],
            ['label' => 'Fechado',            'cor' => '#6b7280', 'fallback' => false, 'statuses' => ['fechado', 'cancelado']],
        ];
    }

    /** Regras especiais suportadas (colunas que não casam por status). */
    private const RULES = ['scheduled'];

    /** Saneia a config: descarta colunas sem label, status inexistentes e >1 fallback. */
    private static function normalize(array $columns): array
    {
        $validKeys = HelpDeskStatus::pluck('key')->map(fn ($k) => (string) $k)->all();
        $out = [];
        $fallbackTaken = false;

        foreach ($columns as $c) {
            if (!is_array($c)) {
                continue;
            }
            $label = trim((string) ($c['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $rule = in_array($c['rule'] ?? null, self::RULES, true) ? $c['rule'] : null;
            // Coluna especial (rule) não casa por status nem é fallback.
            $statuses = $rule ? [] : array_values(array_unique(array_intersect(
                array_map('strval', (array) ($c['statuses'] ?? [])),
                $validKeys
            )));
            $isFallback = !$rule && !$fallbackTaken && (bool) ($c['fallback'] ?? false);
            if ($isFallback) {
                $fallbackTaken = true;
            }
            $out[] = [
                'label'    => mb_substr($label, 0, 60),
                'cor'      => self::sanitizeColor($c['cor'] ?? null),
                'fallback' => $isFallback,
                'rule'     => $rule,
                'statuses' => $statuses,
            ];
        }

        // Garante ao menos um fallback numa coluna comum (senão status não mapeado sumiria da tela).
        if (!$fallbackTaken) {
            foreach ($out as &$col) {
                if (!$col['rule']) {
                    $col['fallback'] = true;
                    break;
                }
            }
            unset($col);
        }

        return $out;
    }

    private static function sanitizeColor($c): ?string
    {
        $c = is_string($c) ? trim($c) : '';

        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : null;
    }
}
