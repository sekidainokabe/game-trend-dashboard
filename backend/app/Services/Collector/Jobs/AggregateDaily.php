<?php

namespace App\Services\Collector\Jobs;

use App\Services\Collector\CollectorJob;
use App\Services\Collector\JobResult;
use Illuminate\Support\Facades\DB;

/**
 * 毎時の人数を、日本時間の1日ごとに集計して daily_stats に書く（毎時）。
 * 昨日と今日の分だけを集計し直す（遅れて届いた回も反映するため昨日も含める）。
 * 集計は DB の中で1回の SQL で行い、PHP にデータを持ってこない
 */
class AggregateDaily implements CollectorJob
{
    private const SQL = <<<'SQL'
        INSERT INTO daily_stats (game_id, date, peak_players, avg_players, samples, created_at, updated_at)
        SELECT game_id,
               (captured_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Tokyo')::date AS day,
               MAX(players),
               ROUND(AVG(players)),
               COUNT(*),
               NOW(), NOW()
        FROM player_snapshots
        WHERE captured_at >= ?
        GROUP BY game_id, day
        ON CONFLICT (game_id, date) DO UPDATE
           SET peak_players = EXCLUDED.peak_players,
               avg_players = EXCLUDED.avg_players,
               samples = EXCLUDED.samples,
               updated_at = EXCLUDED.updated_at
        SQL;

    public function name(): string
    {
        return 'aggregate_daily';
    }

    public function intervalHours(): int
    {
        return 0;
    }

    public function run(): JobResult
    {
        // 日本時間の昨日 0 時を UTC にしたもの。DB の時刻は UTC で入っている
        $since = now()->timezone('Asia/Tokyo')->subDay()->startOfDay()->utc();

        $affected = DB::affectingStatement(self::SQL, [$since]);

        return new JobResult($affected, $affected);
    }
}
