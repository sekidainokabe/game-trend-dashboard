<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\User;

/**
 * 操作履歴（誰が・いつ・何をしたか）を残す。
 *
 * モデルのイベントで自動的に残すのではなく、各操作から明示的に呼ぶ。
 * 行ごとではなく操作の単位で、意味のある内容を残すため。
 * 呼ぶ側は、操作と同じトランザクションの中で呼ぶ（操作だけが成功して履歴が残らない、を防ぐ）
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $detail  操作ごとの補足（件数、コメントなど）
     */
    public function record(User $user, AuditAction $action, User|Game $target, ?array $detail = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'target_type' => $target instanceof Game ? 'game' : 'user',
            'target_id' => $target->id,
            'detail' => $detail,
        ]);
    }
}
