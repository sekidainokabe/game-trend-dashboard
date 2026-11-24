<?php

namespace Tests\Feature;

use App\Models\FetchRun;
use App\Models\Game;
use App\Services\Collector\Jobs\FetchSteamDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * データの取得（app:collect）。外部 API はすべて偽物に差し替え、本物には送らない
 */
class CollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['collector.steam_details_delay_ms' => 0, 'services.rawg.key' => 'test-key']);
        $this->travelTo('2026-11-24 03:30:00');
    }

    private function collect(string ...$only): int
    {
        return $this->artisan('app:collect', ['--only' => $only, '--force' => true])->run();
    }

    public function test_人気上位を追跡するゲームに加え、2回目は重複しない(): void
    {
        Http::fake(['*GetMostPlayedGames*' => Http::response(['response' => ['ranks' => [['appid' => 730], ['appid' => 570]]]])]);

        $this->collect('top_games');
        $this->collect('top_games');

        $this->assertSame([570, 730], Game::orderBy('steam_appid')->pluck('steam_appid')->all());
        $this->assertSame('success', FetchRun::latest('id')->first()->status);
    }

    public function test_人数は1時間単位で残し、同じ時間に2回取っても行は増えない。取れないゲームがあっても止めない(): void
    {
        $a = Game::create(['steam_appid' => 1, 'name' => 'A']);
        $b = Game::create(['steam_appid' => 2, 'name' => 'B']);
        Game::create(['steam_appid' => 3, 'name' => '発売前', 'coming_soon' => true]);
        $players = 100;
        Http::fake(function (Request $request) use (&$players) {
            return match ($request->data()['appid'] ?? null) {
                1 => Http::response(['response' => ['player_count' => $players, 'result' => 1]]),
                default => Http::response(['response' => ['result' => 42]], 404),
            };
        });

        $this->collect('players');
        $players = 120;
        $this->travelTo('2026-11-24 03:55:00');
        $this->collect('players');

        $rows = DB::table('player_snapshots')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($a->id, $rows[0]->game_id);
        $this->assertSame('2026-11-24 03:00:00', $rows[0]->captured_at);
        $this->assertSame(120, $rows[0]->players);
        // 発売前のゲームには問い合わせない
        Http::assertSentCount(4);

        $run = FetchRun::latest('id')->first();
        $this->assertSame(['partial', 2, 1, 1], [$run->status, $run->total, $run->succeeded, $run->failed]);
        $this->assertNotNull($b);
    }

    public function test_1日ごとの集計は日本時間の日付で、最大と平均と回数を出す(): void
    {
        $game = Game::create(['steam_appid' => 1, 'name' => 'A']);
        // UTC 11/23 14:00 は日本時間 11/23 23:00、UTC 11/23 15:00 は日本時間 11/24 0:00
        DB::table('player_snapshots')->insert([
            ['game_id' => $game->id, 'captured_at' => '2026-11-23 14:00:00', 'players' => 50],
            ['game_id' => $game->id, 'captured_at' => '2026-11-23 15:00:00', 'players' => 100],
            ['game_id' => $game->id, 'captured_at' => '2026-11-23 16:00:00', 'players' => 201],
        ]);

        $this->collect('aggregate_daily');
        // 2回目も同じ結果（上書き）
        $this->collect('aggregate_daily');

        $stats = DB::table('daily_stats')->orderBy('date')->get(['date', 'peak_players', 'avg_players', 'samples']);
        $this->assertEquals([
            (object) ['date' => '2026-11-23', 'peak_players' => 50, 'avg_players' => 50, 'samples' => 1],
            (object) ['date' => '2026-11-24', 'peak_players' => 201, 'avg_players' => 151, 'samples' => 2],
        ], $stats->all());
    }

    public function test_steam_の詳細から名前・価格・ジャンルを入れ、価格はその日の集計にも残す(): void
    {
        $game = Game::create(['steam_appid' => 10]);
        $gone = Game::create(['steam_appid' => 11]);
        Http::fake(['*appdetails*appids=10*' => Http::response(['10' => ['success' => true, 'data' => [
            'name' => 'テストゲーム',
            'header_image' => 'https://example.com/header.jpg',
            'is_free' => false,
            'price_overview' => ['final' => 451000, 'discount_percent' => 50],
            'genres' => [['id' => '1', 'description' => 'アクション'], ['id' => '999', 'description' => 'アニメ']],
            'release_date' => ['coming_soon' => false, 'date' => '2022年2月25日'],
            'metacritic' => ['score' => 94],
            'recommendations' => ['total' => 837625],
        ]]]),
            '*appdetails*appids=11*' => Http::response(['11' => ['success' => false]]),
        ]);

        $this->collect('steam_details');

        $game->refresh();
        $this->assertSame(['テストゲーム', 4510, 50, 94, 837625, '2022-02-25'], [
            $game->name, $game->price_jpy, $game->discount_percent, $game->metacritic, $game->recommendations, $game->release_date->toDateString(),
        ]);
        // 対応のないジャンル（アニメ）は取り込まない
        $this->assertSame(['アクション'], $game->genres->pluck('name')->all());
        $this->assertSame(4510, DB::table('daily_stats')->where('game_id', $game->id)->where('date', '2026-11-24')->value('price_jpy'));
        // 日本のストアにないゲームは追跡をやめる
        $this->assertFalse($gone->fresh()->tracked);
    }

    public function test_日付でない発売日は読まない(): void
    {
        $this->assertNull(FetchSteamDetails::parseJapaneseDate('近日登場'));
        $this->assertSame('2026-01-05', FetchSteamDetails::parseJapaneseDate('2026年1月5日'));
    }

    public function test_発売予定は_steam_の_appid_で既存のゲームにまとめ、ストア一覧は初回だけ問い合わせる(): void
    {
        $steamGame = Game::create(['steam_appid' => 2694490, 'name' => 'Path of Exile 2（Steam の名前）']);
        Http::fake([
            '*/games/391403/stores*' => Http::response(['results' => [['url' => 'https://store.steampowered.com/app/2694490/']]]),
            'api.rawg.io/api/games?*' => Http::response(['next' => null, 'results' => [
                [
                    'id' => 391403, 'slug' => 'path-of-exile-2', 'name' => 'Path of Exile 2', 'released' => '2026-12-11',
                    'rating' => 3.55, 'ratings_count' => 40, 'metacritic' => null, 'background_image' => 'https://example.com/poe2.jpg',
                    'genres' => [['slug' => 'role-playing-games-rpg'], ['slug' => 'action']],
                    'platforms' => [['platform' => ['name' => 'PC']]],
                    'stores' => [['store' => ['slug' => 'steam']]],
                ],
                [
                    'id' => 1018059, 'slug' => 'console-only', 'name' => 'Console Only', 'released' => '2026-12-03',
                    'rating' => 0, 'ratings_count' => 0, 'genres' => [['slug' => 'platformer']],
                    'platforms' => [['platform' => ['name' => 'PlayStation 5']]], 'stores' => [],
                ],
            ]]),
        ]);

        $this->collect('upcoming');
        $this->collect('upcoming');

        $this->assertSame(2, Game::count());
        $steamGame->refresh();
        $this->assertSame([391403, 'Path of Exile 2（Steam の名前）', 3.55, true], [
            $steamGame->rawg_id, $steamGame->name, $steamGame->rawg_rating, $steamGame->coming_soon,
        ]);
        $this->assertEqualsCanonicalizing(['RPG', 'アクション'], $steamGame->genres->pluck('name')->all());

        $console = Game::where('rawg_id', 1018059)->sole();
        $this->assertNull($console->steam_appid);
        $this->assertFalse($console->tracked);
        $this->assertNull($console->rawg_rating);
        $this->assertSame(['PlayStation 5'], $console->platforms);

        Http::assertSentCount(3);
    }

    public function test_古い毎時の人数は90日で消す(): void
    {
        $game = Game::create(['steam_appid' => 1]);
        DB::table('player_snapshots')->insert([
            ['game_id' => $game->id, 'captured_at' => '2026-08-25 00:00:00', 'players' => 1],
            ['game_id' => $game->id, 'captured_at' => '2026-08-26 04:00:00', 'players' => 2],
        ]);

        $this->collect('prune_snapshots');

        $this->assertSame([2], DB::table('player_snapshots')->pluck('players')->all());
    }

    public function test_1日1回の処理は前回の成功から24時間たつまで実行しない。毎時の処理は毎回実行する(): void
    {
        Http::fake(['*GetMostPlayedGames*' => Http::response(['response' => ['ranks' => []]])]);
        FetchRun::create(['job' => 'top_games', 'status' => 'success', 'started_at' => now()->subHours(22)]);

        $this->artisan('app:collect', ['--only' => ['top_games', 'aggregate_daily']])->assertSuccessful();
        $this->assertSame(1, FetchRun::where('job', 'top_games')->count());
        $this->assertSame(1, FetchRun::where('job', 'aggregate_daily')->count());

        // 前回から23時間（1時間の余裕）たてば実行する
        $this->travel(1)->hours();
        $this->artisan('app:collect', ['--only' => ['top_games']])->assertSuccessful();
        $this->assertSame(2, FetchRun::where('job', 'top_games')->count());
    }

    public function test_1つの処理が失敗してもほかは続け、失敗の記録から_ap_i_キーを伏せる(): void
    {
        Http::fake([
            'api.rawg.io/*' => Http::failedConnection(),
            '*GetMostPlayedGames*' => Http::response(['response' => ['ranks' => [['appid' => 730]]]]),
        ]);

        $this->artisan('app:collect', ['--only' => ['upcoming', 'top_games'], '--force' => true])->assertFailed();

        $failed = FetchRun::where('job', 'upcoming')->sole();
        $this->assertSame('failed', $failed->status);
        $this->assertStringNotContainsString('test-key', (string) $failed->message);
        $this->assertSame('success', FetchRun::where('job', 'top_games')->sole()->status);
    }
}
