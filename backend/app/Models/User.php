<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'disabled_at' => 'datetime',
        ];
    }

    /** 無効化されたユーザーはログインできない（docs/SPEC.md 8章） */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** 公開デモの admin が変更の操作をしたときに返す理由 */
    public const DEMO_READ_ONLY_MESSAGE = '公開デモのため、管理画面は見るだけです（変更はできません）。';

    /** 公開デモで守っているアカウントか（config/demo.php） */
    public function isLockedDemoAccount(): bool
    {
        return config('demo.accounts_locked') && in_array($this->email, config('demo.account_emails'), true);
    }
}
