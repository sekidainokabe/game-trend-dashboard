<?php

/**
 * 公開しているデモ環境（本番の Railway）向けの設定。
 *
 * 本番の URL とデモ用アカウントのパスワードは公開しているため、誰でも admin でログインできる。
 * デモ用アカウントを無効化されたりパスワードを変えられたりすると、ほかの人がログインできなくなるので、
 * DEMO_ACCOUNTS_LOCKED=true のときは、この2アカウントの役割・状態・パスワードを変えられないようにする
 */
return [
    'accounts_locked' => (bool) env('DEMO_ACCOUNTS_LOCKED', false),

    // database/seeders/UserSeeder.php で作るアカウント
    'account_emails' => [
        'member@example.com',
        'admin@example.com',
    ],
];
