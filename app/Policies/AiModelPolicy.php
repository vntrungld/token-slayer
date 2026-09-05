<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiModel;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Gates the admin `Models` page. There is no create/delete surface — rows
 * only ever come from the Sync action — so only viewing and updating (the
 * inline badge toggle, the duration input, and Sync itself) are gated.
 */
class AiModelPolicy
{
    use HandlesAuthorization;

    /**
     * @param  AuthUser  $authUser
     * @return bool
     */
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AiModel');
    }

    /**
     * @param  AuthUser  $authUser
     * @param  AiModel  $aiModel
     * @return bool
     */
    public function update(AuthUser $authUser, AiModel $aiModel): bool
    {
        return $authUser->can('Update:AiModel');
    }
}
