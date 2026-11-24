<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // ゲームデータベース（発売予定・ジャンル・評価・対応機種）。無料プランは非商用・月2万回まで
    'rawg' => [
        'key' => env('RAWG_API_KEY'),
        'base_url' => 'https://api.rawg.io/api',
    ],

    // Steam。人数と人気上位は公式の Web API（キー不要）、詳細はストアの API（説明書なし・回数制限あり）
    'steam' => [
        'api_url' => 'https://api.steampowered.com',
        'store_url' => 'https://store.steampowered.com',
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
