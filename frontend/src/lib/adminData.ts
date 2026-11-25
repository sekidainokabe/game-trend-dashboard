import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch, type Paginated } from './api'

/* ---------- 取得の状況 ---------- */

export type RunStatus = 'running' | 'success' | 'partial' | 'failed'

export const RUN_STATUS_LABELS: Record<RunStatus, string> = {
  running: '実行中',
  success: '成功',
  partial: '一部失敗',
  failed: '失敗',
}

export type FetchRun = {
  id: number
  job: string
  status: RunStatus
  total: number
  succeeded: number
  failed: number
  message: string | null
  started_at: string
  duration_sec: number | null
}

export type CollectionReport = Paginated<FetchRun> & {
  latest_captured_at: string | null
  jobs: { job: string; label: string; last: FetchRun | null }[]
}

export type CollectionParams = { job?: string; status?: RunStatus; page: number }

export function useCollection({ job, status, page }: CollectionParams) {
  const params = new URLSearchParams({ page: String(page) })
  if (job) params.set('job', job)
  if (status) params.set('status', status)
  return useQuery({
    queryKey: ['admin', 'collection', job, status, page],
    queryFn: () => apiFetch<CollectionReport>(`/api/admin/collection?${params}`),
    placeholderData: keepPreviousData,
  })
}

/**
 * 最新のデータが古すぎないか。毎時取るので、2時間を超えて新しいデータがなければ取得が止まっている疑いがある
 */
export function dataFreshness(latestCapturedAt: string | null, now = new Date()): 'ok' | 'stale' | 'none' {
  if (latestCapturedAt === null) return 'none'
  const hours = (now.getTime() - new Date(latestCapturedAt).getTime()) / 3_600_000
  return hours > 2 ? 'stale' : 'ok'
}

/** 「12分40秒」「35秒」 */
export function formatDuration(sec: number | null): string {
  if (sec === null) return '—'
  const m = Math.floor(sec / 60)
  return m > 0 ? `${m}分${sec % 60}秒` : `${sec}秒`
}

/* ---------- ゲームの管理 ---------- */

export type GameState = 'collecting' | 'stopped' | 'excluded'

export const GAME_STATE_LABELS: Record<GameState, string> = {
  collecting: '取得中',
  stopped: '自動で停止',
  excluded: '集計から除外',
}

export type AdminGame = {
  id: number
  name: string | null
  image_url: string | null
  steam_appid: number | null
  rawg_id: number | null
  players: number | null
  coming_soon: boolean
  state: GameState
  excluded_at: string | null
  details_fetched_at: string | null
}

export type AdminGameParams = { q: string; state?: GameState; page: number }

export function useAdminGames({ q, state, page }: AdminGameParams) {
  const params = new URLSearchParams({ page: String(page) })
  if (q) params.set('q', q)
  if (state) params.set('state', state)
  return useQuery({
    queryKey: ['admin', 'games', q, state, page],
    queryFn: () => apiFetch<Paginated<AdminGame>>(`/api/admin/games?${params}`),
    placeholderData: keepPreviousData,
  })
}

export function useSetExcluded() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, excluded }: { id: number; excluded: boolean }) =>
      apiFetch<{ excluded: boolean }>(`/api/admin/games/${id}`, { method: 'PATCH', body: { excluded } }),
    // 一覧だけでなく、ランキングなどの集計も変わるので、取り直させる
    onSuccess: () => queryClient.invalidateQueries(),
  })
}
