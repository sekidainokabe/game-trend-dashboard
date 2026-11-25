<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 閲覧用の API の速さを測る（開発用）。キャッシュを消してから呼ぶので、毎回 SQL を実行したときの時間になる。
 * 大量の仮データは app:seed-scale で別の DB に入れ、DB_DATABASE で切り替えて測る（docs/performance.md）
 */
class Bench extends Command
{
    protected $signature = 'app:bench {--runs=5 : 各 API を呼ぶ回数（中央値を出す）}';

    protected $description = '閲覧用の API の応答時間と SQL の回数を測る（開発用）';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('本番では実行できません。');

            return self::FAILURE;
        }

        $game = Game::query()->join('player_snapshots as s', 's.game_id', '=', 'games.id')
            ->orderByDesc('s.captured_at')->orderByDesc('s.players')->value('games.id');
        $admin = User::where('role', 'admin')->first();

        $paths = [
            '/api/dashboard', '/api/ranking', '/api/ranking?sort=change&genre=rpg', '/api/genres', '/api/ratings', '/api/releases',
            "/api/games/{$game}", "/api/games/{$game}/players?range=7d", "/api/games/{$game}/players?range=90d",
            '/api/admin/collection', '/api/admin/games?q=game',
        ];

        $rows = [];
        foreach ($paths as $path) {
            $times = [];
            $queries = 0;
            $status = 0;
            foreach (range(1, (int) $this->option('runs')) as $_) {
                Cache::flush();
                DB::flushQueryLog();
                DB::enableQueryLog();
                if (str_starts_with($path, '/api/admin')) {
                    Auth::guard('web')->setUser($admin);
                }
                $start = hrtime(true);
                $response = app()->handle(Request::create($path, 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
                $times[] = (hrtime(true) - $start) / 1e6;
                $queries = count(DB::getQueryLog());
                $status = $response->getStatusCode();
                DB::disableQueryLog();
            }
            sort($times);
            $rows[] = [$path, $status, number_format($times[intdiv(count($times), 2)], 1), $queries];
        }

        $this->table(['API', 'HTTP', '中央値（ms）', 'SQL の回数'], $rows);
        $this->line(sprintf('games %d 本 / player_snapshots %d 行 / daily_stats %d 行',
            DB::table('games')->count(), DB::table('player_snapshots')->count(), DB::table('daily_stats')->count()));

        return self::SUCCESS;
    }
}
