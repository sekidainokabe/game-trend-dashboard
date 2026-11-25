<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * データの取得の管理（取得の状況を見る、ゲームを集計から外す・戻す）は admin だけ
 */
class GamePolicy
{
    public function manage(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
