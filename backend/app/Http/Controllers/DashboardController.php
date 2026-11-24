<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\Dashboard\PlayerStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ホーム（ダッシュボード）の数字をまとめて返す。ログインなしで見られる
 */
class DashboardController extends Controller
{
    public function __invoke(PlayerStats $stats): JsonResponse
    {
        $data = $stats->remember('dashboard', function (?CarbonImmutable $latest) use ($stats) {
            $top = $latest ? $stats->top($latest, 10) : collect();
            $upcoming = $this->upcoming();
            $trends = $stats->dailyPeaks($top->pluck('id')->all(), 7);
            $genres = $this->genreNames([...$top->pluck('id'), ...$upcoming->pluck('id')]);

            return [
                'updated_at' => $latest?->toIso8601String(),
                'total_players' => $latest ? (int) DB::table('player_snapshots')->where('captured_at', $latest)->sum('players') : 0,
                'tracked_games' => Game::where('tracked', true)->where('coming_soon', false)->whereNotNull('steam_appid')->count(),
                // キャッシュに入れるため、Collection ではなく配列にする
                'top' => $top->map(fn (array $g) => [
                    ...$g,
                    'genres' => $genres->get($g['id'], []),
                    'trend' => $trends->get($g['id'], []),
                ])->values()->all(),
                'rising' => $latest ? $stats->rising($latest, 5)->all() : [],
                'upcoming' => $upcoming->map(fn (Game $g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'image_url' => $g->image_url,
                    'release_date' => $g->release_date?->toDateString(),
                    'platforms' => $g->platforms ?? [],
                    'genres' => $genres->get($g->id, []),
                ])->values()->all(),
            ];
        });

        return response()->json($data);
    }

    /**
     * 今日から30日以内に発売されるゲーム（発売日の近い順）
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Game>
     */
    private function upcoming(): \Illuminate\Database\Eloquent\Collection
    {
        $today = now()->timezone('Asia/Tokyo')->toDateString();

        return Game::where('release_date', '>=', $today)
            ->where('release_date', '<=', now()->timezone('Asia/Tokyo')->addDays(30)->toDateString())
            ->orderBy('release_date')
            ->limit(12)
            ->get(['id', 'name', 'image_url', 'release_date', 'platforms']);
    }

    /**
     * ゲームごとのジャンル名。対象のゲーム分を1回の SQL で取る（ゲームごとに問い合わせない）
     *
     * @param  array<int, int>  $gameIds
     * @return Collection<int, list<string>>
     */
    private function genreNames(array $gameIds): Collection
    {
        return DB::table('game_genre')
            ->join('genres', 'genres.id', '=', 'game_genre.genre_id')
            ->whereIn('game_genre.game_id', $gameIds)
            ->orderBy('genres.id')
            ->get(['game_genre.game_id', 'genres.name'])
            ->groupBy('game_id')
            ->map(fn ($rows) => $rows->pluck('name')->all());
    }
}
