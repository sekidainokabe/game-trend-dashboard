# game-trend-dashboard

Steam で今遊ばれているゲームの人数・価格・評価・発売予定を毎時集め、グラフで見られる社内向けダッシュボード。転職用ポートフォリオとして作成している。計画は @docs/plan.md を参照。

## このプロジェクトの目的

- HAL研究所「コーポレートウェブシステムエンジニア」への応募用ポートフォリオ（応募目標：2026年12月20日）
- 必須要件：HTML / CSS / TypeScript / React / PHP / SQL、フロントエンドとサーバーサイド両方の開発
- 歓迎要件：データベース設計、利用者100名以上のサービスの設計・実装
- 画面を開いて10秒で何のシステムか分かること（README を読まなくても分かること）を最優先にする
- 前作 master-studio（非公開）は、題材と画面が分かりにくかったため作り直した

## 開発者について

- ECモール運営代行会社の社内SE。Python / GAS / JavaScript / HTML / CSS / スプレッドシート / CSV / Supabase に精通
- TypeScript / React / PHP / Laravel / SQL は学習しながら進めている
- 面接で自分の言葉で説明できる必要があるため、各ステップの終わりに要点を3行で説明する

## 作業ルール

- 日程は決めず最速で進める。承認済みの計画の範囲内なら、ステップごとの確認は「次へ」程度でよい
- 計画にない変更をするときは、先に提示して承認を得る。範囲外の問題に気づいたら報告だけする
- 説明は簡潔に、文章で
- 実在のゲームの名前・数値・API が返す画像の URL は、出典（Steam、RAWG）を示して表示してよい。画像は自分のサーバーに保存しない。実在のキャラクターやロゴを自作の素材として使うことはしない
- API キーなどの秘密の値はチャットに貼らない。`.env` と GitHub / Railway の Secrets に本人が入れる
- コミットは意味のある単位で、メッセージは日本語でよい

## 環境

- Windows + WSL2（Ubuntu）。プロジェクトは `~/game-trend-dashboard`
- Docker Desktop（WSL2 統合を有効化）

## 技術構成

- モノレポ：`backend/`（Laravel、Sail、PostgreSQL、Sanctum）、`frontend/`（Vite、React、TypeScript、React Router、TanStack Query、Recharts）、`docs/`
- 本番：DB は Supabase（無料枠）、毎時の取得は GitHub Actions、画面と API は Railway（必要なときだけ起動）
