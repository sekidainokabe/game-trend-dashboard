<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * 操作履歴の権限。誰がいつ何をしたかは、問題の調査をする admin だけが見る
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
