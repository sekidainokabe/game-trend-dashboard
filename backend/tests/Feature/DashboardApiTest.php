<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 閲覧用の API（ログインなし）：ホームの集計、ゲームの詳細、人数の推移
 */
class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private const LATEST = '2026-11-24 08:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-24 08:30:00');
    }

    private function game(string $name, int $players, ?int $players24hAgo = null, array $attributes = []): Game
    {
        $game = Game::create(['steam_appid' => random_int(1, 9_999_999), 'name' => $name, ...$attributes]);
        DB::table('player_snapshots')->insert(['game_id' => $game->id, 'captured_at' => self::LATEST, 'players' => $players]);
        if ($players24hAgo !== null) {
            DB::table('player_snapshots')->insert(['game_id' => $game->id, 'captured_at' => '2026-11-23 08:00:00', 'players' => $players24hAgo]);
        }

        return $game;
    }

    public function test_ホームは人数の多い順と、24時間前からの増減率、合計を返す(): void
    {
        $a = $this->game('A', 5000, 4000);
        $this->game('B', 9000);
        $a->genres()->attach(Genre::idsForKeys(['action']));

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('updated_at', '2026-11-24T08:00:00+00:00')
            ->assertJsonPath('total_players', 14000)
            ->assertJsonPath('top.0.name', 'B')
            ->assertJsonPath('top.0.change_pct', null)
            ->assertJsonPath('top.1.name', 'A')
            ->assertJsonPath('top.1.change_pct', 25)
            ->assertJsonPath('top.1.genres', ['アクション']);
    }

    public function test_急上昇は増えた割合の順で、もともと少ないゲームは入れない(): void
    {
        $this->game('2倍', 4000, 2000);
        $this->game('1.5倍', 3000, 2000);
        $this->game('少人数', 500, 100);
        $this->game('減った', 1000, 2000);

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonCount(2, 'rising')
            ->assertJsonPath('rising.0.name', '2倍')
            ->assertJsonPath('rising.0.change_pct', 100)
            ->assertJsonPath('rising.1.name', '1.5倍');
    }

    public function test_近日発売は30日以内を発売日の順に返す(): void
    {
        Game::create(['name' => '来月', 'release_date' => '2026-12-30']);
        Game::create(['name' => '来週', 'release_date' => '2026-12-01', 'platforms' => ['PC']]);
        Game::create(['name' => '発売済み', 'release_date' => '2026-11-01']);

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonCount(1, 'upcoming')
            ->assertJsonPath('upcoming.0.name', '来週')
            ->assertJsonPath('upcoming.0.platforms', ['PC']);
    }

    public function test_データがまだなくても_200_を返す(): void
    {
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('updated_at', null)
            ->assertJsonPath('top', []);
    }

    public function test_ホームの_sq_l_の回数はゲームの数によらない(): void
    {
        foreach (range(1, 15) as $i) {
            $this->game("G{$i}", 1000 * $i, 900 * $i)->genres()->attach(Genre::idsForKeys(['action', 'rpg']));
        }

        DB::enableQueryLog();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonCount(10, 'top');
        $count = count(DB::getQueryLog());

        // ゲームごとに問い合わせると 10 本 × 数回になる。まとめて取れば一定の回数で済む
        $this->assertLessThanOrEqual(12, $count);
    }

    public function test_ゲームの詳細は、今の人数と増減率、ストアのリンクを返す(): void
    {
        $game = $this->game('A', 1200, 1000, ['price_jpy' => 4510, 'discount_percent' => 50, 'metacritic' => 94, 'platforms' => ['PC']]);

        $this->getJson("/api/games/{$game->id}")->assertOk()
            ->assertJsonPath('data.name', 'A')
            ->assertJsonPath('data.players', 1200)
            ->assertJsonPath('data.change_pct', 20)
            ->assertJsonPath('data.price_jpy', 4510)
            ->assertJsonPath('data.store_url', "https://store.steampowered.com/app/{$game->steam_appid}/");

        $this->getJson('/api/games/999999')->assertNotFound();
    }

    public function test_推移は短い期間なら毎時、長い期間なら1日ごとの値を返す(): void
    {
        $game = $this->game('A', 1200, 1000);
        DB::table('daily_stats')->insert([
            ['game_id' => $game->id, 'date' => '2026-11-01', 'peak_players' => 900, 'avg_players' => 700, 'price_jpy' => 2000, 'discount_percent' => 50],
            ['game_id' => $game->id, 'date' => '2026-09-01', 'peak_players' => 1, 'avg_players' => 1, 'price_jpy' => 4000, 'discount_percent' => 0],
        ]);

        $this->getJson("/api/games/{$game->id}/players?range=24h")->assertOk()
            ->assertJsonPath('granularity', 'hour')
            ->assertJsonCount(1, 'points')
            ->assertJsonPath('points.0.t', '2026-11-24T08:00:00+00:00');

        $this->getJson("/api/games/{$game->id}/players?range=30d")->assertOk()
            ->assertJsonPath('granularity', 'day')
            ->assertJsonCount(1, 'points')
            ->assertJsonPath('points.0.discount_percent', 50);

        $this->getJson("/api/games/{$game->id}/players?range=1y")->assertUnprocessable();
    }
}
