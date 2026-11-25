<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API で返すユーザー情報。モデルをそのまま返さず、公開する項目をここで決める。
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            // 画面でボタンを出し分けるための権限。判定は Policy に任せ、フロントに権限のルールを重複させない
            'can' => [
                'view_audit_logs' => $this->resource->can('viewAny', AuditLog::class),
                'manage_users' => $this->resource->can('viewAny', User::class),
                'manage_games' => $this->resource->can('manage', Game::class),
                // 公開デモのアカウントは、自分のパスワードも変えられない（config/demo.php）
                'change_password' => $this->resource->can('changePassword', $this->resource),
            ],
        ];
    }
}
