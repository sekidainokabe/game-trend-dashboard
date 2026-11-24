<?php

namespace App\Services\Collector\Jobs;

use App\Models\Game;
use App\Models\Genre;
use App\Services\Collector\CollectorJob;
use App\Services\Collector\JobResult;
use App\Services\Collector\RawgClient;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * RAWG から、今日から90日先までに発売されるゲームを取る（1日1回）。
 *
 * 名寄せ：Steam でも売るゲームは、RAWG のストア一覧にある Steam の URL から appid を取り出し、
 * Steam の人気上位から入った同じゲームの行にまとめる（名前の一致では判断しない。表記ゆれで誤るため）
 */
class FetchUpcoming implements CollectorJob
{
    public function __construct(private RawgClient $rawg) {}

    public function name(): string
    {
        return 'upcoming';
    }

    public function intervalHours(): int
    {
        return 24;
    }

    public function run(): JobResult
    {
        $today = now()->timezone('Asia/Tokyo');
        $from = $today->toDateString();
        $to = $today->copy()->addDays((int) config('collector.upcoming_days'))->toDateString();
        $total = 0;
        $succeeded = 0;

        for ($page = 1; $page <= (int) config('collector.upcoming_pages'); $page++) {
            $body = $this->rawg->releasesBetween($from, $to, $page);
            foreach ($body['results'] as $item) {
                $total++;
                try {
                    $this->apply($item, $from);
                    $succeeded++;
                } catch (Throwable) {
                    // 1本の失敗で全体を止めない
                }
            }
            if (! $body['has_next']) {
                break;
            }
        }

        return new JobResult($total, $succeeded, $total - $succeeded);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function apply(array $item, string $today): void
    {
        $game = Game::where('rawg_id', $item['id'])->first();

        if ($game === null) {
            // 初めて見るゲーム。Steam でも売るなら appid を調べて、既存の行を探す（ストア一覧の取得は初回の1回だけ）
            $storeSlugs = array_column(array_column($item['stores'] ?? [], 'store'), 'slug');
            $steamAppid = in_array('steam', $storeSlugs, true) ? $this->rawg->steamAppid((int) $item['id']) : null;
            $game = ($steamAppid !== null ? Game::where('steam_appid', $steamAppid)->first() : null)
                ?? new Game(['steam_appid' => $steamAppid, 'tracked' => $steamAppid !== null]);
        }

        $released = $item['released'] ?? null;

        DB::transaction(function () use ($game, $item, $released, $today) {
            $game->fill([
                'rawg_id' => $item['id'],
                'rawg_slug' => $item['slug'] ?? null,
                // Steam から取った名前・画像があればそちらを使う（日本語のストアの表記のため）
                'name' => $game->name ?? $item['name'],
                'image_url' => $game->image_url ?? ($item['background_image'] ?? null),
                'release_date' => $released ?? $game->release_date,
                'coming_soon' => $released !== null && $released > $today,
                // 評価の件数が0のときの 0.0 は「評価なし」
                'rawg_rating' => ($item['ratings_count'] ?? 0) > 0 ? $item['rating'] : null,
                'metacritic' => $game->metacritic ?? ($item['metacritic'] ?? null),
                'platforms' => array_values(array_map(fn (array $p) => $p['platform']['name'], $item['platforms'] ?? [])),
            ])->save();

            $keys = collect($item['genres'] ?? [])
                ->map(fn (array $g) => config('genres.rawg')[$g['slug']] ?? null)
                ->filter()->values()->all();
            $game->genres()->syncWithoutDetaching(Genre::idsForKeys($keys));
        });
    }
}
