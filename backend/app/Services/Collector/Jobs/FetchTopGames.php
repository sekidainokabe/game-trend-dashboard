<?php

namespace App\Services\Collector\Jobs;

use App\Models\Game;
use App\Services\Collector\CollectorJob;
use App\Services\Collector\JobResult;
use App\Services\Collector\SteamClient;

/**
 * Steam の人気上位100本を、追跡するゲームに加える（1日1回）。
 * 上位から外れたゲームも追跡はやめない（推移が途切れないように）
 */
class FetchTopGames implements CollectorJob
{
    public function __construct(private SteamClient $steam) {}

    public function name(): string
    {
        return 'top_games';
    }

    public function intervalHours(): int
    {
        return 24;
    }

    public function run(): JobResult
    {
        $appids = $this->steam->mostPlayed();
        $new = 0;
        foreach ($appids as $appid) {
            $game = Game::firstOrCreate(['steam_appid' => $appid], ['tracked' => true]);
            if ($game->wasRecentlyCreated) {
                $new++;
            } elseif (! $game->tracked) {
                $game->update(['tracked' => true]);
            }
        }

        return new JobResult(count($appids), count($appids), message: "新しく追跡するゲーム {$new} 本");
    }
}
