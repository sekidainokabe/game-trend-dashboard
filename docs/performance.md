# 大量データでの速さの確認

本番で集めるのは約100本 × 毎時 × 90日分（約22万行）です。その約20倍の量を入れても、閲覧用の API が十分に速いことを確かめました（2026-11-25）。

## 測り方

開発用の DB とは別に `laravel_scale` という DB を作り、そこに大量の仮データを入れて測ります（開発用のデータを消さないため、`app:seed-scale` は名前に「scale」を含む DB でしか動きません）。

```bash
docker compose exec -T pgsql psql -U sail -d laravel -c "CREATE DATABASE laravel_scale"
docker compose exec -T -e DB_DATABASE=laravel_scale laravel.test php artisan migrate --force
docker compose exec -T -e DB_DATABASE=laravel_scale laravel.test php artisan app:seed-scale   # 約30秒
docker compose exec -T -e DB_DATABASE=laravel_scale laravel.test php artisan app:bench
```

- `app:seed-scale`：ゲーム2,000本、毎時の人数720時間分（115万行）、1日ごとの集計90日分（14万行）を、PostgreSQL の `generate_series` で DB の中で作ります。
- `app:bench`：各 API を5回ずつ呼び、応答時間の中央値と SQL の回数を出します。**毎回キャッシュを消してから呼ぶ**ので、キャッシュが効かない最悪の場合の時間です（本番では、集計の結果を1時間使い回します）。

## 結果

| API | 中央値 | SQL の回数 |
| --- | ---: | ---: |
| ホーム `/api/dashboard` | 32ms | 11 |
| ランキング `/api/ranking` | 24ms | 7 |
| ランキング（増減の順・RPG で絞り込み） | 26ms | 8 |
| ジャンルの比較 `/api/genres` | 87ms | 7 |
| 評価と人気 `/api/ratings` | 44ms | 5 |
| 発売カレンダー `/api/releases` | 29ms | 8 |
| ゲームの詳細 `/api/games/1` | 18ms | 8 |
| 人数の推移（7日・毎時） | 17ms | 6 |
| 人数の推移（90日・1日ごと） | 15ms | 6 |
| 取得の状況（管理） | 30ms | 4 |
| ゲームの管理（名前で検索） | 21ms | 3 |

SQL の回数には、キャッシュとログイン情報の読み書きも含みます。どの API も、ゲームの本数によらず SQL の回数は一定です（ゲームごとに問い合わせる N+1 にしていない）。

## いちばん遅い「ジャンルの比較」を調べた

`EXPLAIN ANALYZE` で、30日間のジャンル別の推移の SQL を調べました。

- 1日ごとの集計 48,000行を日付の索引で読む部分は 14ms で、索引は効いています。
- 時間の大半は、1本が複数のジャンルに入るため行が約2倍（96,000行）に増えたあとの、結合と集計にかかっています。
- 読む列だけを含む索引（`(date) INCLUDE (game_id, peak_players)`）を試しましたが、PostgreSQL はそれを使わず、時間も変わりませんでした。**効果がないので入れていません**（索引は書き込みを遅くし、容量も使うため）。

本番の量（約100本）では数 ms で、さらに結果を1時間キャッシュするため、今のままで十分と判断しました。ゲームの数が何十倍にも増えたときは、ジャンル別の日ごとの合計を集計の処理で前もって作っておく（読むときに集計しない）方法に切り替えます。

## 索引の一覧（読む SQL と対応）

| 表 | 索引 | 使う SQL |
| --- | --- | --- |
| player_snapshots | 主キー (game_id, captured_at) | 1本のゲームの推移、24時間前の人数との比較 |
| player_snapshots | (captured_at) | 最新の取得時刻、その時刻の全ゲームの人数 |
| daily_stats | 主キー (game_id, date) | 1本のゲームの30日・90日の推移、ランキングの小さなグラフ |
| daily_stats | (date) | ジャンル別の推移 |
| games | (release_date) | 近日発売、発売カレンダー |
| fetch_runs | (job, started_at) | 処理ごとの最後の結果、1日1回の処理を動かすかの判断 |
