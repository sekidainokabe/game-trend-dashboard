import { Link } from 'react-router'
import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ChangeBadge } from '../components/GameParts'
import { formatCompact, formatNumber, formatPointLabel } from '../lib/dashboard'
import { CATEGORY_COLORS, useGenres, type GenreReport } from '../lib/insights'

const TREND_GENRES = 5

/** ジャンルの比較：どのジャンルがどれだけ遊ばれ、評価・価格はどうか */
export function GenresPage() {
  const { data, isPending, isError } = useGenres()

  return (
    <>
      <h1>ジャンルの比較</h1>
      <p className="lead">今遊んでいる人数をジャンルごとに合計し、本数・評価・価格・発売予定と並べて比べます。</p>
      {isPending && <p className="status">読み込み中…</p>}
      {isError && <p role="alert">ジャンルの集計を取得できませんでした。</p>}
      {data && data.genres.length === 0 && <p className="status">まだデータがありません。</p>}
      {data && data.genres.length > 0 && <GenresBody data={data} />}
    </>
  )
}

function GenresBody({ data }: { data: GenreReport }) {
  const top = data.genres.slice(0, TREND_GENRES)
  const trend = data.trend.map((d) => ({ date: d.date, ...d.values }))

  return (
    <>
      <section className="panel" aria-labelledby="genre-players-title">
        <h2 id="genre-players-title">ジャンル別の、今遊んでいる人数</h2>
        <p className="sub">1本のゲームが複数のジャンルに入るため、合計は全体の人数より多くなります。</p>
        <div className="chart">
          <ResponsiveContainer width="100%" height={Math.max(240, data.genres.length * 34)}>
            <BarChart data={data.genres} layout="vertical" margin={{ top: 8, right: 56, bottom: 8, left: 8 }}>
              <CartesianGrid stroke="var(--border)" horizontal={false} />
              <XAxis type="number" tickFormatter={(v: number) => formatCompact(v)} tick={{ fill: 'var(--text)', fontSize: 12 }} />
              <YAxis type="category" dataKey="name" width={110} tick={{ fill: 'var(--text-h)', fontSize: 13 }} />
              <Tooltip
                formatter={(v) => [`${formatNumber(Number(v))}人`, '人数']}
                contentStyle={{ background: 'var(--bg)', border: '1px solid var(--border)' }}
                cursor={{ fill: 'var(--surface)' }}
              />
              <Bar
                dataKey="total_players"
                fill="var(--accent)"
                radius={[0, 4, 4, 0]}
                isAnimationActive={false}
                label={{ position: 'right', fill: 'var(--text)', fontSize: 12, formatter: (v: unknown) => formatCompact(Number(v)) }}
              />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </section>

      <section className="panel" aria-labelledby="genre-table-title">
        <h2 id="genre-table-title">ジャンルごとの数字</h2>
        <div className="table-scroll">
          <table className="data-table">
            <thead>
              <tr>
                <th>ジャンル</th>
                <th className="num">本数</th>
                <th className="num">人数の合計</th>
                <th className="num">1本あたり</th>
                <th className="num">24時間の増減</th>
                <th className="num">平均の評価</th>
                <th className="num">平均の価格</th>
                <th className="num">基本無料の割合</th>
                <th className="num">発売予定</th>
              </tr>
            </thead>
            <tbody>
              {data.genres.map((g) => (
                <tr key={g.key}>
                  <td>
                    <Link to={`/ranking?genre=${g.key}`}>{g.name}</Link>
                  </td>
                  <td className="num">{g.games}</td>
                  <td className="num">{formatCompact(g.total_players)}</td>
                  <td className="num">{formatCompact(g.avg_players)}</td>
                  <td className="num">
                    <ChangeBadge pct={g.change_pct} />
                  </td>
                  <td className="num">
                    {g.avg_metacritic ?? '—'}
                    {g.rated_games > 0 && <span className="sub">（{g.rated_games}本）</span>}
                  </td>
                  <td className="num">{g.avg_price_jpy === null ? '—' : `¥${formatNumber(g.avg_price_jpy)}`}</td>
                  <td className="num">{Math.round(g.free_ratio * 100)}%</td>
                  <td className="num">{g.upcoming_games}本</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="sub">
          評価は Metacritic の点数がある本だけの平均。平均の価格は有料のゲームの定価（日本円）。ジャンル名を押すと、そのジャンルのランキングへ移ります。
        </p>
      </section>

      <section className="panel" aria-labelledby="genre-trend-title">
        <h2 id="genre-trend-title">人数の多い{top.length}ジャンルの推移（30日）</h2>
        <p className="sub">各ゲームのその日の最大人数を、ジャンルごとに足したもの（日本時間）</p>
        {trend.length < 2 ? (
          <p className="status">2日分以上のデータがたまると表示されます。</p>
        ) : (
          <div className="chart">
            <ResponsiveContainer width="100%" height={320}>
              <LineChart data={trend} margin={{ top: 8, right: 16, bottom: 8, left: 8 }}>
                <CartesianGrid stroke="var(--border)" vertical={false} />
                <XAxis dataKey="date" tickFormatter={(t: string) => formatPointLabel(t, 'day')} tick={{ fill: 'var(--text)', fontSize: 12 }} minTickGap={24} />
                <YAxis tickFormatter={(v: number) => formatCompact(v)} tick={{ fill: 'var(--text)', fontSize: 12 }} width={56} />
                <Tooltip
                  labelFormatter={(t) => formatPointLabel(String(t), 'day')}
                  formatter={(v, name) => [`${formatNumber(Number(v))}人`, top.find((g) => g.key === name)?.name ?? String(name)]}
                  contentStyle={{ background: 'var(--bg)', border: '1px solid var(--border)' }}
                  itemSorter={(item) => -Number(item.value)}
                />
                <Legend formatter={(key: string) => top.find((g) => g.key === key)?.name ?? key} />
                {top.map((g, i) => (
                  <Line key={g.key} type="monotone" dataKey={g.key} stroke={CATEGORY_COLORS[i]} strokeWidth={2} dot={false} isAnimationActive={false} />
                ))}
              </LineChart>
            </ResponsiveContainer>
          </div>
        )}
      </section>
    </>
  )
}
