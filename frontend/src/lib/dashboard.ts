import { useQuery } from '@tanstack/react-query'
import { apiFetch } from './api'

export type RankedGame = {
  id: number
  name: string | null
  image_url: string | null
  players: number
  /** 24時間前からの増減（%）。24時間前のデータがなければ null */
  change_pct: number | null
}

export type TopGame = RankedGame & {
  genres: string[]
  /** 直近7日の、日ごとの最大人数（小さなグラフ用） */
  trend: { date: string; peak: number | null }[]
}

export type UpcomingGame = {
  id: number
  name: string | null
  image_url: string | null
  release_date: string
  platforms: string[]
  genres: string[]
}

export type Dashboard = {
  /** 最新の取得時刻。まだ1回も取っていなければ null */
  updated_at: string | null
  total_players: number
  tracked_games: number
  top: TopGame[]
  rising: RankedGame[]
  upcoming: UpcomingGame[]
}

export type GameDetail = {
  id: number
  name: string | null
  image_url: string | null
  store_url: string | null
  rawg_url: string | null
  release_date: string | null
  coming_soon: boolean
  is_free: boolean
  price_jpy: number | null
  discount_percent: number
  metacritic: number | null
  rawg_rating: number | null
  recommendations: number | null
  platforms: string[]
  genres: string[]
  players: number | null
  change_pct: number | null
  peak_30d: number | null
}

export const RANGES = [
  { key: '24h', label: '24時間' },
  { key: '7d', label: '7日' },
  { key: '30d', label: '30日' },
  { key: '90d', label: '90日' },
] as const

export type RangeKey = (typeof RANGES)[number]['key']

export type PlayerPoint = {
  /** 毎時なら ISO 8601 の日時、1日ごとなら日付（YYYY-MM-DD、日本時間） */
  t: string
  players: number | null
  avg?: number | null
  price_jpy?: number | null
  discount_percent?: number | null
}

export type PlayerSeries = {
  range: RangeKey
  granularity: 'hour' | 'day'
  points: PlayerPoint[]
}

// データは毎時しか変わらないので、5分間は取り直さない
const STALE_TIME = 5 * 60 * 1000

export function useDashboard() {
  return useQuery({ queryKey: ['dashboard'], queryFn: () => apiFetch<Dashboard>('/api/dashboard'), staleTime: STALE_TIME })
}

export function useGame(id: number) {
  return useQuery({
    queryKey: ['games', id],
    queryFn: async () => (await apiFetch<{ data: GameDetail }>(`/api/games/${id}`)).data,
    staleTime: STALE_TIME,
  })
}

export function useGamePlayers(id: number, range: RangeKey) {
  return useQuery({
    queryKey: ['games', id, 'players', range],
    queryFn: () => apiFetch<PlayerSeries>(`/api/games/${id}/players?range=${range}`),
    staleTime: STALE_TIME,
    // 期間を切り替えるあいだ、前のグラフを出したままにする（ちらつかないように）
    placeholderData: (previous) => previous,
  })
}

const compact = new Intl.NumberFormat('ja-JP', { notation: 'compact', maximumFractionDigits: 1 })
const plain = new Intl.NumberFormat('ja-JP')

/** 大きな数を「348万」のように短くする（1万未満はそのまま「9,800」） */
export function formatCompact(value: number): string {
  return value < 10000 ? plain.format(value) : compact.format(value)
}

export function formatNumber(value: number): string {
  return plain.format(value)
}

/** 増減率を「+12.5%」「-3%」の形にする。比べる値がなければ「—」 */
export function formatChange(pct: number | null): string {
  if (pct === null) return '—'
  return `${pct > 0 ? '+' : ''}${pct}%`
}

export function changeDirection(pct: number | null): 'up' | 'down' | 'flat' {
  if (pct === null || pct === 0) return 'flat'
  return pct > 0 ? 'up' : 'down'
}

export function formatPrice(game: Pick<GameDetail, 'is_free' | 'price_jpy'>): string {
  if (game.is_free) return '基本無料'
  if (game.price_jpy === null) return '—'
  return `¥${plain.format(game.price_jpy)}`
}

const timeFormat = new Intl.DateTimeFormat('ja-JP', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Tokyo' })
const hourFormat = new Intl.DateTimeFormat('ja-JP', { month: 'numeric', day: 'numeric', hour: 'numeric', timeZone: 'Asia/Tokyo' })

/** 「11/24 17:00」（日本時間） */
export function formatUpdatedAt(iso: string): string {
  return timeFormat.format(new Date(iso))
}

/** グラフの横軸。毎時は「11/24 17時」、1日ごとは「11/24」 */
export function formatPointLabel(t: string, granularity: 'hour' | 'day'): string {
  if (granularity === 'hour') return hourFormat.format(new Date(t))
  const [, m, d] = t.split('-')
  return `${Number(m)}/${Number(d)}`
}

/** 発売日を「12/1（火）」の形にする */
export function formatReleaseDate(date: string): string {
  const [y, m, d] = date.split('-').map(Number)
  const weekday = '日月火水木金土'[new Date(y, m - 1, d).getDay()]
  return `${m}/${d}（${weekday}）`
}

/**
 * 割引していた日をつなげて期間にする（グラフにセールの期間を重ねるため）。
 * 例：11/1〜11/7 が 50% 引きなら { from: '2026-11-01', to: '2026-11-07', percent: 50 }
 */
export function saleRanges(points: PlayerPoint[]): { from: string; to: string; percent: number }[] {
  const ranges: { from: string; to: string; percent: number }[] = []
  for (const p of points) {
    const percent = p.discount_percent ?? 0
    const last = ranges.at(-1)
    const prev = points[points.indexOf(p) - 1]
    if (percent > 0 && last && prev && last.to === prev.t && last.percent === percent) {
      last.to = p.t
    } else if (percent > 0) {
      ranges.push({ from: p.t, to: p.t, percent })
    }
  }
  return ranges
}
