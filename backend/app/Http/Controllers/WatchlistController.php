<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\Dashboard\PlayerStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ウォッチリスト：ログインした人が、気になるゲームを登録して動きをまとめて見る
 */
class WatchlistController extends Controller
{
    /** 1人が登録できる本数。一覧の表示が重くならないように上限を置く */
    public const LIMIT = 50;

    public function index(Request $request, PlayerStats $stats): JsonResponse
    {
        $games = Game::query()
            ->join('watchlist as w', 'w.game_id', '=', 'games.id')
            ->where('w.user_id', $request->user()->id)
            ->orderByDesc('w.created_at')
            ->get(['games.id', 'games.name', 'games.image_url', 'games.release_date', 'games.coming_soon', 'games.is_free', 'games.price_jpy', 'games.discount_percent']);
        $ids = $games->pluck('id')->all();

        // 今の人数と増減、7日の推移、ジャンル。登録の本数によらず、それぞれ1回の SQL
        $latest = $stats->latestCapturedAt();
        $now = $latest && $ids ? $stats->latestWithChange($latest)->whereIn('s.game_id', $ids)->get()->keyBy('id') : collect();
        $trends = $stats->dailyPeaks($ids, 7);
        $genres = $stats->genreNames($ids);

        return response()->json(['data' => $games->map(fn (Game $g) => [
            'id' => $g->id,
            'name' => $g->name,
            'image_url' => $g->image_url,
            'release_date' => $g->release_date?->toDateString(),
            'coming_soon' => $g->coming_soon,
            'is_free' => $g->is_free,
            'price_jpy' => $g->price_jpy,
            'discount_percent' => $g->discount_percent,
            'players' => $now->get($g->id)?->players,
            'change_pct' => PlayerStats::changePct($now->get($g->id)?->players, $now->get($g->id)?->players_24h_ago),
            'trend' => $trends->get($g->id, []),
            'genres' => $genres->get($g->id, []),
        ])]);
    }

    /** 登録。同じゲームを2回登録しても1件のまま（何度押しても同じ結果になる） */
    public function store(Request $request, Game $game): Response
    {
        $user = $request->user();
        $exists = DB::table('watchlist')->where(['user_id' => $user->id, 'game_id' => $game->id])->exists();
        if (! $exists && DB::table('watchlist')->where('user_id', $user->id)->count() >= self::LIMIT) {
            throw ValidationException::withMessages(['game' => 'ウォッチリストに登録できるのは'.self::LIMIT.'本までです。']);
        }

        DB::table('watchlist')->insertOrIgnore(['user_id' => $user->id, 'game_id' => $game->id, 'created_at' => now()]);

        return response()->noContent();
    }

    public function destroy(Request $request, Game $game): Response
    {
        DB::table('watchlist')->where(['user_id' => $request->user()->id, 'game_id' => $game->id])->delete();

        return response()->noContent();
    }
}
