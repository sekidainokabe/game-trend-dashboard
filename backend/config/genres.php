<?php

/**
 * ジャンルの共通のキーと表示名。Steam と RAWG で呼び方が違うため、ここで対応させる。
 * 対応のないジャンル（Steam の「アニメ」など）は取り込まない
 */
return [
    'names' => [
        'action' => 'アクション',
        'adventure' => 'アドベンチャー',
        'rpg' => 'RPG',
        'strategy' => 'ストラテジー',
        'simulation' => 'シミュレーション',
        'casual' => 'カジュアル',
        'indie' => 'インディー',
        'sports' => 'スポーツ',
        'racing' => 'レース',
        'mmo' => 'MMO',
        'shooter' => 'シューティング',
        'puzzle' => 'パズル',
        'platformer' => 'プラットフォーマー',
        'fighting' => '格闘',
        'family' => 'ファミリー',
        'arcade' => 'アーケード',
        'board-games' => 'ボードゲーム',
        'card' => 'カード',
        'educational' => '教育',
        'free-to-play' => '基本無料',
        'early-access' => '早期アクセス',
    ],

    // Steam の genres[].id → キー
    'steam' => [
        1 => 'action',
        2 => 'strategy',
        3 => 'rpg',
        4 => 'casual',
        9 => 'racing',
        18 => 'sports',
        23 => 'indie',
        25 => 'adventure',
        28 => 'simulation',
        29 => 'mmo',
        37 => 'free-to-play',
        70 => 'early-access',
    ],

    // RAWG の genres[].slug → キー
    'rawg' => [
        'action' => 'action',
        'adventure' => 'adventure',
        'role-playing-games-rpg' => 'rpg',
        'strategy' => 'strategy',
        'simulation' => 'simulation',
        'casual' => 'casual',
        'indie' => 'indie',
        'sports' => 'sports',
        'racing' => 'racing',
        'massively-multiplayer' => 'mmo',
        'shooter' => 'shooter',
        'puzzle' => 'puzzle',
        'platformer' => 'platformer',
        'fighting' => 'fighting',
        'family' => 'family',
        'arcade' => 'arcade',
        'board-games' => 'board-games',
        'card' => 'card',
        'educational' => 'educational',
    ],
];
