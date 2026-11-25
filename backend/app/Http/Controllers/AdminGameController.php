<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\Game;
use App\Services\AuditLogger;
use App\Services\Dashboard\PlayerStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * 集めるゲームの管理（admin だけ）。ゲームではないソフト（壁紙アプリなど）を集計から外す・戻す
 */
class AdminGameController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, PlayerStats $stats): JsonResponse
    {
        Gate::authorize('manage', Game::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(['collecting', 'excluded', 'stopped'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $latest = $stats->latestCapturedAt();
        $games = Game::query()
            // 今の人数（最新の取得時刻の値）を、一覧と同じ1回の SQL で付ける
            ->leftJoin('player_snapshots as s', fn ($j) => $j->on('s.game_id', '=', 'games.id')->where('s.captured_at', '=', $latest))
            ->when($filters['q'] ?? null, function ($q, string $text) {
                $q->where('games.name', 'ilike', '%'.addcslashes($text, '%_\\').'%');
            })
            ->when($filters['state'] ?? null, fn ($q, string $state) => match ($state) {
                'excluded' => $q->whereNotNull('games.excluded_at'),
                // 取得処理が「日本のストアにない」などで止めたもの
                'stopped' => $q->whereNull('games.excluded_at')->where('games.tracked', false),
                'collecting' => $q->whereNull('games.excluded_at')->where('games.tracked', true),
            })
            ->orderByRaw('s.players DESC NULLS LAST')
            ->orderBy('games.id')
            ->select('games.id', 'games.name', 'games.image_url', 'games.steam_appid', 'games.rawg_id', 'games.tracked',
                'games.coming_soon', 'games.excluded_at', 'games.details_fetched_at', 's.players')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($games->items())->map(fn (Game $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'image_url' => $g->image_url,
                'steam_appid' => $g->steam_appid,
                'rawg_id' => $g->rawg_id,
                'players' => $g->players,
                'coming_soon' => $g->coming_soon,
                'state' => $g->excluded_at ? 'excluded' : ($g->tracked ? 'collecting' : 'stopped'),
                'excluded_at' => $g->excluded_at?->toIso8601String(),
                'details_fetched_at' => $g->details_fetched_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $games->currentPage(), 'last_page' => $games->lastPage(), 'total' => $games->total()],
        ]);
    }

    public function update(Request $request, Game $game): JsonResponse
    {
        Gate::authorize('update', Game::class);
        $excluded = $request->validate(['excluded' => ['required', 'boolean']])['excluded'];

        if ($excluded !== ($game->excluded_at !== null)) {
            DB::transaction(function () use ($request, $game, $excluded) {
                $game->update(['excluded_at' => $excluded ? now() : null]);
                $this->audit->record($request->user(), $excluded ? AuditAction::GameExcluded : AuditAction::GameIncluded, $game);
            });
            // 取得時刻が変わらなくても、ランキングなどからすぐに消える（戻る）ように、集計のキャッシュを作り直させる
            PlayerStats::invalidate();
        }

        return response()->json(['excluded' => $game->excluded_at !== null]);
    }
}
