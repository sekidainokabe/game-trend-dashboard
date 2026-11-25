<?php

namespace App\Http\Controllers;

use App\Models\FetchRun;
use App\Models\Game;
use App\Services\Dashboard\PlayerStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * データ取得の状況（admin だけ）：処理ごとの最後の結果と、実行の記録
 */
class AdminCollectionController extends Controller
{
    private const PER_PAGE = 50;

    /** 処理の名前と、画面に出す説明。順番は実行の順 */
    public const JOBS = [
        'top_games' => 'Steam の人気上位を取得',
        'steam_details' => 'ゲームの詳細（価格・ジャンル・評価）',
        'upcoming' => '発売予定（RAWG）',
        'players' => '今遊んでいる人数',
        'aggregate_daily' => '1日ごとの集計',
        'prune_snapshots' => '古いデータの削除',
    ];

    public function index(Request $request, PlayerStats $stats): JsonResponse
    {
        Gate::authorize('manage', Game::class);
        $filters = $request->validate([
            'job' => ['nullable', Rule::in(array_keys(self::JOBS))],
            'status' => ['nullable', Rule::in(['running', 'success', 'partial', 'failed'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // 処理ごとの最後の1件（PostgreSQL の DISTINCT ON で1回の SQL）
        $last = collect(DB::select('SELECT DISTINCT ON (job) * FROM fetch_runs ORDER BY job, started_at DESC, id DESC'))
            ->keyBy('job');

        $runs = FetchRun::query()
            ->when($filters['job'] ?? null, fn ($q, string $job) => $q->where('job', $job))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $latest = $stats->latestCapturedAt();

        return response()->json([
            'latest_captured_at' => $latest?->toIso8601String(),
            'jobs' => collect(self::JOBS)->map(fn (string $label, string $job) => [
                'job' => $job,
                'label' => $label,
                'last' => isset($last[$job]) ? $this->run($last[$job]) : null,
            ])->values(),
            'data' => collect($runs->items())->map(fn (FetchRun $r) => $this->run($r)),
            'meta' => ['current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage(), 'total' => $runs->total()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(object $r): array
    {
        $started = CarbonImmutable::parse($r->started_at, 'UTC');
        $finished = $r->finished_at ? CarbonImmutable::parse($r->finished_at, 'UTC') : null;

        return [
            'id' => $r->id,
            'job' => $r->job,
            'status' => $r->status,
            'total' => $r->total,
            'succeeded' => $r->succeeded,
            'failed' => $r->failed,
            'message' => $r->message,
            'started_at' => $started->toIso8601String(),
            'duration_sec' => $finished ? (int) $started->diffInSeconds($finished) : null,
        ];
    }
}
