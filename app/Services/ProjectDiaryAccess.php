<?php

namespace App\Services;

use App\Models\PipelineViewPermission;
use App\Models\Project;
use App\Models\ProjectMessageParticipant;

/**
 * Fonte ÚNICA da regra de acesso ao Diário do Projeto.
 *
 * Usada tanto pelo ProjectMessageController (que barra a API) quanto pelo
 * ProjectController (que expõe `diary_access` p/ o FE mostrar/esconder a aba).
 * Manter as duas em sincronia: se divergirem, a aba aparece e a API dá 403.
 */
class ProjectDiaryAccess
{
    public static function allows($user, Project $project): bool
    {
        if (!$user) {
            return false;
        }
        if ($user->isAdmin() || (method_exists($user, 'isAdministrativo') && $user->isAdministrativo())) {
            return true;
        }
        // Legado: convites explícitos criados antes de o Diário derivar o acesso
        // de alocação + Liberação de Visualização. Ninguém perde acesso já concedido.
        if (ProjectMessageParticipant::where('project_id', $project->id)->where('user_id', $user->id)->exists()) {
            return true;
        }
        if ($user->isCoordenador()) {
            return $project->coordinators()->where('users.id', $user->id)->exists();
        }
        if ($user->isCliente() && $user->customer_id) {
            return $project->customer_id === $user->customer_id;
        }
        // Consultor (e parceiro): precisa estar alocado no projeto E ter a Liberação de
        // Visualização cobrindo o cliente daquele projeto.
        return $project->consultants()->where('users.id', $user->id)->exists()
            && self::pipelineAllowsProject($user, $project);
    }

    /** A Liberação de Visualização (pipeline_view_permissions) cobre o cliente deste projeto? */
    public static function pipelineAllowsProject($user, Project $project): bool
    {
        $perm = PipelineViewPermission::effectiveFor($user)['project'];
        if (!$perm['visible']) {
            return false;
        }
        if (($perm['scope'] ?? 'all') === 'all') {
            return true;
        }
        return in_array((int) $project->customer_id, $perm['customer_ids'], true);
    }
}
