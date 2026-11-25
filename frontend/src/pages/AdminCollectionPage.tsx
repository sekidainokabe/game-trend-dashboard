import { useSearchParams } from 'react-router'
import {
  dataFreshness,
  formatDuration,
  RUN_STATUS_LABELS,
  useCollection,
  type FetchRun,
  type RunStatus,
} from '../lib/adminData'
import { useMe } from '../lib/auth'
import { formatUpdatedAt } from '../lib/dashboard'

const STATUSES = Object.keys(RUN_STATUS_LABELS) as RunStatus[]

/** 取得の状況（admin だけ）：毎時・毎日の取得が動いているか、失敗していないかを見る */
export function AdminCollectionPage() {
  const { data: me } = useMe()
  if (me && !me.can.manage_games) {
    return (
      <>
        <h1>取得の状況</h1>
        <p role="alert">この画面は、管理者だけが使えます。</p>
      </>
    )
  }
  return <CollectionBody />
}

function StatusBadge({ status }: { status: RunStatus }) {
  return <span className={`run-status run-${status}`}>{RUN_STATUS_LABELS[status]}</span>
}

function CollectionBody() {
  const [searchParams, setSearchParams] = useSearchParams()
  const job = searchParams.get('job') ?? undefined
  const status = (STATUSES.find((s) => s === searchParams.get('status')) ?? undefined) as RunStatus | undefined
  const page = Math.max(1, Number(searchParams.get('page')) || 1)
  const { data, isPending, isError } = useCollection({ job, status, page })

  const update = (changes: Record<string, string | null>) => {
    const next = new URLSearchParams(searchParams)
    for (const [k, v] of Object.entries(changes)) {
      if (v) next.set(k, v)
      else next.delete(k)
    }
    if (!('page' in changes)) next.delete('page')
    setSearchParams(next)
  }

  const labels = new Map(data?.jobs.map((j) => [j.job, j.label]))
  const freshness = data ? dataFreshness(data.latest_captured_at) : null

  return (
    <>
      <h1>取得の状況</h1>
      <p className="lead">外部の API からのデータ取得が、予定どおり動いているかを確かめる画面です。人数は毎時、それ以外は1日1回取得します。</p>
      {isPending && <p className="status">読み込み中…</p>}
      {isError && <p role="alert">取得の状況を読み込めませんでした。</p>}
      {data && (
        <>
          <p className={`freshness freshness-${freshness}`} role={freshness === 'stale' ? 'alert' : undefined}>
            {freshness === 'none' && 'まだ人数を1回も取得していません。'}
            {freshness === 'ok' && `最新の人数：${formatUpdatedAt(data.latest_captured_at!)}（予定どおり取得できています）`}
            {freshness === 'stale' &&
              `最新の人数が ${formatUpdatedAt(data.latest_captured_at!)} から更新されていません。毎時の取得が止まっていないか確かめてください。`}
          </p>

          <section className="kpis job-cards" aria-label="処理ごとの最後の結果">
            {data.jobs.map((j) => (
              <div key={j.job} className="kpi">
                <span className="kpi-label">{j.label}</span>
                {j.last ? (
                  <>
                    <StatusBadge status={j.last.status} />
                    <span className="sub">
                      {formatUpdatedAt(j.last.started_at)}・{j.last.succeeded}/{j.last.total}件・{formatDuration(j.last.duration_sec)}
                    </span>
                  </>
                ) : (
                  <span className="sub">まだ実行していません</span>
                )}
              </div>
            ))}
          </section>

          <div className="filters">
            <label className="filter-select">
              処理
              <select value={job ?? ''} onChange={(e) => update({ job: e.target.value })}>
                <option value="">すべて</option>
                {data.jobs.map((j) => (
                  <option key={j.job} value={j.job}>
                    {j.label}
                  </option>
                ))}
              </select>
            </label>
            <label className="filter-select">
              結果
              <select value={status ?? ''} onChange={(e) => update({ status: e.target.value })}>
                <option value="">すべて</option>
                {STATUSES.map((s) => (
                  <option key={s} value={s}>
                    {RUN_STATUS_LABELS[s]}
                  </option>
                ))}
              </select>
            </label>
            <span className="sub" role="status">
              {data.meta.total} 件
            </span>
          </div>

          <div className="table-scroll">
            <table className="data-table">
              <thead>
                <tr>
                  <th>開始</th>
                  <th>処理</th>
                  <th>結果</th>
                  <th className="num">成功／全体</th>
                  <th className="num">失敗</th>
                  <th className="num">かかった時間</th>
                  <th>メッセージ</th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((r: FetchRun) => (
                  <tr key={r.id}>
                    <td className="nowrap">{formatUpdatedAt(r.started_at)}</td>
                    <td className="nowrap">{labels.get(r.job) ?? r.job}</td>
                    <td>
                      <StatusBadge status={r.status} />
                    </td>
                    <td className="num">
                      {r.succeeded}/{r.total}
                    </td>
                    <td className="num">{r.failed}</td>
                    <td className="num nowrap">{formatDuration(r.duration_sec)}</td>
                    <td className="run-message">{r.message}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {data.data.length === 0 && <p className="sub">条件に合う実行の記録はありません。</p>}
          {data.meta.last_page > 1 && (
            <nav className="pagination" aria-label="ページ送り">
              <button type="button" className="secondary" disabled={page <= 1} onClick={() => update({ page: String(page - 1) })}>
                前へ
              </button>
              <span>
                {data.meta.current_page} / {data.meta.last_page} ページ
              </span>
              <button type="button" className="secondary" disabled={page >= data.meta.last_page} onClick={() => update({ page: String(page + 1) })}>
                次へ
              </button>
            </nav>
          )}
        </>
      )}
    </>
  )
}
