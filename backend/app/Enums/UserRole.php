<?php

namespace App\Enums;

/**
 * member：ウォッチリストなど自分の設定だけを使う。admin：ユーザー管理、追跡するゲーム、取得の実行結果、操作履歴
 */
enum UserRole: string
{
    case Member = 'member';
    case Admin = 'admin';
}
