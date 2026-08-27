<?php

declare(strict_types=1);

namespace Core45\AiCore\Policies;

use Core45\AiCore\Models\AIKnowledgeConflict;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for AIKnowledgeConflict.
 *
 * Lives in the package namespace (Core45\AiCore\Policies) rather than
 * App\Policies so Laravel's Gate::guessPolicyName() auto-discovery can
 * find it: for a package-namespaced model, Laravel only searches inside
 * that model's own namespace tree, never App\Policies.
 */
class AIKnowledgeConflictPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AIKnowledgeConflict');
    }

    public function view(AuthUser $authUser, AIKnowledgeConflict $conflict): bool
    {
        return $authUser->can('View:AIKnowledgeConflict');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AIKnowledgeConflict');
    }

    public function update(AuthUser $authUser, AIKnowledgeConflict $conflict): bool
    {
        return $authUser->can('Update:AIKnowledgeConflict');
    }

    public function delete(AuthUser $authUser, AIKnowledgeConflict $conflict): bool
    {
        return $authUser->can('Delete:AIKnowledgeConflict');
    }

    public function restore(AuthUser $authUser, AIKnowledgeConflict $conflict): bool
    {
        return $authUser->can('Restore:AIKnowledgeConflict');
    }

    public function forceDelete(AuthUser $authUser, AIKnowledgeConflict $conflict): bool
    {
        return $authUser->can('ForceDelete:AIKnowledgeConflict');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AIKnowledgeConflict');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AIKnowledgeConflict');
    }

    public function replicate(AuthUser $authUser, AIKnowledgeConflict $conflict): bool
    {
        return $authUser->can('Replicate:AIKnowledgeConflict');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AIKnowledgeConflict');
    }
}
