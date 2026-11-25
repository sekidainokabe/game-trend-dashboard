import { useQuery } from '@tanstack/react-query'
import { apiFetch, type Paginated } from './api'

// データは毎時しか変わらないので、5分間は取り直さない
const STALE_TIME = 5 * 60 * 1000

/* ---------- ランキング表 ---------- */

export type RankingRow = {
  id: number
  name: string | null
  image_url: string | null
  players: number
  change_pct: number | null
  is_free: boolean
  price_jpy: number | null
  discount_percent: number
  metacritic: number | null
  rawg_rating: number | null
  recommendations: number | null
  release_date: string | null
  genres: string[]
}

export const SORT_KEYS = ['players', 'change', 'metacritic', 'recommendations', 'price', 'release'] as const
export type SortKey = (typeof SORT_KEYS)[number]

export type RankingQuery = {
  sort: SortKey
  order: 'asc' | 'desc'
  genre: string | null
  price: 'free' | 'paid' | 'sale' | null
  page: number
}

export function useRanking(q: RankingQuery) {
  const params = new URLSearchParams({ sort: q.sort, order: q.order, page: String(q.page) })
  if (q.genre) params.set('genre', q.genre)
  if (q.price) params.set('price', q.price)

  return useQuery({
    queryKey: ['ranking', q],
    queryFn: () => apiFetch<Paginated<RankingRow>>(`/api/ranking?${params}`),
    staleTime: STALE_TIME,
    // 並べ替えや絞り込みを変えるあいだ、前の表を出したままにする
    placeholderData: (previous) => previous,
  })
}

/* ---------- ジャンルの比較 ---------- */

export type GenreStat = {
  key: string
  name: string
  games: number
  total_players: number
  avg_players: number
  change_pct: number | null
  avg_metacritic: number | null
  rated_games: number
  avg_price_jpy: number | null
  free_ratio: number
  upcoming_games: number
}

export type GenreReport = {
  updated_at: string | null
  genres: GenreStat[]
  trend: { date: string; values: Record<string, number> }[]
}

export function useGenres() {
  return useQuery({ queryKey: ['genres'], queryFn: () => apiFetch<GenreReport>('/api/genres'), staleTime: STALE_TIME })
}

/* ---------- 評価と人気 ---------- */

export type RatedGame = {
  id: number
  name: string | null
  image_url: string | null
  players: number
  metacritic: number | null
  recommendations: number | null
  is_free: boolean
  price_jpy: number | null
  genre_key: string | null
  genre: string | null
}

export function useRatings() {
  return useQuery({
    queryKey: ['ratings'],
    queryFn: () => apiFetch<{ games: RatedGame[] }>('/api/ratings'),
    staleTime: STALE_TIME,
  })
}

/** 中央値（散布図の「遊ばれている／いない」の境目に使う） */
export function median(values: number[]): number | null {
  if (values.length === 0) return null
  const sorted = [...values].sort((a, b) => a - b)
  const mid = Math.floor(sorted.length / 2)
  return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2
}

/**
 * 評価が高いのに、今遊んでいる人が少ないゲーム（「隠れた名作」の候補）。
 * Metacritic が基準以上で、人数が評価のあるゲームの中央値より少ないもの
 */
export function hiddenGems(games: RatedGame[], minScore = 85): RatedGame[] {
  const rated = games.filter((g) => g.metacritic !== null)
  const mid = median(rated.map((g) => g.players))
  if (mid === null) return []
  return rated
    .filter((g) => (g.metacritic ?? 0) >= minScore && g.players < mid)
    .sort((a, b) => (b.metacritic ?? 0) - (a.metacritic ?? 0) || b.players - a.players)
}

/** 対数の軸の目盛り（1, 10, 100, 1000…）。値の範囲を挟む分だけ返す */
export function logTicks(values: number[]): number[] {
  const positive = values.filter((v) => v > 0)
  if (positive.length === 0) return []
  const lo = Math.floor(Math.log10(Math.min(...positive)))
  const hi = Math.ceil(Math.log10(Math.max(...positive)))
  return Array.from({ length: hi - lo + 1 }, (_, i) => 10 ** (lo + i))
}

/* ---------- 発売カレンダー ---------- */

export type ReleaseGame = {
  id: number
  name: string | null
  image_url: string | null
  release_date: string | null
  platforms: string[]
  is_free: boolean
  price_jpy: number | null
  genres: string[]
}

export type ReleaseReport = {
  month: string
  games: ReleaseGame[]
  months: { month: string; count: number }[]
  undated: ReleaseGame[]
}

export function useReleases(month: string | null) {
  return useQuery({
    queryKey: ['releases', month],
    queryFn: () => apiFetch<ReleaseReport>(`/api/releases${month ? `?month=${month}` : ''}`),
    staleTime: STALE_TIME,
    placeholderData: (previous) => previous,
  })
}

/** 「2026-12」→「2026年12月」 */
export function formatMonth(month: string): string {
  const [y, m] = month.split('-').map(Number)
  return `${y}年${m}月`
}

/** 月を前後にずらす。「2026-12」と 1 →「2027-01」 */
export function shiftMonth(month: string, delta: number): string {
  const [y, m] = month.split('-').map(Number)
  const d = new Date(y, m - 1 + delta, 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/** 発売日ごとにまとめる（日付の順を保つ） */
export function groupByDate<T extends { release_date: string | null }>(games: T[]): { date: string; games: T[] }[] {
  const groups: { date: string; games: T[] }[] = []
  for (const g of games) {
    if (!g.release_date) continue
    const last = groups.at(-1)
    if (last?.date === g.release_date) last.games.push(g)
    else groups.push({ date: g.release_date, games: [g] })
  }
  return groups
}

/* ---------- 色 ---------- */

/** ジャンルの色分け（上位5つ）。それ以外は灰色にまとめる。色の値は index.css の --cat-* */
export const CATEGORY_COLORS = ['var(--cat-1)', 'var(--cat-2)', 'var(--cat-3)', 'var(--cat-4)', 'var(--cat-5)']
export const OTHER_COLOR = 'var(--cat-other)'
