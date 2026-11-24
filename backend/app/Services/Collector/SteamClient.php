<?php

namespace App\Services\Collector;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Steam の API を呼ぶ。外部 API の形の違いはここで吸収し、取得処理（Jobs）には配列で渡す
 */
class SteamClient
{
    /**
     * 今遊ばれている人数の上位100本の appid（公式 API、キー不要）
     *
     * @return list<int>
     *
     * @throws RequestException
     */
    public function mostPlayed(): array
    {
        $ranks = Http::timeout(config('collector.http_timeout'))
            ->retry(2, 1000)
            ->get(config('services.steam.api_url').'/ISteamChartsService/GetMostPlayedGames/v1/')
            ->throw()
            ->json('response.ranks', []);

        return array_values(array_map(fn (array $rank) => (int) $rank['appid'], $ranks));
    }

    /**
     * 今遊んでいる人数。何本かずつ同時に問い合わせる。取れなかったゲームは null
     *
     * @param  list<int>  $appids
     * @return array<int, int|null> appid => 人数
     */
    public function currentPlayers(array $appids): array
    {
        $result = [];
        foreach (array_chunk($appids, max(1, (int) config('collector.players_concurrency'))) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (int $appid) => $pool->as((string) $appid)
                    ->timeout(config('collector.http_timeout'))
                    ->get(config('services.steam.api_url').'/ISteamUserStats/GetNumberOfCurrentPlayers/v1/', ['appid' => $appid]),
                $chunk,
            ));

            foreach ($chunk as $appid) {
                $response = $responses[(string) $appid] ?? null;
                // 接続できなかったときは Response ではなく例外のオブジェクトが入る
                $ok = $response instanceof Response && $response->successful() && $response->json('response.result') === 1;
                $result[$appid] = $ok ? (int) $response->json('response.player_count') : null;
            }
        }

        return $result;
    }

    /**
     * ストアの詳細（価格、ジャンル、発売日、Metacritic など）。日本円・日本語で取る。
     * ストアにない（地域で売っていない、削除された）ときは null
     *
     * @return array<string, mixed>|null
     *
     * @throws RequestException
     */
    public function appDetails(int $appid): ?array
    {
        $body = Http::timeout(config('collector.http_timeout'))
            ->retry(2, 2000)
            ->get(config('services.steam.store_url').'/api/appdetails', ['appids' => $appid, 'cc' => 'jp', 'l' => 'japanese'])
            ->throw()
            ->json((string) $appid);

        return ($body['success'] ?? false) ? $body['data'] : null;
    }
}
