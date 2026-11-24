<?php

namespace App\Services\Collector\Jobs;

use App\Models\Game;
use App\Services\Collector\CollectorJob;
use App\Services\Collector\JobResult;
use App\Services\Collector\SteamClient;
use Illuminate\Support\Facades\DB;

/**
 * 追跡しているゲームの「今遊んでいる人数」を取る（毎時）。
 * 時刻は1時間単位に切りそろえ、(ゲーム, 時刻) で upsert する。同じ時間に2回実行しても行は増えない
 */
class FetchPlayers implements CollectorJob
{
    public function __construct(private SteamClient $steam) {}

    public function name(): string
    {
        return 'players';
    }

    public function intervalHours(): int
    {
        return 0;
    }

    public function run(): JobResult
    {
        // 発売前のゲームは人数がないので問い合わせない
        $games = Game::where('tracked', true)->whereNotNull('steam_appid')->where('coming_soon', false)
            ->pluck('id', 'steam_appid');
        $capturedAt = now()->utc()->startOfHour();

        $players = $this->steam->currentPlayers($games->keys()->all());
        $rows = [];
        foreach ($players as $appid => $count) {
            if ($count !== null) {
                $rows[] = ['game_id' => $games[$appid], 'captured_at' => $capturedAt, 'players' => $count];
            }
        }
        // 1回の SQL でまとめて書く（INSERT ... ON CONFLICT DO UPDATE）
        DB::table('player_snapshots')->upsert($rows, ['game_id', 'captured_at'], ['players']);

        return new JobResult($games->count(), count($rows), $games->count() - count($rows));
    }
}
