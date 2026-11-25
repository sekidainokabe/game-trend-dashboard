<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\PlayerStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ジャンルの比較：ジャンルごとの人数・本数・評価・価格と、30日間の推移。ログインなしで見られる
 */
class GenreController extends Controller
{
    /** 遊び方の種類ではなく、売り方や開発の段階を表すもの。ジャンルの比較からは外す */
    public const NOT_GENRES = ['free-to-play', 'early-access'];

    private const TREND_DAYS = 30;

    public function __invoke(PlayerStats $stats): JsonResponse
    {
        $data = $stats->remember('genres', function (?CarbonImmutable $latest) {
            $today = now()->timezone('Asia/Tokyo')->toDateString();

            // 発売予定の本数（まだ人数のないゲームも数える）
            $upcoming = DB::table('game_genre as gg')
                ->join('games as g', 'g.id', '=', 'gg.game_id')
                ->where('g.release_date', '>=', $today)
                ->whereNull('g.excluded_at')
                ->groupBy('gg.genre_id')
                ->selectRaw('gg.genre_id, count(*) as games')
                ->pluck('games', 'genre_id');

            $rows = $latest === null ? collect() : $this->currentByGenre($latest);

            return [
                'updated_at' => $latest?->toIso8601String(),
                'genres' => $rows->map(fn (object $r) => [
                    'key' => $r->key,
                    'name' => $r->name,
                    'games' => (int) $r->games,
                    'total_players' => (int) $r->total_players,
                    'avg_players' => (int) round($r->avg_players),
                    // 24時間前の値があるゲームだけで比べる（新しく加わったゲームで増えたように見えないように）
                    'change_pct' => PlayerStats::changePct($r->players_with_prev === null ? null : (int) $r->players_with_prev, $r->players_24h_ago === null ? null : (int) $r->players_24h_ago),
                    'avg_metacritic' => $r->avg_metacritic === null ? null : round((float) $r->avg_metacritic, 1),
                    'rated_games' => (int) $r->rated_games,
                    'avg_price_jpy' => $r->avg_price === null ? null : (int) round($r->avg_price),
                    'free_ratio' => round((float) $r->free_ratio, 3),
                    'upcoming_games' => (int) ($upcoming[$r->genre_id] ?? 0),
                ])->values()->all(),
                'trend' => $this->trend(),
            ];
        });

        return response()->json($data);
    }

    /**
     * 最新の時刻の人数を、ジャンルごとにまとめる。1回の SQL で全ジャンル分を出す
     *
     * @return Collection<int, object>
     */
    private function currentByGenre(CarbonImmutable $latest): Collection
    {
        return DB::table('player_snapshots as s')
            ->join('games as g', 'g.id', '=', 's.game_id')
            ->join('game_genre as gg', 'gg.game_id', '=', 's.game_id')
            ->join('genres as ge', 'ge.id', '=', 'gg.genre_id')
            ->leftJoin('player_snapshots as prev', function ($join) use ($latest) {
                $join->on('prev.game_id', '=', 's.game_id')->where('prev.captured_at', '=', $latest->subDay());
            })
            ->where('s.captured_at', $latest)
            ->whereNull('g.excluded_at')
            ->whereNotIn('ge.key', self::NOT_GENRES)
            ->groupBy('ge.id', 'ge.key', 'ge.name')
            ->orderByDesc('total_players')
            ->select([
                'ge.id as genre_id', 'ge.key', 'ge.name',
                DB::raw('count(*) as games'),
                DB::raw('sum(s.players) as total_players'),
                DB::raw('avg(s.players) as avg_players'),
                DB::raw('sum(s.players) filter (where prev.players is not null) as players_with_prev'),
                DB::raw('sum(prev.players) as players_24h_ago'),
                DB::raw('avg(g.metacritic) as avg_metacritic'),
                DB::raw('count(g.metacritic) as rated_games'),
                DB::raw('avg(g.price_jpy) filter (where not g.is_free and g.price_jpy > 0) as avg_price'),
                DB::raw('avg(case when g.is_free then 1 else 0 end) as free_ratio'),
            ])
            ->get();
    }

    /**
     * ジャンルごとの、日ごとの人数（各ゲームのその日の最大人数を足したもの）
     *
     * @return list<array{date: string, values: array<string, int>}>
     */
    private function trend(): array
    {
        $since = now()->timezone('Asia/Tokyo')->subDays(self::TREND_DAYS - 1)->toDateString();

        return DB::table('daily_stats as d')
            ->join('game_genre as gg', 'gg.game_id', '=', 'd.game_id')
            ->join('genres as ge', 'ge.id', '=', 'gg.genre_id')
            ->join('games as g', 'g.id', '=', 'd.game_id')
            ->whereNull('g.excluded_at')
            ->where('d.date', '>=', $since)
            ->whereNotNull('d.peak_players')
            ->whereNotIn('ge.key', self::NOT_GENRES)
            ->groupBy('d.date', 'ge.key')
            ->orderBy('d.date')
            ->get(['d.date', 'ge.key', DB::raw('sum(d.peak_players) as players')])
            ->groupBy('date')
            ->map(fn ($rows, $date) => [
                'date' => $date,
                'values' => $rows->mapWithKeys(fn ($r) => [$r->key => (int) $r->players])->all(),
            ])
            ->values()
            ->all();
    }
}
