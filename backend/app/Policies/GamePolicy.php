<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * データの取得の管理（取得の状況を見る、ゲームを集計から外す・戻す）は admin だけ
 */
class GamePolicy
{
    /** 取得の状況・ゲームの一覧を見る */
    public function manage(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    /**
     * ゲームを集計から外す・戻す。
     * 公開デモの admin は誰でもログインできるため、見るだけにする（外されると本番の取得が止まる）
     */
    public function update(User $user): Response
    {
        if ($user->role !== UserRole::Admin) {
            return Response::deny('ゲームを管理する権限がありません。');
        }
        if ($user->isLockedDemoAccount()) {
            return Response::deny(User::DEMO_READ_ONLY_MESSAGE);
        }

        return Response::allow();
    }
}
