<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\PlayerStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * 評価と人気の散布図：今の人数と、評価（Metacritic）・レビュー数を並べる。ログインなしで見られる
 */
class RatingController extends Controller
{
    public function __invoke(PlayerStats $stats): JsonResponse
    {
        $data = $stats->remember('ratings', function (?CarbonImmutable $latest) {
            if ($latest === null) {
                return ['games' => []];
            }

            // 色分けに使う「主なジャンル」：売り方（基本無料など）を除いた、最初のジャンル
            $mainGenre = DB::table('game_genre as gg')
                ->join('genres as ge', 'ge.id', '=', 'gg.genre_id')
                ->whereColumn('gg.game_id', 'g.id')
                ->whereNotIn('ge.key', GenreController::NOT_GENRES)
                ->orderBy('ge.id')
                ->limit(1);

            return [
                'games' => DB::table('player_snapshots as s')
                    ->join('games as g', 'g.id', '=', 's.game_id')
                    ->where('s.captured_at', $latest)
                    ->whereNull('g.excluded_at')
                    ->where('s.players', '>', 0)
                    ->select('g.id', 'g.name', 'g.image_url', 's.players', 'g.metacritic', 'g.recommendations', 'g.is_free', 'g.price_jpy')
                    ->selectSub((clone $mainGenre)->select('ge.key'), 'genre_key')
                    ->selectSub((clone $mainGenre)->select('ge.name'), 'genre')
                    ->orderByDesc('s.players')
                    ->get()
                    ->map(fn (object $r) => [...(array) $r, 'is_free' => (bool) $r->is_free])
                    ->all(),
            ];
        });

        return response()->json($data);
    }
}
