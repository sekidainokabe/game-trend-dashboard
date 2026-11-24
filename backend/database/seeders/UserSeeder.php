<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * 権限ごとのデモ用ユーザー。パスワードはすべて "password"（モデルの casts でハッシュ化される）
     */
    public function run(): void
    {
        $users = [
            ['name' => '企画 太郎', 'email' => 'member@example.com', 'role' => UserRole::Member],
            ['name' => '管理 花子', 'email' => 'admin@example.com', 'role' => UserRole::Admin],
        ];

        foreach ($users as $user) {
            User::create([...$user, 'password' => 'password']);
        }
    }
}
