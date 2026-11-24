import { Link } from 'react-router'
import { ChangeBadge, GameThumb, GenreChips, Sparkline } from '../components/GameParts'
import {
  formatCompact,
  formatNumber,
  formatReleaseDate,
  formatUpdatedAt,
  useDashboard,
  type Dashboard,
} from '../lib/dashboard'

/** ホーム：今遊ばれているゲームの全体像（ログインなしで見られる） */
export function HomePage() {
  const { data, isPending, isError } = useDashboard()

  return (
    <>
      <header className="hero">
        <h1>いま Steam で遊ばれているゲーム</h1>
        <p className="lead">
          人気上位のゲームの「今遊んでいる人数」を毎時集め、人気の動き・ジャンル・評価・発売予定をまとめて見られるようにしています。
        </p>
      </header>

      {isPending && <p className="status">読み込み中…</p>}
      {isError && (
        <p className="status" role="alert">
          データを取得できませんでした。時間をおいて開き直してください。
        </p>
      )}
      {data && <DashboardBody data={data} />}
    </>
  )
}

function DashboardBody({ data }: { data: Dashboard }) {
  if (data.updated_at === null) {
    return <p className="status">まだデータがありません。最初の取得が終わると表示されます。</p>
  }

  return (
    <>
      <section className="kpis" aria-label="全体の数字">
        <div className="kpi">
          <span className="kpi-label">今遊んでいる人数（合計）</span>
          <span className="kpi-value">{formatCompact(data.total_players)}人</span>
          <span className="sub">追跡中のゲームの合計</span>
        </div>
        <div className="kpi">
          <span className="kpi-label">追跡中のゲーム</span>
          <span className="kpi-value">{data.tracked_games}本</span>
          <span className="sub">Steam の人気上位から</span>
        </div>
        <div className="kpi">
          <span className="kpi-label">最終更新</span>
          <span className="kpi-value">{formatUpdatedAt(data.updated_at)}</span>
          <span className="sub">1時間ごとに更新</span>
        </div>
      </section>

      <div className="dashboard-grid">
        <section className="panel" aria-labelledby="ranking-title">
          <h2 id="ranking-title">人気ランキング</h2>
          <p className="sub">今遊んでいる人数の多い順。増減は24時間前との比較、グラフは直近7日の最大人数</p>
          <ol className="ranking">
            {data.top.map((game, i) => (
              <li key={game.id} className="ranking-row">
                <span className="rank">{i + 1}</span>
                <GameThumb src={game.image_url} name={game.name} />
                <span className="ranking-name">
                  <Link to={`/games/${game.id}`}>{game.name ?? '（名前を取得中）'}</Link>
                  <GenreChips genres={game.genres} />
                </span>
                <span className="ranking-players">
                  <span>
                    <strong>{formatNumber(game.players)}</strong>人
                  </span>
                  <ChangeBadge pct={game.change_pct} />
                </span>
                <Sparkline values={game.trend.map((t) => t.peak)} />
              </li>
            ))}
          </ol>
        </section>

        <div className="side-column">
          <section className="panel" aria-labelledby="rising-title">
            <h2 id="rising-title">急上昇</h2>
            <p className="sub">24時間前より人数が増えた割合の大きい順（1,000人以上のゲーム）</p>
            {data.rising.length === 0 ? (
              <p className="sub">24時間分のデータがたまると表示されます。</p>
            ) : (
              <ul className="compact-list">
                {data.rising.map((game) => (
                  <li key={game.id}>
                    <GameThumb src={game.image_url} name={game.name} />
                    <Link to={`/games/${game.id}`}>{game.name}</Link>
                    <ChangeBadge pct={game.change_pct} />
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="panel" aria-labelledby="upcoming-title">
            <h2 id="upcoming-title">近日発売</h2>
            <p className="sub">30日以内に発売されるゲーム</p>
            {data.upcoming.length === 0 ? (
              <p className="sub">30日以内に発売されるゲームはありません。</p>
            ) : (
              <ul className="upcoming-list">
                {data.upcoming.map((game) => (
                  <li key={game.id}>
                    <GameThumb src={game.image_url} name={game.name} />
                    <span className="upcoming-text">
                      <span className="release-date">{formatReleaseDate(game.release_date)}</span>
                      <Link to={`/games/${game.id}`}>{game.name}</Link>
                      <span className="sub">{game.platforms.join('・')}</span>
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      </div>
    </>
  )
}
