import { Link, useSearchParams } from 'react-router'
import { ChangeBadge, GameThumb, GenreChips } from '../components/GameParts'
import { formatCompact, formatNumber, formatPrice } from '../lib/dashboard'
import { SORT_KEYS, useGenres, useRanking, type RankingQuery, type SortKey } from '../lib/insights'

const COLUMNS: { key: SortKey; label: string; firstOrder: 'asc' | 'desc' }[] = [
  { key: 'players', label: '今の人数', firstOrder: 'desc' },
  { key: 'change', label: '24時間の増減', firstOrder: 'desc' },
  { key: 'metacritic', label: '評価', firstOrder: 'desc' },
  { key: 'recommendations', label: 'レビュー数', firstOrder: 'desc' },
  { key: 'price', label: '価格', firstOrder: 'asc' },
  { key: 'release', label: '発売日', firstOrder: 'desc' },
]

const PRICES = [
  { key: 'free', label: '基本無料' },
  { key: 'paid', label: '有料' },
  { key: 'sale', label: 'セール中' },
] as const

/** ランキング表：並べ替えと絞り込み。条件は URL に入れる（共有・戻る操作で同じ表が出るように） */
export function RankingPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const sortParam = searchParams.get('sort') as SortKey | null
  const query: RankingQuery = {
    sort: sortParam && SORT_KEYS.includes(sortParam) ? sortParam : 'players',
    order: searchParams.get('order') === 'asc' ? 'asc' : 'desc',
    genre: searchParams.get('genre'),
    price: (PRICES.find((p) => p.key === searchParams.get('price'))?.key ?? null),
    page: Math.max(1, Number(searchParams.get('page')) || 1),
  }
  const ranking = useRanking(query)
  const genres = useGenres()
  const meta = ranking.data?.meta

  const update = (changes: Record<string, string | null>) => {
    const next = new URLSearchParams(searchParams)
    for (const [k, v] of Object.entries(changes)) {
      if (v) next.set(k, v)
      else next.delete(k)
    }
    // 条件を変えたら1ページ目へ戻す
    if (!('page' in changes)) next.delete('page')
    setSearchParams(next)
  }

  const sortBy = (col: (typeof COLUMNS)[number]) => {
    // 同じ列をもう一度押すと、昇順と降順が入れ替わる
    const order = query.sort === col.key ? (query.order === 'desc' ? 'asc' : 'desc') : col.firstOrder
    update({ sort: col.key, order })
  }

  const offset = (query.page - 1) * 50

  return (
    <>
      <h1>ランキング</h1>
      <p className="lead">今遊ばれているゲームを、人数・増減・評価・価格などで並べ替えられます。列の名前を押すと並べ替わります。</p>

      <div className="filters">
        <label className="filter-select">
          ジャンル
          <select value={query.genre ?? ''} onChange={(e) => update({ genre: e.target.value })}>
            <option value="">すべて</option>
            {genres.data?.genres.map((g) => (
              <option key={g.key} value={g.key}>
                {g.name}（{g.games}本）
              </option>
            ))}
          </select>
        </label>
        <label className="filter-select">
          価格
          <select value={query.price ?? ''} onChange={(e) => update({ price: e.target.value })}>
            <option value="">すべて</option>
            {PRICES.map((p) => (
              <option key={p.key} value={p.key}>
                {p.label}
              </option>
            ))}
          </select>
        </label>
        {meta && (
          <span className="sub" role="status">
            {meta.total} 本
          </span>
        )}
      </div>

      {ranking.isPending && <p className="status">読み込み中…</p>}
      {ranking.isError && <p role="alert">ランキングを取得できませんでした。</p>}
      {ranking.data && (
        <>
          <div className="table-scroll">
            <table className="data-table ranking-table">
              <thead>
                <tr>
                  <th className="num">順位</th>
                  <th>ゲーム</th>
                  {COLUMNS.map((col) => (
                    <th
                      key={col.key}
                      className="num"
                      aria-sort={query.sort === col.key ? (query.order === 'asc' ? 'ascending' : 'descending') : undefined}
                    >
                      <button type="button" className="sort-button" onClick={() => sortBy(col)}>
                        {col.label}
                        <span aria-hidden="true">{query.sort === col.key ? (query.order === 'asc' ? ' ▲' : ' ▼') : ' ⇅'}</span>
                      </button>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {ranking.data.data.map((g, i) => (
                  <tr key={g.id}>
                    <td className="num">{offset + i + 1}</td>
                    <td>
                      <span className="table-game">
                        <GameThumb src={g.image_url} name={g.name} />
                        <span>
                          <Link to={`/games/${g.id}`}>{g.name ?? '（名前を取得中）'}</Link>
                          <GenreChips genres={g.genres} />
                        </span>
                      </span>
                    </td>
                    <td className="num">{formatNumber(g.players)}</td>
                    <td className="num">
                      <ChangeBadge pct={g.change_pct} />
                    </td>
                    <td className="num">{g.metacritic ?? '—'}</td>
                    <td className="num">{g.recommendations === null ? '—' : formatCompact(g.recommendations)}</td>
                    <td className="num nowrap">
                      {formatPrice(g)}
                      {g.discount_percent > 0 && <span className="sale-badge">{g.discount_percent}%OFF</span>}
                    </td>
                    <td className="num nowrap">{g.release_date?.replaceAll('-', '/') ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {ranking.data.data.length === 0 && <p className="sub">条件に合うゲームはありません。</p>}
          <p className="sub">評価は Metacritic（100点満点）。値のないゲームは、並べ替えると最後に並びます。</p>
          {meta && meta.last_page > 1 && (
            <nav className="pagination" aria-label="ページ送り">
              <button type="button" className="secondary" disabled={query.page <= 1} onClick={() => update({ page: String(query.page - 1) })}>
                前へ
              </button>
              <span>
                {meta.current_page} / {meta.last_page} ページ
              </span>
              <button
                type="button"
                className="secondary"
                disabled={query.page >= meta.last_page}
                onClick={() => update({ page: String(query.page + 1) })}
              >
                次へ
              </button>
            </nav>
          )}
        </>
      )}
    </>
  )
}
