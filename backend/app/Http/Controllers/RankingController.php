<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\PlayerStats;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ランキング表：並べ替えと、ジャンル・価格での絞り込み。ログインなしで見られる
 */
class RankingController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * 並べ替えに使える列。利用者の入力を SQL に直接入れず、決まった式だけを使う（SQL インジェクション対策）
     */
    private const SORTS = [
        'players' => 's.players',
        'change' => '(s.players - prev.players)::float / NULLIF(prev.players, 0)',
        'metacritic' => 'g.metacritic',
        'recommendations' => 'g.recommendations',
        'price' => 'g.price_jpy',
        'release' => 'g.release_date',
    ];

    public function __invoke(Request $request, PlayerStats $stats): JsonResponse
    {
        $params = $request->validate([
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'order' => ['nullable', Rule::in(['asc', 'desc'])],
            'genre' => ['nullable', 'string', 'exists:genres,key'],
            'price' => ['nullable', Rule::in(['free', 'paid', 'sale'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $params += ['sort' => 'players', 'order' => 'desc', 'genre' => null, 'price' => null, 'page' => 1];
        ksort($params);

        $data = $stats->remember('ranking:'.md5(json_encode($params)), function (?CarbonImmutable $latest) use ($stats, $params) {
            if ($latest === null) {
                return ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]];
            }

            $query = $stats->latestWithChange($latest)
                ->addSelect('g.is_free', 'g.price_jpy', 'g.discount_percent', 'g.metacritic', 'g.rawg_rating', 'g.recommendations', 'g.release_date');
            $this->filter($query, $params);

            // 値のないゲームは、昇順でも降順でも最後に回す。同じ値なら人数の多い順
            $direction = $params['order'] === 'asc' ? 'ASC' : 'DESC';
            $query->orderByRaw(self::SORTS[$params['sort']]." {$direction} NULLS LAST")
                ->orderByDesc('s.players')
                ->orderBy('g.id');

            $page = $query->paginate(self::PER_PAGE, ['*'], 'page', $params['page']);
            $genres = $stats->genreNames(collect($page->items())->pluck('id')->all());

            return [
                'data' => collect($page->items())->map(fn (object $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'image_url' => $r->image_url,
                    'players' => $r->players,
                    'change_pct' => PlayerStats::changePct($r->players, $r->players_24h_ago),
                    'is_free' => (bool) $r->is_free,
                    'price_jpy' => $r->price_jpy,
                    'discount_percent' => $r->discount_percent,
                    'metacritic' => $r->metacritic,
                    'rawg_rating' => $r->rawg_rating === null ? null : (float) $r->rawg_rating,
                    'recommendations' => $r->recommendations,
                    'release_date' => $r->release_date,
                    'genres' => $genres->get($r->id, []),
                ])->all(),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            ];
        });

        return response()->json($data);
    }

    /**
     * @param  array{genre: string|null, price: string|null}  $params
     */
    private function filter(Builder $query, array $params): void
    {
        if ($params['genre'] !== null) {
            $query->whereExists(fn (Builder $q) => $q->from('game_genre')
                ->join('genres', 'genres.id', '=', 'game_genre.genre_id')
                ->whereColumn('game_genre.game_id', 'g.id')
                ->where('genres.key', $params['genre']));
        }

        match ($params['price']) {
            'free' => $query->where('g.is_free', true),
            'paid' => $query->where('g.is_free', false),
            'sale' => $query->where('g.discount_percent', '>', 0),
            null => null,
        };
    }
}
