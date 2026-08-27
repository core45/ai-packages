<?php

declare(strict_types=1);

namespace Core45\AiCore\Policies;

use Core45\AiCore\Models\AIPromptOverride;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for AIPromptOverride.
 *
 * Lives in the package namespace (Core45\AiCore\Policies) rather than
 * App\Policies so Laravel's Gate::guessPolicyName() auto-discovery can
 * find it: for a package-namespaced model, Laravel only searches inside
 * that model's own namespace tree, never App\Policies.
 */
class AIPromptOverridePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AIPromptOverride');
    }

    public function view(AuthUser $authUser, AIPromptOverride $promptOverride): bool
    {
        return $authUser->can('View:AIPromptOverride');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AIPromptOverride');
    }

    public function update(AuthUser $authUser, AIPromptOverride $promptOverride): bool
    {
        return $authUser->can('Update:AIPromptOverride');
    }

    public function delete(AuthUser $authUser, AIPromptOverride $promptOverride): bool
    {
        return $authUser->can('Delete:AIPromptOverride');
    }

    public function restore(AuthUser $authUser, AIPromptOverride $promptOverride): bool
    {
        return $authUser->can('Restore:AIPromptOverride');
    }

    public function forceDelete(AuthUser $authUser, AIPromptOverride $promptOverride): bool
    {
        return $authUser->can('ForceDelete:AIPromptOverride');
    }
}
