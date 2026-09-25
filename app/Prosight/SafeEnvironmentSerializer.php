<?php

namespace App\Prosight;

use Illuminate\Support\Collection;

/**
 * Prosight C3 — projeção SEGURA (allowlist, deny-by-default) de um ambiente do Cofre Env*.
 *
 * FRONTEIRA: o Cofre sabe os segredos; o Prosight só sabe que o ambiente EXISTE. Este serializer
 * emite APENAS campos explicitamente aprovados. NUNCA recebe/emite: env_secrets, qualquer *_secret_id,
 * credenciais, VPN, certificado, root_path, port, rdp_host/port, server/instance/database/username,
 * URL de link, inventory bruto, notes, ciphertext. Se um campo não está aqui, ele NÃO sai.
 *
 * Métricas ao vivo (health/AppServer online/RPO) pertencem à Trilha Conector (Bloco B) — aqui
 * saem sempre como "aguardando_conector"; nunca inferidas de version/build/patch.
 */
class SafeEnvironmentSerializer
{
    /** status é CADASTRAL (manual no Cofre) — rótulos honestos, nunca "Online" como se fosse health. */
    private const STATUS_MAP = [
        'online'      => ['ativo', 'Ativo (cadastral)'],
        'offline'     => ['inativo', 'Inativo (cadastral)'],
        'maintenance' => ['manutencao', 'Em manutenção'],
        'unknown'     => ['indefinido', 'Status técnico indisponível'],
    ];

    private const STATUS_NOTE = 'Status cadastral informado no Cofre — não representa health em tempo real.';

    /** Só tipos de link úteis e aprovados para a tela (sem URL). rdp/sharepoint/azure/aws/other ficam no Cofre. */
    public const ALLOWED_LINK_KINDS = ['tss', 'fluig', 'portal', 'powerbi'];

    /**
     * @param  object  $env           linha de env_environments (id, customer_id, name, type, status, created_at, updated_at)
     * @param  Collection  $appservers  linhas safe de env_appservers (name, version, build, patch)
     * @param  Collection  $databases   linhas safe de env_databases (engine)
     * @param  Collection  $links       linhas safe de env_links já filtradas por ALLOWED_LINK_KINDS (label, kind)
     * @param  string|null  $responsibleName  nome do responsável (não sensível) ou null
     * @return array a projeção segura (SafeEnvironment)
     */
    public function serialize(object $env, Collection $appservers, Collection $databases, Collection $links, ?string $responsibleName): array
    {
        [$statusCode, $statusLabel] = self::STATUS_MAP[$env->status] ?? self::STATUS_MAP['unknown'];

        $appservers = $appservers->map(fn ($a) => [
            'name'    => $a->name,
            'version' => $a->version,
            'build'   => $a->build,
            'patch'   => $a->patch,
        ])->values()->all();

        // Banco: SOMENTE engine (nunca server/instance/database/username/senha).
        $engines = $databases->pluck('engine')->filter()->unique()->values();
        $databasesOut = $engines->map(fn ($e) => ['engine' => $e])->all();

        $linksOut = $links->map(fn ($l) => ['label' => $l->label, 'kind' => $l->kind])->values()->all();

        // Componentes CONSERVADORES: só o que agrega à tela. VPN/certificado NÃO entram por mera existência.
        $components = [];
        if ($appservers !== []) {
            $components[] = 'protheus';
            $components[] = 'appserver';
        }
        if ($databasesOut !== []) {
            $components[] = 'dbaccess';
        }
        foreach ($linksOut as $l) {
            $components[] = $l['kind']; // tss|fluig|portal|powerbi
        }
        $components = array_values(array_unique($components));

        return [
            'id'          => (int) $env->id,
            'customer_id' => (int) $env->customer_id,
            'name'        => $env->name,
            'type'        => $env->type, // prod|homolog|dev|dr
            'status'      => ['code' => $statusCode, 'label' => $statusLabel, 'note' => self::STATUS_NOTE],
            'components'  => $components,
            'appservers'  => $appservers,
            'databases'   => $databasesOut,
            'links'       => $linksOut,
            'responsible_name' => $responsibleName,
            'created_at'  => $env->created_at ? (string) $env->created_at : null,
            'updated_at'  => $env->updated_at ? (string) $env->updated_at : null,
            // Bloco B / Conector — nunca inventado, nunca inferido de build/patch.
            'live'        => ['health' => 'aguardando_conector', 'rpo' => 'aguardando_conector'],
        ];
    }

    /**
     * C4 — detalhe cadastral de UM ambiente (Configuração). Mesma fronteira allowlist do serialize().
     * NÃO inclui: pending_capabilities (a UI rotula "Aguardando Conector" estaticamente — o Env NÃO
     * conhece estado live), backup_info, host/porta/path/URL/secret. always_on é CONFIG CADASTRADA
     * (não estado AlwaysOn ao vivo) — a UI rotula assim.
     */
    public function serializeConfig(object $env, Collection $appservers, Collection $databases, Collection $links, ?string $responsibleName): array
    {
        [$statusCode, $statusLabel] = self::STATUS_MAP[$env->status] ?? self::STATUS_MAP['unknown'];

        return [
            'environment' => [
                'id'          => (int) $env->id,
                'customer_id' => (int) $env->customer_id,
                'name'        => $env->name,
                'type'        => $env->type,
                'status'      => ['code' => $statusCode, 'label' => $statusLabel, 'note' => self::STATUS_NOTE],
                'responsible_name' => $responsibleName,
                'updated_at'  => $env->updated_at ? (string) $env->updated_at : null,
            ],
            'appservers'  => $appservers->map(fn ($a) => [
                'name' => $a->name, 'version' => $a->version, 'build' => $a->build, 'patch' => $a->patch,
            ])->values()->all(),
            // engine + always_on (config CADASTRADA, não estado live). Sem server/instance/database/username/secret.
            'databases'   => $databases->map(fn ($d) => [
                'engine' => $d->engine, 'always_on_cadastrado' => (bool) $d->always_on,
            ])->values()->all(),
            'links'       => $links->map(fn ($l) => ['label' => $l->label, 'kind' => $l->kind])->values()->all(),
        ];
    }
}
