# 本番の手順（Supabase ＋ GitHub Actions ＋ cron-job.org ＋ Railway）

## 構成

| 役割 | サービス | 料金 | 動かし方 |
| --- | --- | --- | --- |
| DB | Supabase（PostgreSQL、東京） | 無料枠（500MB） | 常に動いている |
| 毎時の取得 | GitHub Actions（`.github/workflows/collect.yml`） | 無料（公開リポジトリ） | cron-job.org から毎時起動する |
| 起動の合図 | cron-job.org | 無料 | 毎時7分に GitHub の API を呼ぶ |
| 画面と API | Railway（リポジトリ直下の `Dockerfile`） | 使った分だけ | 見せるときだけ ON にする |

データは画面のアプリを止めている間もたまり続けます。Railway を ON にすると、その時点までのデータがすぐに見られます。

秘密の値（DB の接続先、API キー、トークン、`APP_KEY`）は、リポジトリやチャットには載せず、各サービスの設定画面に本人が入れます。

## 1. Supabase（DB）

1. プロジェクトを作る（Region は Tokyo）
2. 「Connect」→ **Session pooler** の URI を控える。これを `DB_URL` として GitHub と Railway の両方に入れる
   - Direct connection は IPv6 でしかつながらず、GitHub Actions のサーバーからは使えない
3. 表は、取得のワークフローと Railway の起動時に `migrate --force` で自動で作られる

無料のプロジェクトは、7日間使われないと一時停止します。毎時の取得で書き込みが続いている限り止まりません。

## 2. GitHub Actions（毎時の取得）

リポジトリの Settings → Secrets and variables → Actions に、次の2つを入れます。

| Secret | 値 |
| --- | --- |
| `DB_URL` | Supabase の Session pooler の URI |
| `RAWG_API_KEY` | RAWG のキー（https://rawg.io/apidocs） |

ワークフローは「PHP を用意 → `migrate --force` → `php artisan app:collect`」を行います。手動では Actions の画面の「毎時のデータ取得」→「Run workflow」で動かせます。

- 1日1回の処理（人気上位の更新、Steam の詳細、RAWG の発売予定、1日ごとの集計、古いデータの削除）がある回は約12分かかります。Actions のサーバー（米国）と Supabase（東京）の間の往復が長いためです。毎時の人数だけの回は30秒ほどです
- `concurrency` で、前の回が終わっていなければ次の回は待ちます
- ワークフローの `schedule`（毎時7分）は予備として残しています。GitHub の定期実行は混雑で数時間遅れたり抜けたりするため（2026-11-25 に17時間で1回しか動かなかった）、主には次の cron-job.org から起動します

## 3. cron-job.org（毎時の起動）

GitHub の「ワークフローを手動で起動する API」を、毎時呼びます。

### トークンを作る（GitHub）

Settings → Developer settings → Personal access tokens → **Fine-grained tokens** で作ります。

- Repository access：このリポジトリだけ
- Permissions：**Actions：Read and write**（Metadata：Read-only は自動で付く）
- 有効期限：最長でも1年。**切れると取得が止まる**ので、期限の前に作り直して cron-job.org の値を差し替える

### ジョブを作る（cron-job.org）

| 項目 | 値 |
| --- | --- |
| URL | `https://api.github.com/repos/sekidainokabe/game-trend-dashboard/actions/workflows/collect.yml/dispatches` |
| Schedule | 毎時 7分 |
| Request method | POST |
| Headers | `Authorization: Bearer （トークン）`、`Accept: application/vnd.github+json`、`X-GitHub-Api-Version: 2022-11-28` |
| Request body | `{"ref":"main"}` |

成功すると GitHub は 204（本文なし）を返し、Actions の画面に「workflow_dispatch」の実行が増えます。

### 動いているかの確かめ方

- Actions の画面で、毎時7分すぎに実行が増えているか
- 管理画面の「取得の状況」：最後に人数を取った時刻が2時間より前になると、注意の表示が出る
- cron-job.org の実行の履歴：401 ならトークンの期限切れか権限の不足、404 ならURLの誤り

## 4. Railway（画面と API）

### 最初の設定

1. New Project → Deploy from GitHub repo → このリポジトリを選ぶ。リポジトリ直下の `Dockerfile` からビルドされる
2. Settings
   - Region：Asia（Supabase の東京に近い場所）
   - Networking：公開ドメインを作り、転送先のポートは 8080
   - Deploy：Healthcheck Path は `/api/health`
   - Source：Auto deploys は Disable（push のたびに起動して料金がかからないように）
3. 環境変数（Variables）を入れる（下の表）
4. 未適用の変更（staged changes）の案内から Deploy する

| 変数 | 値 |
| --- | --- |
| `APP_KEY` | `sail artisan key:generate --show` で作った値 |
| `APP_URL` | `https://（公開ドメイン）` |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | Supabase の Session pooler の URI（GitHub の Secret と同じ値） |
| `SESSION_SECURE_COOKIE` | `true` |
| `SANCTUM_STATEFUL_DOMAINS` | 公開ドメイン（`https://` を付けない） |
| `DEMO_ACCOUNTS_LOCKED` | `true`（公開しているデモ用アカウントの役割・状態・パスワードを変えられないようにする） |
| `PORT` | `8080` |

`APP_ENV=production`、`APP_DEBUG=false`、`LOG_CHANNEL=stderr` は `Dockerfile` で設定済みです。`RAWG_API_KEY` は取得を行わないため不要です。

注意：変数を足したり変えたりしただけでは、アプリに届きません。staged changes から適用（Deploy）して起動し直し、変えた設定が効いているかを画面で確かめます（master-studio で、適用し忘れて設定が効いていなかったことがある）。

### 起動時の処理（`docker/start.sh`）

1. `config:cache` / `route:cache`
2. `migrate --force`
3. `app:seed-if-empty`：ユーザーが1人もいないときだけ、ログイン用のアカウント（member と admin）を作る
4. Web サーバー（FrankenPHP）の起動

どこかで失敗すると Web サーバーまで進まず、Healthcheck failure になります。「View logs」→「Deploy Logs」で原因を確かめます。

### ON にする

アプリのサービスを選んだ状態で `Ctrl + K` →「**Deploy Latest Commit**」。`/api/health` が `{"status":"ok","database":"ok"}` を返せば完了です。DB は Supabase で常に動いているため、DB を先に起動する手順はありません。

「Redeploy」は使いません。Redeploy は選んだデプロイと同じコミットを起動し直すため、一覧に残っている古いデプロイから実行すると、修正前のコードが起動します（master-studio で実際に起きた）。

### OFF にする

ACTIVE のデプロイの「⋮」→「Remove deployment」。設定と環境変数は残ります。データは Supabase にあるため、止めている間も取得は続きます。

### コードを更新したとき

main に push し、CI が通ったのを確かめてから「Deploy Latest Commit」。

本番へ出す前に、Git に入っているファイルだけでイメージを作り、起動できるかを確かめるとよいです。手元にしかないファイル（空のフォルダなど）は Railway のビルドに含まれないためです。

```bash
cd ~/game-trend-dashboard && git ls-files -z | tar --null -T - -c | docker build -t game-trend-dashboard:check -
```

## 料金

- Supabase・GitHub Actions・cron-job.org：無料
- Railway：動かしている時間の分だけ。Hobby プランでは、ワークスペースの使用料に上限（Hard limit）を設定しておく
