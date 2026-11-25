<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\Dashboard\PlayerStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ゲームの詳細と、人数の推移。ログインなしで見られる
 */
class GameController extends Controller
{
    /** 期間ごとの、使う表と点の細かさ。短い期間は毎時の生データ、長い期間は1日ごとの集計を読む */
    private const RANGES = [
        '24h' => ['hours' => 24],
        '7d' => ['hours' => 24 * 7],
        '30d' => ['days' => 30],
        '90d' => ['days' => 90],
    ];

    public function show(Game $game, PlayerStats $stats): JsonResponse
    {
        $game->load('genres:id,name');

        $now = $stats->remember("game:{$game->id}", function (?CarbonImmutable $latest) use ($stats, $game) {
            $row = $latest ? $stats->latestWithChange($latest)->where('s.game_id', $game->id)->first() : null;

            return [
                'players' => $row?->players,
                'change_pct' => PlayerStats::changePct($row?->players, $row?->players_24h_ago),
                'peak_30d' => DB::table('daily_stats')->where('game_id', $game->id)
                    ->where('date', '>=', now()->timezone('Asia/Tokyo')->subDays(29)->toDateString())
                    ->max('peak_players'),
            ];
        });

        return response()->json(['data' => [
            'id' => $game->id,
            'name' => $game->name,
            'image_url' => $game->image_url,
            'store_url' => $game->steam_appid ? "https://store.steampowered.com/app/{$game->steam_appid}/" : null,
            'rawg_url' => $game->rawg_slug ? "https://rawg.io/games/{$game->rawg_slug}" : null,
            'release_date' => $game->release_date?->toDateString(),
            'coming_soon' => $game->coming_soon,
            'is_free' => $game->is_free,
            'price_jpy' => $game->price_jpy,
            'discount_percent' => $game->discount_percent,
            'metacritic' => $game->metacritic,
            'rawg_rating' => $game->rawg_rating,
            'recommendations' => $game->recommendations,
            'platforms' => $game->platforms ?? [],
            'genres' => $game->genres->pluck('name'),
            ...$now,
        ]]);
    }

    public function players(Request $request, Game $game, PlayerStats $stats): JsonResponse
    {
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(self::RANGES))]])['range'] ?? '7d';
        $spec = self::RANGES[$range];

        $points = $stats->remember("players:{$game->id}:{$range}", function () use ($game, $spec) {
            if (isset($spec['hours'])) {
                return DB::table('player_snapshots')
                    ->where('game_id', $game->id)
                    ->where('captured_at', '>', now()->subHours($spec['hours']))
                    ->orderBy('captured_at')
                    ->get(['captured_at', 'players'])
                    ->map(fn ($r) => ['t' => CarbonImmutable::parse($r->captured_at, 'UTC')->toIso8601String(), 'players' => $r->players])
                    ->all();
            }

            // セールの期間をグラフに重ねるため、価格と割引率も返す
            return DB::table('daily_stats')
                ->where('game_id', $game->id)
                ->where('date', '>=', now()->timezone('Asia/Tokyo')->subDays($spec['days'] - 1)->toDateString())
                ->orderBy('date')
                ->get(['date', 'peak_players', 'avg_players', 'price_jpy', 'discount_percent'])
                ->map(fn ($r) => [
                    't' => $r->date,
                    'players' => $r->peak_players,
                    'avg' => $r->avg_players,
                    'price_jpy' => $r->price_jpy,
                    'discount_percent' => $r->discount_percent,
                ])
                ->all();
        });

        return response()->json([
            'range' => $range,
            'granularity' => isset($spec['hours']) ? 'hour' : 'day',
            'points' => $points,
        ]);
    }
}
