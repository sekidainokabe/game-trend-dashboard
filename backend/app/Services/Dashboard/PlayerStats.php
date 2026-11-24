<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 閲覧用の集計。データは毎時1回しか変わらないため、「最新の取得時刻」をキャッシュのキーに入れて結果を使い回す。
 * 新しい時刻のデータが入ると、キーが変わって自動的に計算し直される（消す処理が要らない）
 */
class PlayerStats
{
    /** 増減率を出す最低の人数（数十人のゲームが「+300%」で急上昇の上位に並ばないように） */
    private const RISING_MIN_PLAYERS = 1000;

    /** 最新の取得時刻（UTC）。まだ1回も取っていなければ null */
    public function latestCapturedAt(): ?CarbonImmutable
    {
        $value = DB::table('player_snapshots')->max('captured_at');

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    /**
     * キャッシュの名前に最新の取得時刻を入れて、毎時のデータの更新に合わせて作り直す
     *
     * @template T
     *
     * @param  callable(CarbonImmutable|null): T  $compute
     * @return T
     */
    public function remember(string $name, callable $compute): mixed
    {
        $latest = $this->latestCapturedAt();
        $key = "stats:{$name}:".($latest?->timestamp ?? 'none');

        return Cache::remember($key, now()->addHours(2), fn () => $compute($latest));
    }

    /**
     * 最新の時刻の人数と、24時間前の人数（増減率の計算用）。1回の SQL で取る
     */
    public function latestWithChange(CarbonImmutable $latest): Builder
    {
        return DB::table('player_snapshots as s')
            ->join('games as g', 'g.id', '=', 's.game_id')
            ->leftJoin('player_snapshots as prev', function (JoinClause $join) use ($latest) {
                $join->on('prev.game_id', '=', 's.game_id')
                    ->where('prev.captured_at', '=', $latest->subDay());
            })
            ->where('s.captured_at', '=', $latest)
            ->select('g.id', 'g.name', 'g.image_url', 's.players', 'prev.players as players_24h_ago');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function top(CarbonImmutable $latest, int $limit): Collection
    {
        return $this->latestWithChange($latest)->orderByDesc('s.players')->limit($limit)->get()->map($this->withChange(...));
    }

    /**
     * 24時間前より増えた割合の大きい順
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rising(CarbonImmutable $latest, int $limit): Collection
    {
        return $this->latestWithChange($latest)
            ->where('prev.players', '>=', self::RISING_MIN_PLAYERS)
            ->whereColumn('s.players', '>', 'prev.players')
            ->orderByRaw('s.players::float / prev.players DESC')
            ->limit($limit)
            ->get()
            ->map($this->withChange(...));
    }

    /**
     * キャッシュに入れるため、オブジェクトではなく配列にする（キャッシュから戻せるのは配列と値だけ）
     *
     * @return array{id: int, name: string|null, image_url: string|null, players: int, change_pct: float|null}
     */
    private function withChange(object $row): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'image_url' => $row->image_url,
            'players' => $row->players,
            'change_pct' => $row->players_24h_ago > 0
                ? round(($row->players - $row->players_24h_ago) / $row->players_24h_ago * 100, 1)
                : null,
        ];
    }

    /**
     * ゲームごとの、直近の日の最大人数（ランキングの小さなグラフ用）。N 本分を1回の SQL で取る
     *
     * @param  list<int>  $gameIds
     * @return Collection<int, list<array{date: string, peak: int|null}>>
     */
    public function dailyPeaks(array $gameIds, int $days): Collection
    {
        $since = now()->timezone('Asia/Tokyo')->subDays($days - 1)->toDateString();

        return DB::table('daily_stats')
            ->whereIn('game_id', $gameIds)
            ->where('date', '>=', $since)
            ->orderBy('date')
            ->get(['game_id', 'date', 'peak_players'])
            ->groupBy('game_id')
            ->map(fn (Collection $rows) => $rows->map(fn ($r) => ['date' => $r->date, 'peak' => $r->peak_players])->values()->all());
    }
}
