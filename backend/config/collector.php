<?php

/**
 * データ取得（app:collect）の設定
 */
return [
    // 毎時の生データを残す日数。これより古い行は、1日ごとの集計（daily_stats）を残して消す（無料枠 500MB に収めるため）
    'snapshot_retention_days' => 90,

    // 人数を同時に問い合わせる数（Steam に一度に大量のリクエストを送らないため）
    'players_concurrency' => 20,

    // Steam ストアの詳細 API の呼び出し間隔（ミリ秒）。5分に200回程度という制限があるため、間を空ける
    'steam_details_delay_ms' => (int) env('STEAM_DETAILS_DELAY_MS', 1500),

    // RAWG の発売予定を何日先まで、何ページ（1ページ40件）取るか。月2万回の枠に収める
    'upcoming_days' => 90,
    'upcoming_pages' => 3,

    'http_timeout' => 10,
];
