<?php

namespace App\Console\Commands;

use App\Models\FetchRun;
use App\Services\Collector\CollectorJob;
use App\Services\Collector\Jobs\AggregateDaily;
use App\Services\Collector\Jobs\FetchPlayers;
use App\Services\Collector\Jobs\FetchSteamDetails;
use App\Services\Collector\Jobs\FetchTopGames;
use App\Services\Collector\Jobs\FetchUpcoming;
use App\Services\Collector\Jobs\PruneSnapshots;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * データの取得。GitHub Actions から毎時1回実行する。
 * 1日1回の処理は「前回の成功から24時間たったか」で判断するので、毎時このコマンドを動かすだけでよい
 */
#[Signature('app:collect {--only=* : 指定した処理だけを実行する（players など）} {--force : 前回の成功からの時間を見ずに実行する}')]
#[Description('Steam・RAWG からデータを取得し、1日ごとに集計する')]
class Collect extends Command
{
    /** 実行する順番。人気上位 → 詳細 → 発売予定 → 人数 → 集計 → 間引き */
    private const JOBS = [
        FetchTopGames::class,
        FetchSteamDetails::class,
        FetchUpcoming::class,
        FetchPlayers::class,
        AggregateDaily::class,
        PruneSnapshots::class,
    ];

    public function handle(): int
    {
        $only = $this->option('only');
        $anyFailed = false;

        foreach (self::JOBS as $class) {
            /** @var CollectorJob $job */
            $job = app($class);
            if ($only !== [] && ! in_array($job->name(), $only, true)) {
                continue;
            }
            if (! $this->option('force') && ! $this->isDue($job)) {
                $this->line("{$job->name()}：前回の成功から時間がたっていないため、とばします");

                continue;
            }

            $run = FetchRun::create(['job' => $job->name(), 'status' => 'running', 'started_at' => now()]);
            try {
                $result = $job->run();
                $run->update([
                    'status' => $result->status(),
                    'total' => $result->total,
                    'succeeded' => $result->succeeded,
                    'failed' => $result->failed,
                    'message' => $this->scrub($result->message),
                    'finished_at' => now(),
                ]);
            } catch (Throwable $e) {
                // 1つの処理が失敗しても、ほかの処理は続ける（Steam が落ちていても RAWG は取る、など）
                $run->update(['status' => 'failed', 'message' => $this->scrub($e->getMessage()), 'finished_at' => now()]);
            }

            $anyFailed = $anyFailed || $run->status === 'failed';
            $this->line("{$job->name()}：{$run->status}（{$run->succeeded}/{$run->total}）".($run->message ? " {$run->message}" : ''));
        }

        // 失敗があれば GitHub Actions の実行結果を赤くして気づけるようにする
        return $anyFailed ? self::FAILURE : self::SUCCESS;
    }

    private function isDue(CollectorJob $job): bool
    {
        if ($job->intervalHours() === 0) {
            return true;
        }

        // 1時間の余裕を持たせる（毎時の実行が数分ずれても、24時間ごとに動くように）
        return ! FetchRun::where('job', $job->name())
            ->whereIn('status', ['success', 'partial'])
            ->where('started_at', '>', now()->subHours($job->intervalHours() - 1))
            ->exists();
    }

    /** エラーの文に API キーが含まれる（URL に付けて送るため）ことがあるので、記録する前に伏せる */
    private function scrub(?string $message): ?string
    {
        $key = (string) config('services.rawg.key');

        return ($message === null || $key === '') ? $message : str_replace($key, '***', $message);
    }
}
