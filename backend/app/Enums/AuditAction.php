<?php

namespace App\Enums;

/**
 * 操作履歴（audit_logs.action）に残す操作の種類。画面の絞り込みと表示名にも使う
 */
enum AuditAction: string
{
    case Login = 'login';
    case UserCreated = 'user.create';
    case UserUpdated = 'user.update';
    case PasswordChanged = 'user.password';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'ログイン',
            self::UserCreated => 'ユーザーの追加',
            self::UserUpdated => 'ユーザーの変更',
            self::PasswordChanged => 'パスワードの変更',
        };
    }
}
