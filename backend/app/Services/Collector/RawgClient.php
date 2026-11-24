<?php

namespace App\Services\Collector;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * RAWG（ゲームデータベース）の API を呼ぶ。キーは .env の RAWG_API_KEY
 */
class RawgClient
{
    /**
     * 期間内に発売されるゲーム（注目度の高い順）。1ページ40件
     *
     * @return array{results: list<array<string, mixed>>, has_next: bool}
     *
     * @throws RequestException
     */
    public function releasesBetween(string $from, string $to, int $page): array
    {
        $body = $this->request()
            ->get('/games', ['dates' => "{$from},{$to}", 'ordering' => '-added', 'page_size' => 40, 'page' => $page])
            ->throw()
            ->json();

        return ['results' => $body['results'] ?? [], 'has_next' => ! empty($body['next'])];
    }

    /**
     * ゲームの Steam の appid。ストアの一覧から Steam のストア URL を探す。Steam で売っていなければ null
     *
     * @throws RequestException
     */
    public function steamAppid(int $rawgId): ?int
    {
        $stores = $this->request()->get("/games/{$rawgId}/stores")->throw()->json('results', []);
        foreach ($stores as $store) {
            if (preg_match('#store\.steampowered\.com/app/(\d+)#', (string) ($store['url'] ?? ''), $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function request(): PendingRequest
    {
        $key = config('services.rawg.key');
        if (empty($key)) {
            throw new RuntimeException('RAWG_API_KEY が設定されていません。');
        }

        return Http::baseUrl(config('services.rawg.base_url'))
            ->timeout(config('collector.http_timeout'))
            ->retry(2, 1000)
            ->withQueryParameters(['key' => $key]);
    }
}
