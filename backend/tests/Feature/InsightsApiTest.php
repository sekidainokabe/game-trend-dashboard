<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 分析の画面の API（ログインなし）：ランキング表、ジャンルの比較、評価と人気、発売カレンダー
 */
class InsightsApiTest extends TestCase
{
    use RefreshDatabase;

    private const LATEST = '2026-11-24 08:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-24 08:30:00');
    }

    /**
     * @param  list<string>  $genres
     */
    private function game(string $name, int $players, ?int $players24hAgo = null, array $attributes = [], array $genres = []): Game
    {
        $game = Game::create(['steam_appid' => random_int(1, 9_999_999), 'name' => $name, ...$attributes]);
        DB::table('player_snapshots')->insert(['game_id' => $game->id, 'captured_at' => self::LATEST, 'players' => $players]);
        if ($players24hAgo !== null) {
            DB::table('player_snapshots')->insert(['game_id' => $game->id, 'captured_at' => '2026-11-23 08:00:00', 'players' => $players24hAgo]);
        }
        $game->genres()->attach(Genre::idsForKeys($genres));

        return $game;
    }

    public function test_ランキングは指定した列で並べ替え、値のないゲームは最後に回す(): void
    {
        $this->game('高評価', 1000, attributes: ['metacritic' => 95]);
        $this->game('評価なし', 9000);
        $this->game('中評価', 5000, attributes: ['metacritic' => 70]);

        $this->getJson('/api/ranking?sort=metacritic&order=desc')->assertOk()
            ->assertJsonPath('data.*.name', ['高評価', '中評価', '評価なし']);
        $this->getJson('/api/ranking?sort=metacritic&order=asc')->assertOk()
            ->assertJsonPath('data.*.name', ['中評価', '高評価', '評価なし']);
    }

    public function test_ランキングはジャンルと価格で絞り込める(): void
    {
        $this->game('無料のRPG', 3000, attributes: ['is_free' => true], genres: ['rpg']);
        $this->game('セール中のRPG', 2000, attributes: ['price_jpy' => 1000, 'discount_percent' => 50], genres: ['rpg']);
        $this->game('アクション', 9000, genres: ['action']);

        $this->getJson('/api/ranking?genre=rpg')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.genres', ['RPG']);
        $this->getJson('/api/ranking?genre=rpg&price=sale')->assertOk()
            ->assertJsonPath('data.*.name', ['セール中のRPG']);
    }

    public function test_ランキングは決まった並べ替えと絞り込みしか受け付けない(): void
    {
        $this->getJson('/api/ranking?sort=name;drop table games')->assertUnprocessable();
        $this->getJson('/api/ranking?genre=unknown')->assertUnprocessable();
        $this->getJson('/api/ranking?price=cheap')->assertUnprocessable();
    }

    public function test_ジャンルの比較は人数の合計と平均、評価、発売予定の本数を返す(): void
    {
        $this->game('A', 3000, 2000, ['metacritic' => 90, 'price_jpy' => 2000], ['rpg', 'free-to-play']);
        $this->game('B', 1000, null, ['metacritic' => 70, 'is_free' => true], ['rpg']);
        $this->game('C', 500, 500, [], ['action']);
        $upcoming = Game::create(['name' => '発売前', 'release_date' => '2026-12-10']);
        $upcoming->genres()->attach(Genre::idsForKeys(['rpg']));

        $this->getJson('/api/genres')->assertOk()
            // 「基本無料」は遊び方の種類ではないので、比較に入れない
            ->assertJsonCount(2, 'genres')
            ->assertJsonPath('genres.0.key', 'rpg')
            ->assertJsonPath('genres.0.games', 2)
            ->assertJsonPath('genres.0.total_players', 4000)
            ->assertJsonPath('genres.0.avg_players', 2000)
            // 24時間前の値があるゲーム（A）だけで比べる：2000 → 3000
            ->assertJsonPath('genres.0.change_pct', 50)
            ->assertJsonPath('genres.0.avg_metacritic', 80)
            ->assertJsonPath('genres.0.avg_price_jpy', 2000)
            ->assertJsonPath('genres.0.free_ratio', 0.5)
            ->assertJsonPath('genres.0.upcoming_games', 1)
            ->assertJsonPath('genres.1.key', 'action');
    }

    public function test_ジャンルの推移は日ごとに、ジャンルの人数を足して返す(): void
    {
        $a = $this->game('A', 1, genres: ['rpg']);
        $b = $this->game('B', 1, genres: ['rpg', 'action']);
        DB::table('daily_stats')->insert([
            ['game_id' => $a->id, 'date' => '2026-11-20', 'peak_players' => 100, 'avg_players' => 1],
            ['game_id' => $b->id, 'date' => '2026-11-20', 'peak_players' => 50, 'avg_players' => 1],
        ]);

        $this->getJson('/api/genres')->assertOk()
            ->assertJsonPath('trend.0.date', '2026-11-20')
            ->assertJsonPath('trend.0.values', ['action' => 50, 'rpg' => 150]);
    }

    public function test_評価と人気は、今の人数と主なジャンルを返す(): void
    {
        $this->game('A', 2000, attributes: ['metacritic' => 88, 'recommendations' => 5000], genres: ['free-to-play', 'action']);

        $this->getJson('/api/ratings')->assertOk()
            ->assertJsonPath('games.0.players', 2000)
            ->assertJsonPath('games.0.metacritic', 88)
            ->assertJsonPath('games.0.genre_key', 'action');
    }

    public function test_発売カレンダーは指定した月のゲームと、先の月の本数を返す(): void
    {
        Game::create(['name' => '12月', 'release_date' => '2026-12-05']);
        Game::create(['name' => '1月', 'release_date' => '2027-01-10']);
        Game::create(['name' => '日付未定', 'coming_soon' => true]);

        $this->getJson('/api/releases?month=2026-12')->assertOk()
            ->assertJsonPath('month', '2026-12')
            ->assertJsonPath('games.*.name', ['12月'])
            ->assertJsonPath('months.0', ['month' => '2026-11', 'count' => 0])
            ->assertJsonPath('months.1', ['month' => '2026-12', 'count' => 1])
            ->assertJsonPath('months.2', ['month' => '2027-01', 'count' => 1])
            ->assertJsonPath('undated.0.name', '日付未定');

        $this->getJson('/api/releases?month=2026-13')->assertUnprocessable();
    }
}
