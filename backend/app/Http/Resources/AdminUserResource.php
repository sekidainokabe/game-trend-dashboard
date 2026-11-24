<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * ユーザー管理の画面（admin だけ）で返すユーザー情報
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // inspect は「できるか」と「できない理由」を一緒に返す
        $update = Gate::forUser($request->user())->inspect('update', $this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // 行ごとに、変更のボタンを出すか（自分自身とデモ用アカウントは変えられない）。理由も画面に出す
            'can' => [
                'update' => $update->allowed(),
            ],
            'locked_reason' => $update->allowed() ? null : $update->message(),
        ];
    }
}
