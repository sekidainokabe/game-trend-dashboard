<?php

namespace App\Console\Commands;

use App\Models\Game;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 開発用：過去の人数と価格の仮データを入れる（画面の開発と、データ量が増えたときの速さの確認に使う）。
 * 実際に集めたデータと混ざらないよう、本番では実行できない
 */
#[Signature('app:seed-history {--days=90 : 1日ごとの集計を何日分作るか} {--hours=168 : 毎時の人数を何時間分作るか}')]
#[Description('開発用：過去の人数・価格の仮データを入れる（本番では実行できない）')]
class SeedHistory extends Command
{
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('本番では実行できません。');

            return self::FAILURE;
        }

        $games = Game::where('tracked', true)->whereNotNull('steam_appid')->where('coming_soon', false)->get();
        $latest = DB::table('player_snapshots')->max('captured_at');
        $base = DB::table('player_snapshots')->where('captured_at', $latest)->pluck('players', 'game_id');
        $now = now()->utc()->startOfHour();
        $days = (int) $this->option('days');
        $hours = (int) $this->option('hours');
        mt_srand(42);

        foreach ($games as $game) {
            $current = (int) ($base[$game->id] ?? 1000);
            $snapshots = [];
            $level = $current;
            // 新しい時刻から過去へさかのぼり、少しずつ変わる人数を作る（日本時間の夜に多く、朝に少ない）
            for ($h = 1; $h <= $hours; $h++) {
                $at = $now->copy()->subHours($h);
                $level = max(10, (int) ($level * (1 + (mt_rand(-30, 30) / 1000))));
                $jstHour = ($at->hour + 9) % 24;
                $cycle = 0.75 + 0.25 * sin(($jstHour - 15) / 24 * 2 * M_PI);
                $snapshots[] = ['game_id' => $game->id, 'captured_at' => $at, 'players' => (int) ($level * $cycle)];
            }
            foreach (array_chunk($snapshots, 1000) as $chunk) {
                DB::table('player_snapshots')->insertOrIgnore($chunk);
            }

            $stats = [];
            $level = $current;
            // セールを1回入れる（20〜26日前に50%引き。人数が増える）
            $price = $game->price_jpy;
            for ($d = 1; $d <= $days; $d++) {
                $date = now()->timezone('Asia/Tokyo')->subDays($d)->toDateString();
                $onSale = $price > 0 && $d >= 20 && $d <= 26;
                $level = max(10, (int) ($level * (1 + (mt_rand(-50, 45) / 1000))));
                $peak = (int) ($level * ($onSale ? 1.4 : 1.0));
                $stats[] = [
                    'game_id' => $game->id,
                    'date' => $date,
                    'peak_players' => $peak,
                    'avg_players' => (int) ($peak * 0.7),
                    'samples' => 24,
                    'price_jpy' => $price === null ? null : ($onSale ? intdiv($price, 2) : $price),
                    'discount_percent' => $price === null ? null : ($onSale ? 50 : 0),
                    'recommendations' => $game->recommendations,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            // すでにある日（実際に集めた日）は上書きしない
            DB::table('daily_stats')->insertOrIgnore($stats);
        }

        $this->call('app:collect', ['--only' => ['aggregate_daily'], '--force' => true]);
        $this->info("{$games->count()} 本のゲームに、仮データを入れました。");

        return self::SUCCESS;
    }
}
