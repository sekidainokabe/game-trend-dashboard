import { Link } from 'react-router'
import { ChangeBadge, GameThumb, GenreChips, Sparkline } from '../components/GameParts'
import { formatNumber, formatPrice, formatReleaseDate } from '../lib/dashboard'
import { useToggleWatch, useWatchlist } from '../lib/watchlist'

/** ウォッチリスト：登録したゲームの今の人数と動きを、まとめて見る（ログインが必要） */
export function WatchlistPage() {
  const { data: games, isPending, isError } = useWatchlist()
  const toggle = useToggleWatch()

  return (
    <>
      <h1>ウォッチリスト</h1>
      <p className="lead">気になるゲームを登録しておくと、今の人数と7日間の動きをここでまとめて見られます。</p>
      {isPending && <p className="status">読み込み中…</p>}
      {isError && <p role="alert">ウォッチリストを取得できませんでした。</p>}
      {games && games.length === 0 && (
        <p className="status">
          まだ登録したゲームはありません。ゲームの詳細の「ウォッチリストに追加」から登録できます。<Link to="/ranking">ランキングを見る</Link>
        </p>
      )}
      {games && games.length > 0 && (
        <section className="panel" aria-label="登録したゲーム">
          <ul className="ranking">
            {games.map((g) => (
              <li key={g.id} className="ranking-row watch-row">
                <GameThumb src={g.image_url} name={g.name} />
                <span className="ranking-name">
                  <Link to={`/games/${g.id}`}>{g.name ?? '（名前を取得中）'}</Link>
                  <GenreChips genres={g.genres} />
                </span>
                <span className="ranking-players">
                  {g.coming_soon ? (
                    <span className="sub">{g.release_date ? `${formatReleaseDate(g.release_date)} 発売` : '発売日未定'}</span>
                  ) : (
                    <>
                      <span>{g.players === null ? '—' : <><strong>{formatNumber(g.players)}</strong>人</>}</span>
                      <ChangeBadge pct={g.change_pct} />
                    </>
                  )}
                  <span className="sub nowrap">
                    {formatPrice(g)}
                    {g.discount_percent > 0 && <span className="sale-badge">{g.discount_percent}%OFF</span>}
                  </span>
                </span>
                <Sparkline values={g.trend.map((t) => t.peak)} />
                <button
                  type="button"
                  className="secondary"
                  onClick={() => toggle.mutate({ game: g, watching: false })}
                  aria-label={`${g.name ?? 'このゲーム'}をウォッチリストから外す`}
                >
                  外す
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}
    </>
  )
}
