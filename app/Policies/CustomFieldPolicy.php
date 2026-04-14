<?php

namespace App\Policies;

use App\Models\CustomField;
use App\Models\User;

class CustomFieldPolicy
{
    /**
     * Determine if the user can view any custom fields.
     */
    public function viewAny(User $user): bool
    {
        // Qualquer usuário autenticado pode visualizar campos customizados
        return true;
    }

    /**
     * Determine if the user can view the custom field.
     */
    public function view(User $user, CustomField $customField): bool
    {
        // Qualquer usuário autenticado pode visualizar campos customizados
        return true;
    }

    /**
     * Determine if the user can create custom fields.
     */
    public function create(User $user): bool
    {
        // Apenas administradores podem criar campos customizados
        return $user->isAdmin();
    }

    /**
     * Determine if the user can update the custom field.
     */
    public function update(User $user, CustomField $customField): bool
    {
        // Apenas administradores podem atualizar campos customizados
        return $user->isAdmin();
    }

    /**
     * Determine if the user can delete the custom field.
     */
    public function delete(User $user, CustomField $customField): bool
    {
        // Apenas administradores podem deletar campos customizados
        return $user->isAdmin();
    }
}

