<?php

namespace App\Services\Collector\Jobs;

use App\Models\Game;
use App\Models\Genre;
use App\Services\Collector\CollectorJob;
use App\Services\Collector\JobResult;
use App\Services\Collector\SteamClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Steam ストアの詳細（名前、画像、価格、ジャンル、発売日、Metacritic、レビュー件数）を取る（1日1回）。
 * 価格とレビュー件数は、その日の daily_stats にも残す（セールの前後の比較に使う）
 */
class FetchSteamDetails implements CollectorJob
{
    public function __construct(private SteamClient $steam) {}

    public function name(): string
    {
        return 'steam_details';
    }

    public function intervalHours(): int
    {
        return 24;
    }

    public function run(): JobResult
    {
        $games = Game::where('tracked', true)->whereNotNull('steam_appid')->orderBy('id')->get();
        $today = now()->timezone('Asia/Tokyo')->toDateString();
        $succeeded = 0;
        $failed = [];

        foreach ($games as $i => $game) {
            if ($i > 0) {
                // ストアの API は回数制限があるため、間を空ける
                usleep((int) config('collector.steam_details_delay_ms') * 1000);
            }
            try {
                $data = $this->steam->appDetails($game->steam_appid);
            } catch (Throwable) {
                $failed[] = $game->steam_appid;

                continue;
            }
            if ($data === null) {
                // 日本のストアにない。次の回も取れないので、追跡をやめる
                $game->update(['tracked' => false, 'details_fetched_at' => now()]);
                $failed[] = $game->steam_appid;

                continue;
            }
            $this->apply($game, $data, $today);
            $succeeded++;
        }

        $message = $failed === [] ? null : '取れなかった appid：'.implode(', ', array_slice($failed, 0, 20));

        return new JobResult($games->count(), $succeeded, count($failed), $message);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apply(Game $game, array $data, string $today): void
    {
        $price = $data['price_overview'] ?? null;
        $release = $data['release_date'] ?? [];
        $isFree = (bool) ($data['is_free'] ?? false);

        DB::transaction(function () use ($game, $data, $price, $release, $isFree, $today) {
            $game->update([
                'name' => $data['name'] ?? $game->name,
                'image_url' => $data['header_image'] ?? $game->image_url,
                'is_free' => $isFree,
                // Steam は銭の単位（9,020円 → 902000）で返す
                'price_jpy' => $price !== null ? intdiv((int) $price['final'], 100) : ($isFree ? 0 : null),
                'discount_percent' => (int) ($price['discount_percent'] ?? 0),
                'metacritic' => $data['metacritic']['score'] ?? $game->metacritic,
                'recommendations' => $data['recommendations']['total'] ?? $game->recommendations,
                'coming_soon' => (bool) ($release['coming_soon'] ?? false),
                'release_date' => self::parseJapaneseDate($release['date'] ?? null) ?? $game->release_date,
                'details_fetched_at' => now(),
            ]);

            $keys = collect($data['genres'] ?? [])
                ->map(fn (array $g) => config('genres.steam')[(int) $g['id']] ?? null)
                ->filter()->values()->all();
            // RAWG から付いたジャンルは残す
            $game->genres()->syncWithoutDetaching(Genre::idsForKeys($keys));

            DB::table('daily_stats')->upsert([[
                'game_id' => $game->id,
                'date' => $today,
                'price_jpy' => $game->price_jpy,
                'discount_percent' => $game->discount_percent,
                'recommendations' => $game->recommendations,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['game_id', 'date'], ['price_jpy', 'discount_percent', 'recommendations', 'updated_at']);
        });
    }

    /** 「2012年8月21日」→ 2012-08-21。「近日登場」のような日付でない値は null */
    public static function parseJapaneseDate(?string $text): ?string
    {
        if ($text === null || ! preg_match('/(\d{4})年\s*(\d{1,2})月\s*(\d{1,2})日/u', $text, $m)) {
            return null;
        }

        return Carbon::createFromDate((int) $m[1], (int) $m[2], (int) $m[3])->toDateString();
    }
}
