<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\WatchlistController;
use App\Models\AuditLog;
use App\Models\FetchRun;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ウォッチリスト（ログインした人）と、データ取得の管理（admin だけ）
 */
class WatchlistAndAdminTest extends TestCase
{
    use RefreshDatabase;

    private const LATEST = '2026-11-24 08:00:00';

    private User $member;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-24 08:30:00');
        $this->seed();
        $this->member = User::where('role', UserRole::Member)->firstOrFail();
        $this->admin = User::where('role', UserRole::Admin)->firstOrFail();
    }

    private function game(string $name, int $players, ?int $players24hAgo = null): Game
    {
        $game = Game::create(['steam_appid' => random_int(1, 9_999_999), 'name' => $name]);
        DB::table('player_snapshots')->insert(['game_id' => $game->id, 'captured_at' => self::LATEST, 'players' => $players]);
        if ($players24hAgo !== null) {
            DB::table('player_snapshots')->insert(['game_id' => $game->id, 'captured_at' => '2026-11-23 08:00:00', 'players' => $players24hAgo]);
        }

        return $game;
    }

    public function test_ウォッチリストはログインが必要(): void
    {
        $game = $this->game('A', 100);

        $this->getJson('/api/me/watchlist')->assertUnauthorized();
        $this->putJson("/api/me/watchlist/{$game->id}")->assertUnauthorized();
    }

    public function test_登録したゲームを、今の人数と増減つきで新しい順に返す(): void
    {
        $a = $this->game('A', 1200, 1000);
        $b = $this->game('B', 500);

        $this->actingAs($this->member)->putJson("/api/me/watchlist/{$a->id}")->assertNoContent();
        $this->travel(1)->minutes();
        $this->actingAs($this->member)->putJson("/api/me/watchlist/{$b->id}")->assertNoContent();
        // 同じゲームをもう一度登録しても1件のまま
        $this->actingAs($this->member)->putJson("/api/me/watchlist/{$a->id}")->assertNoContent();

        $this->actingAs($this->member)->getJson('/api/me/watchlist')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'B')
            ->assertJsonPath('data.1.players', 1200)
            ->assertJsonPath('data.1.change_pct', 20);

        // 他の人のウォッチリストには入らない
        $this->actingAs($this->admin)->getJson('/api/me/watchlist')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->member)->deleteJson("/api/me/watchlist/{$a->id}")->assertNoContent();
        $this->actingAs($this->member)->getJson('/api/me/watchlist')->assertJsonCount(1, 'data');
    }

    public function test_ウォッチリストは上限の本数まで(): void
    {
        $games = collect(range(1, WatchlistController::LIMIT + 1))->map(fn ($i) => Game::create(['name' => "G{$i}"]));
        DB::table('watchlist')->insert($games->take(WatchlistController::LIMIT)
            ->map(fn (Game $g) => ['user_id' => $this->member->id, 'game_id' => $g->id])->all());

        $this->actingAs($this->member)->putJson("/api/me/watchlist/{$games->last()->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('game');
        // すでに登録したゲームは、上限でも成功する（同じ結果になるだけ）
        $this->actingAs($this->member)->putJson("/api/me/watchlist/{$games->first()->id}")->assertNoContent();
    }

    public function test_取得の状況とゲームの管理は_admin_だけ(): void
    {
        $game = $this->game('A', 100);

        $this->actingAs($this->member)->getJson('/api/admin/collection')->assertForbidden();
        $this->actingAs($this->member)->getJson('/api/admin/games')->assertForbidden();
        $this->actingAs($this->member)->patchJson("/api/admin/games/{$game->id}", ['excluded' => true])->assertForbidden();
        $this->actingAs($this->member)->getJson('/api/me')->assertJsonPath('data.can.manage_games', false);
        $this->assertNull($game->fresh()->excluded_at);
    }

    public function test_取得の状況は、処理ごとの最後の結果と実行の記録を返す(): void
    {
        FetchRun::create(['job' => 'players', 'status' => 'success', 'total' => 100, 'succeeded' => 100, 'started_at' => '2026-11-24 07:07:00', 'finished_at' => '2026-11-24 07:07:30']);
        FetchRun::create(['job' => 'players', 'status' => 'partial', 'total' => 100, 'succeeded' => 97, 'failed' => 3, 'started_at' => '2026-11-24 08:07:00', 'finished_at' => '2026-11-24 08:07:40']);

        $this->actingAs($this->admin)->getJson('/api/admin/collection')->assertOk()
            ->assertJsonPath('jobs.3.job', 'players')
            ->assertJsonPath('jobs.3.last.status', 'partial')
            ->assertJsonPath('jobs.3.last.duration_sec', 40)
            ->assertJsonPath('jobs.0.last', null)
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($this->admin)->getJson('/api/admin/collection?status=success')->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_ゲームを外すと、すぐにランキングから消え、操作履歴に残る(): void
    {
        $game = $this->game('壁紙アプリ', 9000);
        $this->game('ゲーム', 100);

        // 先にキャッシュを作っておく（外したあとも古い結果が出ないことを確かめるため）
        $this->getJson('/api/dashboard')->assertJsonPath('top.0.name', '壁紙アプリ');

        $this->actingAs($this->admin)->patchJson("/api/admin/games/{$game->id}", ['excluded' => true])
            ->assertOk()->assertJsonPath('excluded', true);

        $this->getJson('/api/dashboard')->assertJsonPath('top.0.name', 'ゲーム')->assertJsonPath('total_players', 100);
        $this->actingAs($this->admin)->getJson('/api/admin/games?state=excluded')->assertJsonPath('data.0.name', '壁紙アプリ');
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'game.exclude', 'target_type' => 'game', 'target_id' => $game->id]);
        $this->actingAs($this->admin)->getJson('/api/audit-logs?action=game.exclude')->assertJsonPath('data.0.target.title', '壁紙アプリ');

        // 戻すと、また出る
        $this->actingAs($this->admin)->patchJson("/api/admin/games/{$game->id}", ['excluded' => false])->assertOk();
        $this->getJson('/api/dashboard')->assertJsonPath('top.0.name', '壁紙アプリ');
    }

    public function test_外したゲームは、取得処理でも人数を取らない(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*GetNumberOfCurrentPlayers*' => Http::response(['response' => ['player_count' => 50, 'result' => 1]])]);
        $kept = Game::create(['steam_appid' => 10, 'name' => 'ゲーム', 'tracked' => true]);
        $excluded = Game::create(['steam_appid' => 20, 'name' => '壁紙アプリ', 'tracked' => true, 'excluded_at' => now()]);

        $this->artisan('app:collect', ['--only' => ['players'], '--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('player_snapshots', ['game_id' => $kept->id]);
        $this->assertDatabaseMissing('player_snapshots', ['game_id' => $excluded->id]);
        Http::assertSentCount(1);
    }

    public function test_公開デモの_admin_はゲームを集計から外せない(): void
    {
        config(['demo.accounts_locked' => true]);
        $game = $this->game('Demo Game', 1000);

        $this->actingAs($this->admin, 'web')
            ->getJson('/api/me')
            ->assertJsonPath('data.can.manage_games', true)
            ->assertJsonPath('data.can.update_games', false);
        $this->getJson('/api/admin/games')->assertOk();

        $this->patchJson("/api/admin/games/{$game->id}", ['excluded' => true])
            ->assertForbidden()
            ->assertJsonPath('message', '公開デモのため、管理画面は見るだけです（変更はできません）。');
        $this->assertNull($game->fresh()->excluded_at);
        $this->assertSame(0, AuditLog::count());
    }
}
