<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\Dashboard\PlayerStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 発売カレンダー：月ごとの発売予定と、先の月の本数。ログインなしで見られる
 */
class ReleaseController extends Controller
{
    private const MONTHS_AHEAD = 6;

    private const UNDATED_LIMIT = 24;

    public function __invoke(Request $request, PlayerStats $stats): JsonResponse
    {
        $thisMonth = now()->timezone('Asia/Tokyo')->startOfMonth()->toImmutable();
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? $thisMonth->format('Y-m');
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);

        // 発売予定は毎日の取得で変わる。最新の取得時刻をキーにしたキャッシュで十分に新しい
        $data = $stats->remember("releases:{$month}", function () use ($start, $thisMonth, $stats) {
            $games = Game::whereBetween('release_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
                ->orderBy('release_date')
                ->orderBy('name')
                ->get(['id', 'name', 'image_url', 'release_date', 'platforms', 'is_free', 'price_jpy', 'coming_soon']);

            $undated = Game::where('coming_soon', true)->whereNull('release_date')
                ->orderBy('name')
                ->limit(self::UNDATED_LIMIT)
                ->get(['id', 'name', 'image_url', 'platforms', 'is_free', 'price_jpy', 'coming_soon']);

            $genres = $stats->genreNames([...$games->pluck('id'), ...$undated->pluck('id')]);
            $toArray = fn (Game $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'image_url' => $g->image_url,
                'release_date' => $g->release_date?->toDateString(),
                'platforms' => $g->platforms ?? [],
                'is_free' => $g->is_free,
                'price_jpy' => $g->price_jpy,
                'genres' => $genres->get($g->id, []),
            ];

            return [
                'month' => $start->format('Y-m'),
                'games' => $games->map($toArray)->all(),
                'months' => $this->monthCounts($thisMonth),
                'undated' => $undated->map($toArray)->all(),
            ];
        });

        return response()->json($data);
    }

    /**
     * 今月から先の月ごとの発売本数（1回の SQL）
     *
     * @return list<array{month: string, count: int}>
     */
    private function monthCounts(CarbonImmutable $thisMonth): array
    {
        $end = $thisMonth->addMonths(self::MONTHS_AHEAD - 1)->endOfMonth();
        $counts = DB::table('games')
            ->whereBetween('release_date', [$thisMonth->toDateString(), $end->toDateString()])
            ->groupByRaw("to_char(release_date, 'YYYY-MM')")
            ->selectRaw("to_char(release_date, 'YYYY-MM') as month, count(*) as games")
            ->pluck('games', 'month');

        return collect(range(0, self::MONTHS_AHEAD - 1))
            ->map(fn (int $i) => $thisMonth->addMonths($i)->format('Y-m'))
            ->map(fn (string $m) => ['month' => $m, 'count' => (int) ($counts[$m] ?? 0)])
            ->all();
    }
}
