<?php

declare(strict_types=1);

namespace Core45\AiCore\Policies;

use Core45\AiCore\Models\AILearnedKnowledge;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for AILearnedKnowledge (the "AI Knowledge" resource).
 *
 * Lives in the package namespace (Core45\AiCore\Policies) rather than
 * App\Policies so Laravel's Gate::guessPolicyName() auto-discovery can
 * find it: for a package-namespaced model, Laravel only searches inside
 * that model's own namespace tree, never App\Policies.
 *
 * Only two permissions exist for this resource today (`ViewAny:AIKnowledge`
 * and `Manage:AIKnowledge`), so every write/delete/restore ability is
 * gated by the single `Manage:AIKnowledge` permission rather than a full
 * CRUD permission set.
 */
class AILearnedKnowledgePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AIKnowledge');
    }

    public function view(AuthUser $authUser, AILearnedKnowledge $knowledge): bool
    {
        return $authUser->can('Manage:AIKnowledge');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Manage:AIKnowledge');
    }

    public function update(AuthUser $authUser, AILearnedKnowledge $knowledge): bool
    {
        return $authUser->can('Manage:AIKnowledge');
    }

    public function delete(AuthUser $authUser, AILearnedKnowledge $knowledge): bool
    {
        return $authUser->can('Manage:AIKnowledge');
    }

    public function restore(AuthUser $authUser, AILearnedKnowledge $knowledge): bool
    {
        return $authUser->can('Manage:AIKnowledge');
    }

    public function forceDelete(AuthUser $authUser, AILearnedKnowledge $knowledge): bool
    {
        return $authUser->can('Manage:AIKnowledge');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('Manage:AIKnowledge');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('Manage:AIKnowledge');
    }

    public function replicate(AuthUser $authUser, AILearnedKnowledge $knowledge): bool
    {
        return $authUser->can('Manage:AIKnowledge');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Manage:AIKnowledge');
    }
}
