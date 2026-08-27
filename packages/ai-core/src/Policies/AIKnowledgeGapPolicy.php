<?php

declare(strict_types=1);

namespace Core45\AiCore\Policies;

use Core45\AiCore\Models\AIKnowledgeGap;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for AIKnowledgeGap.
 *
 * Lives in the package namespace (Core45\AiCore\Policies) rather than
 * App\Policies so Laravel's Gate::guessPolicyName() auto-discovery can
 * find it: for a package-namespaced model, Laravel only searches inside
 * that model's own namespace tree, never App\Policies.
 */
class AIKnowledgeGapPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AIKnowledgeGap');
    }

    public function view(AuthUser $authUser, AIKnowledgeGap $knowledgeGap): bool
    {
        return $authUser->can('View:AIKnowledgeGap');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AIKnowledgeGap');
    }

    public function update(AuthUser $authUser, AIKnowledgeGap $knowledgeGap): bool
    {
        return $authUser->can('Update:AIKnowledgeGap');
    }

    public function delete(AuthUser $authUser, AIKnowledgeGap $knowledgeGap): bool
    {
        return $authUser->can('Delete:AIKnowledgeGap');
    }

    public function restore(AuthUser $authUser, AIKnowledgeGap $knowledgeGap): bool
    {
        return $authUser->can('Restore:AIKnowledgeGap');
    }

    public function forceDelete(AuthUser $authUser, AIKnowledgeGap $knowledgeGap): bool
    {
        return $authUser->can('ForceDelete:AIKnowledgeGap');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AIKnowledgeGap');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AIKnowledgeGap');
    }

    public function replicate(AuthUser $authUser, AIKnowledgeGap $knowledgeGap): bool
    {
        return $authUser->can('Replicate:AIKnowledgeGap');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AIKnowledgeGap');
    }
}
