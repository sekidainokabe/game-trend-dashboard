import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { CartesianGrid, Legend, ResponsiveContainer, Scatter, ScatterChart, Tooltip, XAxis, YAxis, ZAxis } from 'recharts'
import { GameThumb } from '../components/GameParts'
import { formatCompact, formatNumber } from '../lib/dashboard'
import { CATEGORY_COLORS, hiddenGems, logTicks, OTHER_COLOR, useRatings, type RatedGame } from '../lib/insights'

const Y_AXES = [
  { key: 'metacritic', label: '評価（Metacritic）' },
  { key: 'recommendations', label: 'レビュー数' },
] as const
type YKey = (typeof Y_AXES)[number]['key']

/** 評価と人気：評価の高いゲームほど遊ばれているか、を散布図で見る */
export function RatingsPage() {
  const { data, isPending, isError } = useRatings()
  const [y, setY] = useState<YKey>('metacritic')

  return (
    <>
      <h1>評価と人気</h1>
      <p className="lead">
        横は今遊んでいる人数、縦は評価またはレビュー数です。右上ほど「評価が高く、よく遊ばれている」ゲームです。点を押すとゲームの詳細へ移ります。
      </p>
      {isPending && <p className="status">読み込み中…</p>}
      {isError && <p role="alert">データを取得できませんでした。</p>}
      {data && (
        <>
          <section className="panel" aria-labelledby="scatter-title">
            <div className="panel-head">
              <h2 id="scatter-title">今の人数 × {Y_AXES.find((a) => a.key === y)?.label}</h2>
              <div className="segmented" role="group" aria-label="縦の軸">
                {Y_AXES.map((a) => (
                  <button key={a.key} type="button" aria-pressed={y === a.key} onClick={() => setY(a.key)}>
                    {a.label}
                  </button>
                ))}
              </div>
            </div>
            <RatingScatter games={data.games} y={y} />
          </section>
          <HiddenGems games={data.games} />
        </>
      )}
    </>
  )
}

function RatingScatter({ games, y }: { games: RatedGame[]; y: YKey }) {
  const navigate = useNavigate()
  const points = games.filter((g) => g[y] !== null && g[y] > 0)

  // 本数の多い5ジャンルを色分けし、残りは「その他」にまとめる（色が多すぎると見分けられないため）
  const counts = new Map<string, { name: string; count: number }>()
  for (const g of points) {
    if (!g.genre_key || !g.genre) continue
    counts.set(g.genre_key, { name: g.genre, count: (counts.get(g.genre_key)?.count ?? 0) + 1 })
  }
  const groups = [...counts.entries()].sort((a, b) => b[1].count - a[1].count).slice(0, CATEGORY_COLORS.length)
  const keys = groups.map(([k]) => k)
  const series = [
    ...groups.map(([key, { name }], i) => ({ key, name, color: CATEGORY_COLORS[i], data: points.filter((g) => g.genre_key === key) })),
    { key: 'other', name: 'その他', color: OTHER_COLOR, data: points.filter((g) => !g.genre_key || !keys.includes(g.genre_key)) },
  ].filter((s) => s.data.length > 0)

  // 対数の軸は 10 倍ごとの目盛りにする（自動だと半端な値になるため）
  const xTicks = logTicks(points.map((g) => g.players))
  const yTicks = y === 'metacritic' ? [50, 60, 70, 80, 90, 100] : logTicks(points.map((g) => g.recommendations ?? 0))

  if (points.length === 0) return <p className="status">この軸の値があるゲームはまだありません。</p>

  return (
    <>
      <p className="sub">
        {points.length}本（値のないゲームは出していません）。人数{y === 'recommendations' && 'とレビュー数'}は差が大きいため、目盛りを10倍ごとにしています。
      </p>
      <div
        className="chart"
        role="img"
        aria-label={`今の人数と${y === 'metacritic' ? '評価' : 'レビュー数'}の散布図（${points.length}本）。それぞれの値はランキングの表で並べ替えて確かめられます`}
      >
        <ResponsiveContainer width="100%" height={420}>
          <ScatterChart margin={{ top: 8, right: 16, bottom: 24, left: 8 }}>
            <CartesianGrid stroke="var(--border)" />
            <XAxis
              type="number"
              dataKey="players"
              name="今の人数"
              scale="log"
              domain={[xTicks[0] ?? 1, xTicks.at(-1) ?? 10]}
              ticks={xTicks}
              tickFormatter={(v: number) => formatCompact(v)}
              tick={{ fill: 'var(--text)', fontSize: 12 }}
              label={{ value: '今遊んでいる人数', position: 'insideBottom', offset: -16, fill: 'var(--text)', fontSize: 12 }}
            />
            <YAxis
              type="number"
              dataKey={y}
              name={y === 'metacritic' ? '評価' : 'レビュー数'}
              scale={y === 'metacritic' ? 'linear' : 'log'}
              domain={[yTicks[0] ?? 1, yTicks.at(-1) ?? 10]}
              ticks={yTicks}
              tickFormatter={(v: number) => formatCompact(v)}
              tick={{ fill: 'var(--text)', fontSize: 12 }}
              width={56}
            />
            <ZAxis range={[60, 60]} />
            <Tooltip content={<ScatterTip y={y} />} cursor={{ strokeDasharray: '3 3' }} />
            <Legend verticalAlign="top" />
            {series.map((s) => (
              <Scatter
                key={s.key}
                name={s.name}
                data={s.data}
                fill={s.color}
                fillOpacity={0.8}
                isAnimationActive={false}
                cursor="pointer"
                onClick={(p) => navigate(`/games/${(p as unknown as RatedGame).id}`)}
              />
            ))}
          </ScatterChart>
        </ResponsiveContainer>
      </div>
    </>
  )
}

function ScatterTip({ active, payload, y }: { active?: boolean; payload?: { payload: RatedGame }[]; y: YKey }) {
  const g = payload?.[0]?.payload
  if (!active || !g) return null
  return (
    <div className="chart-tip">
      <strong>{g.name}</strong>
      <span>今の人数：{formatNumber(g.players)}人</span>
      {y === 'metacritic' ? <span>評価：{g.metacritic}</span> : <span>レビュー数：{formatNumber(g.recommendations ?? 0)}件</span>}
      {g.genre && <span>ジャンル：{g.genre}</span>}
    </div>
  )
}

function HiddenGems({ games }: { games: RatedGame[] }) {
  const gems = hiddenGems(games).slice(0, 8)
  return (
    <section className="panel" aria-labelledby="gems-title">
      <h2 id="gems-title">評価が高いのに、遊んでいる人が少ないゲーム</h2>
      <p className="sub">Metacritic 85点以上で、今の人数が評価のあるゲームの中央値より少ないもの</p>
      {gems.length === 0 ? (
        <p className="sub">当てはまるゲームはありません。</p>
      ) : (
        <ul className="compact-list">
          {gems.map((g) => (
            <li key={g.id}>
              <GameThumb src={g.image_url} name={g.name} />
              <Link to={`/games/${g.id}`}>{g.name}</Link>
              <span className="sub nowrap">
                {g.metacritic}点・{formatCompact(g.players)}人
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
