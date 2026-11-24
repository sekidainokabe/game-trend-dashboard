<?php

namespace App\Services\Collector\Jobs;

use App\Services\Collector\CollectorJob;
use App\Services\Collector\JobResult;
use Illuminate\Support\Facades\DB;

/**
 * 古い毎時の人数を消す（1日1回）。1日ごとの集計は残るので、長い期間のグラフは描ける
 */
class PruneSnapshots implements CollectorJob
{
    public function name(): string
    {
        return 'prune_snapshots';
    }

    public function intervalHours(): int
    {
        return 24;
    }

    public function run(): JobResult
    {
        $deleted = DB::table('player_snapshots')
            ->where('captured_at', '<', now()->subDays((int) config('collector.snapshot_retention_days')))
            ->delete();

        return new JobResult($deleted, $deleted);
    }
}
