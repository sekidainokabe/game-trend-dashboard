<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 速さの確認用に、大量の仮データを入れる（開発用）。
 * 本番は約100本 × 90日分（約22万行）なので、その何倍もの量で SQL の書き方と索引を確かめる。
 * 行は PostgreSQL の generate_series で DB の中で作る（PHP で1行ずつ作るより速い）
 */
class SeedScale extends Command
{
    protected $signature = 'app:seed-scale {--games=2000} {--hours=720 : 毎時の人数を何時間分入れるか} {--days=90}';

    protected $description = '速さの確認用に、大量の仮データを入れる（開発用・中身を消して入れ直す）';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('本番では実行できません。');

            return self::FAILURE;
        }
        if (! str_contains(DB::connection()->getDatabaseName(), 'scale')) {
            $this->error('名前に「scale」を含む DB でだけ実行できます（開発用のデータを消さないため）。DB_DATABASE=laravel_scale を付けてください。');

            return self::FAILURE;
        }

        $games = (int) $this->option('games');
        $hours = (int) $this->option('hours');
        $days = (int) $this->option('days');

        DB::statement('TRUNCATE player_snapshots, daily_stats, game_genre, watchlist, fetch_runs, games RESTART IDENTITY CASCADE');
        if (! DB::table('users')->exists()) {
            $this->call('db:seed', ['--force' => true]);
        }
        $genreIds = DB::table('genres')->pluck('id')->all();
        if ($genreIds === []) {
            foreach (config('genres.names') as $key => $name) {
                DB::table('genres')->insert(['key' => $key, 'name' => $name]);
            }
            $genreIds = DB::table('genres')->pluck('id')->all();
        }

        $this->info("ゲーム {$games} 本（うち2割は発売予定）");
        DB::statement(<<<'SQL'
            INSERT INTO games (steam_appid, name, image_url, release_date, coming_soon, is_free, price_jpy, discount_percent,
                metacritic, recommendations, platforms, tracked, created_at, updated_at)
            SELECT i, 'Game ' || i, NULL,
                CASE WHEN i % 5 = 0 THEN CURRENT_DATE + (i % 180) ELSE CURRENT_DATE - (i % 3000) END,
                i % 5 = 0, i % 4 = 0, CASE WHEN i % 4 = 0 THEN 0 ELSE 500 + (i * 37) % 9000 END,
                CASE WHEN i % 7 = 0 THEN 50 ELSE 0 END,
                CASE WHEN i % 3 = 0 THEN 55 + (i * 13) % 45 END, (i * 101) % 500000, '["PC"]', true, now(), now()
            FROM generate_series(1, ?) AS i
            SQL, [$games]);

        // ジャンルは1本に1〜3個
        DB::statement(sprintf(<<<'SQL'
            INSERT INTO game_genre (game_id, genre_id)
            SELECT DISTINCT g.id, (array[%s])[1 + ((g.id * 31 + k * 17) %% %d)]
            FROM games g, generate_series(1, 1 + g.id %% 3) AS k
            SQL, implode(',', array_map('intval', $genreIds)), count($genreIds)));

        $this->info("毎時の人数 {$hours} 時間分");
        DB::statement(<<<'SQL'
            INSERT INTO player_snapshots (game_id, captured_at, players)
            SELECT g.id, date_trunc('hour', now() AT TIME ZONE 'UTC') - make_interval(hours => h),
                GREATEST(0, (100000 / g.id + 50) * (100 + 30 * sin(h / 3.8)) / 100)::int
            FROM games g, generate_series(0, ? - 1) AS h
            WHERE NOT g.coming_soon
            SQL, [$hours]);

        $this->info("1日ごとの集計 {$days} 日分");
        DB::statement(<<<'SQL'
            INSERT INTO daily_stats (game_id, date, peak_players, avg_players, samples, price_jpy, discount_percent, created_at, updated_at)
            SELECT g.id, CURRENT_DATE - d, (100000 / g.id + 50) * 1.3, 100000 / g.id + 50, 24, g.price_jpy,
                CASE WHEN d BETWEEN 20 AND 26 THEN 50 ELSE 0 END, now(), now()
            FROM games g, generate_series(0, ? - 1) AS d
            WHERE NOT g.coming_soon
            SQL, [$days]);

        DB::statement('INSERT INTO fetch_runs (job, status, total, succeeded, failed, started_at, finished_at)
            SELECT (array[\'players\',\'aggregate_daily\'])[1 + h % 2], \'success\', 100, 100, 0, now() - make_interval(hours => h), now() - make_interval(hours => h) + interval \'30 seconds\'
            FROM generate_series(0, 2000) AS h');
        DB::statement('ANALYZE');

        $this->info('完了');

        return self::SUCCESS;
    }
}
