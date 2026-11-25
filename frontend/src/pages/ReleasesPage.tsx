import { Link, useSearchParams } from 'react-router'
import { Bar, BarChart, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { GameThumb, GenreChips } from '../components/GameParts'
import { formatPrice, formatReleaseDate } from '../lib/dashboard'
import { formatMonth, groupByDate, shiftMonth, useReleases, type ReleaseGame } from '../lib/insights'

/** 発売カレンダー：月ごとの発売予定。表示する月は URL に入れる */
export function ReleasesPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const monthParam = searchParams.get('month')
  const { data, isPending, isError } = useReleases(/^\d{4}-\d{2}$/.test(monthParam ?? '') ? monthParam : null)
  const month = data?.month
  const go = (m: string) => setSearchParams({ month: m })

  return (
    <>
      <h1>発売カレンダー</h1>
      <p className="lead">これから発売されるゲームを、月ごと・日ごとに並べています。棒を押すと、その月の一覧に移ります。</p>
      {isPending && <p className="status">読み込み中…</p>}
      {isError && <p role="alert">発売予定を取得できませんでした。</p>}
      {data && month && (
        <>
          <section className="panel" aria-labelledby="months-title">
            <h2 id="months-title">月ごとの発売本数</h2>
            <div
              className="chart"
              role="img"
              aria-label={`月ごとの発売本数の棒グラフ。${data.months.map((m) => `${formatMonth(m.month)} ${m.count}本`).join('、')}`}
            >
              <ResponsiveContainer width="100%" height={180}>
                <BarChart data={data.months} margin={{ top: 16, right: 8, bottom: 0, left: 0 }}>
                  <XAxis dataKey="month" tickFormatter={(m: string) => `${Number(m.slice(5))}月`} tick={{ fill: 'var(--text)', fontSize: 12 }} />
                  <YAxis allowDecimals={false} tick={{ fill: 'var(--text)', fontSize: 12 }} width={32} />
                  <Tooltip
                    labelFormatter={(m) => formatMonth(String(m))}
                    formatter={(v) => [`${v}本`, '発売予定']}
                    contentStyle={{ background: 'var(--bg)', border: '1px solid var(--border)' }}
                    cursor={{ fill: 'var(--surface)' }}
                  />
                  <Bar
                    dataKey="count"
                    radius={[4, 4, 0, 0]}
                    isAnimationActive={false}
                    cursor="pointer"
                    onClick={(d) => go((d as unknown as { month: string }).month)}
                    label={{ position: 'top', fill: 'var(--text)', fontSize: 12 }}
                  >
                    {data.months.map((m) => (
                      <Cell key={m.month} fill={m.month === month ? 'var(--accent)' : 'var(--cat-other)'} />
                    ))}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </div>
          </section>

          <section className="panel" aria-labelledby="month-title">
            <div className="panel-head">
              <h2 id="month-title">
                {formatMonth(month)}の発売予定（{data.games.length}本）
              </h2>
              <div className="button-row">
                <button type="button" className="secondary" onClick={() => go(shiftMonth(month, -1))}>
                  ← 前の月
                </button>
                <button type="button" className="secondary" onClick={() => go(shiftMonth(month, 1))}>
                  次の月 →
                </button>
              </div>
            </div>
            {data.games.length === 0 ? (
              <p className="sub">この月に発売されるゲームは、まだ見つかっていません。</p>
            ) : (
              <ol className="release-days">
                {groupByDate(data.games).map((day) => (
                  <li key={day.date}>
                    <h3 className="release-day">{formatReleaseDate(day.date)}</h3>
                    <ul className="release-cards">
                      {day.games.map((g) => (
                        <ReleaseCard key={g.id} game={g} />
                      ))}
                    </ul>
                  </li>
                ))}
              </ol>
            )}
          </section>

          {data.undated.length > 0 && (
            <section className="panel" aria-labelledby="undated-title">
              <h2 id="undated-title">発売日が未定のゲーム</h2>
              <p className="sub">Steam で「近日登場」となっていて、日付がまだ決まっていないもの</p>
              <ul className="release-cards">
                {data.undated.map((g) => (
                  <ReleaseCard key={g.id} game={g} />
                ))}
              </ul>
            </section>
          )}
        </>
      )}
    </>
  )
}

function ReleaseCard({ game }: { game: ReleaseGame }) {
  return (
    <li className="release-card">
      <GameThumb src={game.image_url} name={game.name} size="large" />
      <Link to={`/games/${game.id}`}>{game.name ?? '（名前を取得中）'}</Link>
      <GenreChips genres={game.genres} />
      <span className="sub">
        {[game.platforms.join('・'), game.price_jpy !== null || game.is_free ? formatPrice(game) : null].filter(Boolean).join('　')}
      </span>
    </li>
  )
}
