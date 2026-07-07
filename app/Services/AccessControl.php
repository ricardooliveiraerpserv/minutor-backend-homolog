<?php

namespace App\Services;

use App\Models\NavScreen;
use App\Models\Timesheet;
use App\Models\User;

/**
 * Camada de decisão de acesso POR LINHA (row-level) + motivo. NÃO é um motor novo de permissões:
 * concentra as regras de negócio que a Policy expõe e adiciona o "porquê" para a UI.
 *
 * Escopo atual: APENAS Apontamentos (Timesheets). A TimesheetPolicy delega para cá; o dataset
 * recebe flags via trait WithAbilities; o controller usa para bloquear com mensagem clara.
 *
 * Prioridade (ações update/delete):
 *   1. Bloqueio por status (aprovado/estado final) — vale inclusive para admin
 *   2. Caminhos de acesso: dono, coordenador do projeto, parceiro (escopo), permissão ampla
 *   3. Caso contrário → negado, com motivo
 */
class AccessControl
{
    public const R_APPROVED      = 'Apontamento já aprovado';
    public const R_STATUS        = 'Apontamento não pode ser alterado neste status';
    public const R_NOT_OWNER     = 'Você não é o responsável';
    public const R_NO_PERMISSION = 'Você não tem permissão';

    /** @return array{allowed: bool, reason: ?string} */
    public static function decide(User $user, string $action, Timesheet $ts): array
    {
        $allow = ['allowed' => true,  'reason' => null];
        $deny  = fn (string $r): array => ['allowed' => false, 'reason' => $r];

        $isAdmin = $user->isAdmin() || $user->hasAccess('admin.full_access');

        if (in_array($action, ['update', 'delete'], true)) {
            // 1) Bloqueio por status — prioridade máxima (aprovado é final; reabra antes)
            if (!$ts->canBeEdited()) {
                return $deny($ts->status === Timesheet::STATUS_APPROVED ? self::R_APPROVED : self::R_STATUS);
            }
            if ($isAdmin) return $allow;

            // 2) Caminhos de acesso (usuário → perfil)
            $owner   = $ts->user_id === $user->id;
            $coord   = $user->isCoordenador() && in_array($ts->project_id, $user->coordinatedProjectIds(), true);
            $partner = $user->isParceiroAdmin() && $user->partner_id !== null
                       && optional($ts->user)->partner_id === $user->partner_id;
            $broad   = $user->hasAccess($action === 'delete' ? 'hours.delete_all' : 'hours.update_all');

            if ($owner || $coord || $partner || $broad) return $allow;

            // 3) Motivo do bloqueio
            return $deny($user->hasAccess('timesheets.manage') ? self::R_NOT_OWNER : self::R_NO_PERMISSION);
        }

        if ($action === 'approve') {
            if ($isAdmin) return $allow;
            if (!$user->hasAccess('timesheets.approve')) return $deny(self::R_NO_PERMISSION);
            $coord   = $user->isCoordenador() && in_array($ts->project_id, $user->coordinatedProjectIds(), true);
            $partner = $user->isParceiroAdmin() && $user->partner_id !== null
                       && optional($ts->user)->partner_id === $user->partner_id;
            return ($coord || $partner) ? $allow : $deny(self::R_NOT_OWNER);
        }

        // outras ações: admin libera; demais sem regra específica aqui
        return $isAdmin ? $allow : $deny(self::R_NO_PERMISSION);
    }

    /**
     * Valida uma AÇÃO de TELA para um usuário (screen + action + user), com base na configuração
     * nav_screens.abilities (perfis liberados, usuários liberados, usuários retirados). Prioridade:
     *   admin (bypass) > usuário retirado (deny) > usuário liberado > perfil liberado.
     * Sem config explícita da ação → cai na visibilidade do menu (nav_screens.profiles).
     */
    public static function allows(User $user, string $screenKey, string $actionKey): bool
    {
        if ($user->isAdmin() || $user->hasAccess('admin.full_access')) return true;

        $screen = NavScreen::where('key', $screenKey)->first();
        if (!$screen) return false;

        $eff = $user->effectiveProfiles();
        $ab = ($screen->abilities[$actionKey] ?? null);
        if (!is_array($ab)) {
            // sem regra por ação → herda os perfis de visibilidade da tela
            return (bool) array_intersect($eff, $screen->profiles ?? []);
        }
        if (in_array($user->id, array_map('intval', $ab['deny_users'] ?? []), true)) return false; // retirado
        if (in_array($user->id, array_map('intval', $ab['users'] ?? []), true)) return true;        // liberado direto
        return (bool) array_intersect($eff, $ab['profiles'] ?? []);                                  // perfil
    }

    /**
     * Quem tem acesso à linha + de onde vem a permissão do usuário atual (explicabilidade).
     * NÃO altera o motor — só descreve as decisões já calculadas.
     * @return array{profiles: array, users: array, source: array}
     */
    public static function explain(User $user, Timesheet $ts): array
    {
        $ts->loadMissing(['user', 'project.coordinators']);

        // Perfis com algum caminho de acesso a ESTA linha
        $profiles = ['admin' => 'Admin'];
        $coords = $ts->project?->coordinators ?? collect();
        if ($coords->isNotEmpty()) $profiles['coordenador'] = 'Coordenador';
        $ownerType = optional($ts->user)->type;
        $ownerLabels = ['consultor' => 'Consultor', 'parceiro_admin' => 'Parceiro', 'administrativo' => 'Administrativo', 'coordenador' => 'Coordenador'];
        if ($ownerType && isset($ownerLabels[$ownerType])) $profiles[$ownerType] = $ownerLabels[$ownerType];

        // Usuários com acesso direto (responsável + coordenadores do projeto)
        $users = [];
        if ($ts->user) $users[$ts->user->id] = ['id' => $ts->user->id, 'name' => $ts->user->name, 'role' => 'Responsável'];
        foreach ($coords as $c) $users[$c->id] = ['id' => $c->id, 'name' => $c->name, 'role' => 'Coordenador do projeto'];

        // De onde vem (ou por que falha) a decisão do usuário atual
        $source = [];
        if (!$ts->canBeEdited()) $source[] = $ts->status === Timesheet::STATUS_APPROVED ? 'status_block_approved' : 'status_block';
        if ($user->isAdmin() || $user->hasAccess('admin.full_access')) $source[] = 'admin';
        if ($ts->user_id === $user->id) $source[] = 'owner';
        if ($user->isCoordenador() && in_array($ts->project_id, $user->coordinatedProjectIds(), true)) $source[] = 'project_coordinator';
        if ($user->isParceiroAdmin() && $user->partner_id !== null && optional($ts->user)->partner_id === $user->partner_id) $source[] = 'partner_scope';
        if ($user->hasAccess('hours.update_all') || $user->hasAccess('hours.delete_all')) $source[] = 'global_permission';

        return [
            'profiles' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys($profiles), array_values($profiles)),
            'users'    => array_values($users),
            'source'   => $source,
        ];
    }

    private static function abilityAllows(User $user, $ab): bool
    {
        if (!is_array($ab)) return true;
        $uid = (int) $user->id;
        if (in_array($uid, array_map('intval', $ab['deny_users'] ?? []), true)) return false;
        if (in_array($uid, array_map('intval', $ab['users'] ?? []), true)) return true;
        if (array_intersect($user->effectiveProfiles(), $ab['deny_profiles'] ?? [])) return false;
        return true;
    }

    /**
     * Ações NEGADAS ao usuário, por tela — só o que está bloqueado (padrão é permitido).
     * Consumido pelo FE (hook) p/ esconder/desabilitar botões. Admin → vazio (vê tudo).
     * @return array<string, string[]>  { screenKey: [actionKeys negados] }
     */
    public static function deniedActionsFor(User $user): array
    {
        if ($user->isAdmin() || $user->hasAccess('admin.full_access')) return [];
        $out = [];
        foreach (NavScreen::whereNotNull('abilities')->get(['key', 'abilities']) as $screen) {
            $abilities = $screen->abilities;
            if (!is_array($abilities)) continue;
            $denied = [];
            foreach ($abilities as $actionKey => $ab) {
                if (!self::abilityAllows($user, $ab)) $denied[] = $actionKey;
            }
            if ($denied) $out[$screen->key] = array_values($denied);
        }
        return $out;
    }
}
