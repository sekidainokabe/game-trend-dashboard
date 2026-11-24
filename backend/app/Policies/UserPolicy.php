<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * ユーザー管理の権限（docs/SPEC.md 8章）。ユーザーの一覧・追加・変更は admin だけ
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    /**
     * 役割の変更、無効化・有効化、パスワードの再設定。
     * 自分自身は変えられない。操作する本人は admin のまま残るので、「最後の admin か」を数えなくても admin が0人にならない
     */
    public function update(User $user, User $target): Response
    {
        if ($user->role !== UserRole::Admin) {
            return Response::deny('ユーザーを管理する権限がありません。');
        }
        if ($user->is($target)) {
            return Response::deny('自分自身の役割や状態は、ここでは変えられません。');
        }
        if ($target->isLockedDemoAccount()) {
            return Response::deny('デモ用のアカウントは変更できません。');
        }

        return Response::allow();
    }

    /** 自分のパスワードの変更。公開デモのアカウントは変えられない */
    public function changePassword(User $user, User $target): Response
    {
        if (! $user->is($target)) {
            return Response::deny('ほかの人のパスワードは、ユーザー管理から再設定してください。');
        }
        if ($user->isLockedDemoAccount()) {
            return Response::deny('デモ用のアカウントのパスワードは変更できません。');
        }

        return Response::allow();
    }
}
