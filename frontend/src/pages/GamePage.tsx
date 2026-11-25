import { useState } from 'react'
import { Link, useLocation, useParams } from 'react-router'
import { Area, CartesianGrid, ComposedChart, Line, ReferenceArea, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ChangeBadge, GameThumb, GenreChips } from '../components/GameParts'
import {
  formatCompact,
  formatNumber,
  formatPointLabel,
  formatPrice,
  describeSeries,
  RANGES,
  saleRanges,
  useGame,
  useGamePlayers,
  type GameDetail,
  type RangeKey,
} from '../lib/dashboard'
import { useMe } from '../lib/auth'
import { usePageTitleOverride } from '../lib/pageTitle'
import { useToggleWatch, useWatchlist } from '../lib/watchlist'

/** ゲームの詳細：人数の推移、価格とセール、評価 */
export function GamePage() {
  const id = Number(useParams().id)
  const { data: game, isPending, isError } = useGame(id)
  usePageTitleOverride(game?.name ?? undefined)

  if (isPending) return <p className="status">読み込み中…</p>
  if (isError || !game) {
    return (
      <p className="status" role="alert">
        ゲームが見つかりませんでした。<Link to="/">ホームへ戻る</Link>
      </p>
    )
  }

  return (
    <>
      <p>
        <Link to="/">← ホーム</Link>
      </p>
      <header className="game-hero">
        <GameThumb src={game.image_url} name={game.name} size="large" />
        <div>
          <h1>{game.name}</h1>
          <GenreChips genres={game.genres} />
          <p className="sub">
            {game.release_date && <>発売日：{game.release_date.replaceAll('-', '/')}　</>}
            {game.platforms.length > 0 && <>対応機種：{game.platforms.join('・')}</>}
          </p>
          <p className="links">
            {game.store_url && <a href={game.store_url}>Steam のストアで見る</a>}
            {game.rawg_url && <a href={game.rawg_url}>RAWG で見る</a>}
          </p>
          <WatchButton game={game} />
        </div>
      </header>

      <GameFacts game={game} />

      {game.coming_soon ? (
        <p className="status">発売前のため、遊んでいる人数はまだありません。</p>
      ) : (
        <PlayersChart id={game.id} />
      )}
    </>
  )
}

/** ウォッチリストへの登録・解除。ログインしていなければ、ログインへの案内を出す */
function WatchButton({ game }: { game: GameDetail }) {
  const location = useLocation()
  const { data: me } = useMe()
  const { data: watchlist } = useWatchlist()
  const toggle = useToggleWatch()

  if (me === undefined) return null
  if (me === null) {
    return (
      <p className="sub">
        <Link to="/login" state={{ from: location.pathname }}>
          ログイン
        </Link>
        すると、ウォッチリストに登録して動きを追えます。
      </p>
    )
  }

  const watching = watchlist?.some((g) => g.id === game.id) ?? false
  return (
    <p>
      <button
        type="button"
        className={watching ? undefined : 'secondary'}
        aria-pressed={watching}
        disabled={toggle.isPending || watchlist === undefined}
        onClick={() => toggle.mutate({ game, watching: !watching })}
      >
        {watching ? '★ ウォッチリストに登録済み' : '☆ ウォッチリストに追加'}
      </button>
      {toggle.isError && (
        <span role="alert" className="sub">
          {' '}
          登録できませんでした（登録できるのは50本までです）。
        </span>
      )}
    </p>
  )
}

function GameFacts({ game }: { game: GameDetail }) {
  return (
    <section className="kpis" aria-label="主な数字">
      <div className="kpi">
        <span className="kpi-label">今遊んでいる人数</span>
        <span className="kpi-value">{game.players === null ? '—' : `${formatNumber(game.players)}人`}</span>
        <ChangeBadge pct={game.change_pct} />
      </div>
      <div className="kpi">
        <span className="kpi-label">30日間の最高</span>
        <span className="kpi-value">{game.peak_30d === null ? '—' : `${formatCompact(game.peak_30d)}人`}</span>
      </div>
      <div className="kpi">
        <span className="kpi-label">価格</span>
        <span className="kpi-value">{formatPrice(game)}</span>
        {game.discount_percent > 0 && <span className="sale-badge">セール中 {game.discount_percent}%OFF</span>}
      </div>
      <div className="kpi">
        <span className="kpi-label">評価</span>
        <span className="kpi-value">{game.metacritic ?? '—'}</span>
        <span className="sub">
          Metacritic（100点満点）{game.rawg_rating !== null && ` ／ RAWG ${game.rawg_rating.toFixed(2)}（5点満点）`}
        </span>
      </div>
      <div className="kpi">
        <span className="kpi-label">Steam のレビュー数</span>
        <span className="kpi-value">{game.recommendations === null ? '—' : `${formatCompact(game.recommendations)}件`}</span>
      </div>
    </section>
  )
}

function PlayersChart({ id }: { id: number }) {
  const [range, setRange] = useState<RangeKey>('7d')
  const { data, isError } = useGamePlayers(id, range)
  const sales = data?.granularity === 'day' ? saleRanges(data.points) : []

  return (
    <section className="panel" aria-labelledby="chart-title">
      <div className="panel-head">
        <h2 id="chart-title">遊んでいる人数の推移</h2>
        <div className="segmented" role="group" aria-label="期間">
          {RANGES.map((r) => (
            <button key={r.key} type="button" aria-pressed={range === r.key} onClick={() => setRange(r.key)}>
              {r.label}
            </button>
          ))}
        </div>
      </div>
      <p className="sub">
        {data?.granularity === 'day'
          ? '1日ごとの最大人数（日本時間）。色の付いた期間はセール中'
          : '1時間ごとの人数（日本時間）'}
      </p>
      {isError && <p role="alert">推移を取得できませんでした。</p>}
      {data && data.points.length === 0 && <p className="status">この期間のデータはまだありません。</p>}
      {data && data.points.length > 0 && (
        <div
          className="chart"
          role="img"
          aria-label={`遊んでいる人数の推移のグラフ。${describeSeries(data.points, data.granularity)}${sales.length > 0 ? `。セールの期間が${sales.length}回あります` : ''}`}
        >
          <ResponsiveContainer width="100%" height={320}>
            <ComposedChart data={data.points} margin={{ top: 8, right: 16, bottom: 8, left: 8 }}>
              <CartesianGrid stroke="var(--border)" vertical={false} />
              <XAxis
                dataKey="t"
                tickFormatter={(t: string) => formatPointLabel(t, data.granularity)}
                tick={{ fill: 'var(--text)', fontSize: 12 }}
                minTickGap={24}
              />
              <YAxis tickFormatter={(v: number) => formatCompact(v)} tick={{ fill: 'var(--text)', fontSize: 12 }} width={56} />
              {sales.map((s) => (
                <ReferenceArea
                  key={s.from}
                  x1={s.from}
                  x2={s.to}
                  fill="var(--sale)"
                  fillOpacity={0.25}
                  label={{ value: `セール ${s.percent}%OFF`, position: 'insideTop', fill: 'var(--text-h)', fontSize: 12 }}
                />
              ))}
              <Tooltip
                labelFormatter={(t) => formatPointLabel(String(t), data.granularity)}
                formatter={(v, name) => [`${formatNumber(Number(v))}人`, name === 'avg' ? '平均' : data.granularity === 'day' ? '最大' : '人数']}
                contentStyle={{ background: 'var(--bg)', border: '1px solid var(--border)' }}
              />
              {data.granularity === 'day' && (
                <Area type="monotone" dataKey="avg" stroke="none" fill="var(--accent)" fillOpacity={0.12} isAnimationActive={false} />
              )}
              <Line type="monotone" dataKey="players" stroke="var(--accent)" strokeWidth={2} dot={false} isAnimationActive={false} />
            </ComposedChart>
          </ResponsiveContainer>
        </div>
      )}
    </section>
  )
}
