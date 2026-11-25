import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { DemoReadOnlyNotice } from '../components/DemoReadOnlyNotice'
import { GameThumb } from '../components/GameParts'
import { GAME_STATE_LABELS, useAdminGames, useSetExcluded, type AdminGame, type GameState } from '../lib/adminData'
import { useMe } from '../lib/auth'
import { formatNumber, formatUpdatedAt } from '../lib/dashboard'

const STATES = Object.keys(GAME_STATE_LABELS) as GameState[]

/** ゲームの管理（admin だけ）：ゲームではないソフトなどを集計から外す・戻す */
export function AdminGamesPage() {
  const { data: me } = useMe()
  if (me && !me.can.manage_games) {
    return (
      <>
        <h1>ゲームの管理</h1>
        <p role="alert">この画面は、管理者だけが使えます。</p>
      </>
    )
  }
  return <GamesBody canUpdate={me?.can.update_games ?? false} />
}

function GamesBody({ canUpdate }: { canUpdate: boolean }) {
  const [searchParams, setSearchParams] = useSearchParams()
  const q = searchParams.get('q') ?? ''
  const state = STATES.find((s) => s === searchParams.get('state'))
  const page = Math.max(1, Number(searchParams.get('page')) || 1)
  const games = useAdminGames({ q, state, page })
  const setExcluded = useSetExcluded()
  const [draft, setDraft] = useState(q)
  const meta = games.data?.meta

  const update = (changes: Record<string, string | null>) => {
    const next = new URLSearchParams(searchParams)
    for (const [k, v] of Object.entries(changes)) {
      if (v) next.set(k, v)
      else next.delete(k)
    }
    if (!('page' in changes)) next.delete('page')
    setSearchParams(next)
  }

  const search = (e: FormEvent) => {
    e.preventDefault()
    update({ q: draft.trim() })
  }

  const toggle = (g: AdminGame) => {
    const excluding = g.state !== 'excluded'
    const message = excluding
      ? `「${g.name}」を集計から外します。人数の取得をやめ、ランキングなどにも出なくなります。よろしいですか？`
      : `「${g.name}」を集計に戻します。次の毎時の取得から、また人数を取ります。よろしいですか？`
    if (window.confirm(message)) {
      setExcluded.mutate({ id: g.id, excluded: excluding })
    }
  }

  return (
    <>
      <h1>ゲームの管理</h1>
      <p className="lead">
        Steam の人気上位には、ゲームではないソフト（壁紙アプリなど）も入ります。集計から外すと、人数の取得をやめ、ランキングや集計にも出なくなります。操作は操作履歴に残ります。
      </p>
      {!canUpdate && <DemoReadOnlyNotice />}

      <div className="filters">
        <form className="filter-select" onSubmit={search} role="search">
          <label htmlFor="game-q">名前</label>
          <input id="game-q" type="search" value={draft} onChange={(e) => setDraft(e.target.value)} />
          <button type="submit" className="secondary">
            探す
          </button>
        </form>
        <label className="filter-select">
          状態
          <select value={state ?? ''} onChange={(e) => update({ state: e.target.value })}>
            <option value="">すべて</option>
            {STATES.map((s) => (
              <option key={s} value={s}>
                {GAME_STATE_LABELS[s]}
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

      {setExcluded.isError && <p role="alert">変更できませんでした。もう一度お試しください。</p>}
      {games.isPending && <p className="status">読み込み中…</p>}
      {games.isError && <p role="alert">ゲームの一覧を取得できませんでした。</p>}
      {games.data && (
        <>
          <div className="table-scroll">
            <table className="data-table ranking-table">
              <thead>
                <tr>
                  <th>ゲーム</th>
                  <th className="num">今の人数</th>
                  <th>状態</th>
                  <th>詳細の取得</th>
                  <th>取得元</th>
                  {canUpdate && <th>操作</th>}
                </tr>
              </thead>
              <tbody>
                {games.data.data.map((g) => (
                  <tr key={g.id}>
                    <td>
                      <span className="table-game">
                        <GameThumb src={g.image_url} name={g.name} />
                        <Link to={`/games/${g.id}`}>{g.name ?? '（名前を取得中）'}</Link>
                      </span>
                    </td>
                    <td className="num">{g.players === null ? '—' : formatNumber(g.players)}</td>
                    <td className="nowrap">
                      <span className={`game-state state-${g.state}`}>{GAME_STATE_LABELS[g.state]}</span>
                      {g.coming_soon && <span className="sub">（発売前）</span>}
                    </td>
                    <td className="nowrap">{g.details_fetched_at ? formatUpdatedAt(g.details_fetched_at) : '—'}</td>
                    <td className="nowrap">{[g.steam_appid && 'Steam', g.rawg_id && 'RAWG'].filter(Boolean).join('・')}</td>
                    {canUpdate && (
                      <td>
                        <button
                          type="button"
                          className={g.state === 'excluded' ? 'secondary' : 'danger'}
                          disabled={setExcluded.isPending}
                          onClick={() => toggle(g)}
                        >
                          {g.state === 'excluded' ? '集計に戻す' : '集計から外す'}
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {games.data.data.length === 0 && <p className="sub">条件に合うゲームはありません。</p>}
          <p className="sub">「自動で停止」は、日本の Steam ストアで販売されていないなどの理由で、取得処理が対象から外したゲームです。</p>
          {meta && meta.last_page > 1 && (
            <nav className="pagination" aria-label="ページ送り">
              <button type="button" className="secondary" disabled={page <= 1} onClick={() => update({ page: String(page - 1) })}>
                前へ
              </button>
              <span>
                {meta.current_page} / {meta.last_page} ページ
              </span>
              <button type="button" className="secondary" disabled={page >= meta.last_page} onClick={() => update({ page: String(page + 1) })}>
                次へ
              </button>
            </nav>
          )}
        </>
      )}
    </>
  )
}
