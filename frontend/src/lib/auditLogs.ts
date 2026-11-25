import { useQuery } from '@tanstack/react-query'
import { apiFetch, type Paginated } from './api'
import { ROLE_LABELS, type UserRole } from './auth'

export type AuditAction =
  | 'login'
  | 'user.create'
  | 'user.update'
  | 'user.password'
  | 'game.exclude'
  | 'game.include'

/** 絞り込みの選択肢。表示名はサーバーの AuditAction::label() と同じ */
export const AUDIT_ACTION_LABELS: Record<AuditAction, string> = {
  login: 'ログイン',
  'user.create': 'ユーザーの追加',
  'user.update': 'ユーザーの変更',
  'user.password': 'パスワードの変更',
  'game.exclude': 'ゲームを集計から外す',
  'game.include': 'ゲームを集計に戻す',
}

export const AUDIT_ACTIONS = Object.keys(AUDIT_ACTION_LABELS) as AuditAction[]

export type AuditLog = {
  id: number
  user: { id: number; name: string } | null
  action: AuditAction
  action_label: string
  /** title はユーザーの名前かゲームの名前。削除済みなら null */
  target: { type: 'user' | 'game'; id: number; title: string | null }
  detail: Record<string, unknown> | null
  created_at: string
}

export type UserOption = { id: number; name: string; role: UserRole }

export type AuditLogParams = {
  userId?: number
  action?: AuditAction
  /** 画面で選んだ日付（YYYY-MM-DD）。その日を含む */
  from?: string
  to?: string
  page?: number
}

/**
 * 日付（YYYY-MM-DD）を、ブラウザのタイムゾーンでのその日の 0 時の日時にする。
 * サーバーは利用者のタイムゾーンを知らないため、「どの瞬間からどの瞬間まで」に直して送る
 */
export function startOfLocalDay(date: string, addDays = 0): string {
  const [y, m, d] = date.split('-').map(Number)
  return new Date(y, m - 1, d + addDays).toISOString()
}

/** 一覧 API のクエリ文字列。to はその日を含めたいので、翌日の 0 時（を含まない）にする */
export function auditLogQuery({ userId, action, from, to, page }: AuditLogParams): string {
  const params = new URLSearchParams()
  if (userId !== undefined) params.set('user_id', String(userId))
  if (action) params.set('action', action)
  if (from) params.set('from', startOfLocalDay(from))
  if (to) params.set('to', startOfLocalDay(to, 1))
  if (page !== undefined && page > 1) params.set('page', String(page))
  const query = params.toString()
  return query === '' ? '' : `?${query}`
}

const text = (value: unknown) => (typeof value === 'string' ? value : '')

/** 操作ごとの補足（detail）を、一覧に出す短い文にする */
export function auditDetailText(log: Pick<AuditLog, 'action' | 'detail'>): string {
  const d = log.detail ?? {}
  switch (log.action) {
    case 'user.create':
      return `役割：${roleLabel(d.role)}`
    case 'user.update':
      return userUpdateText(d)
    default:
      return ''
  }
}

function roleLabel(value: unknown): string {
  return typeof value === 'string' && value in ROLE_LABELS ? ROLE_LABELS[value as UserRole] : text(value)
}

/** 変わった項目だけが入っている（例：役割：メンバー → 管理者・無効化・パスワードを再設定）。パスワードの値は記録していない */
function userUpdateText(d: Record<string, unknown>): string {
  const parts: string[] = []
  const role = d.role as { from?: unknown; to?: unknown } | undefined
  if (role) parts.push(`役割：${roleLabel(role.from)} → ${roleLabel(role.to)}`)
  const disabled = d.disabled as { to?: unknown } | undefined
  if (disabled) parts.push(disabled.to === true ? '無効化' : '有効に戻す')
  if (d.password_reset === true) parts.push('パスワードを再設定')
  return parts.join('・')
}

export const auditLogKeys = {
  list: (params: AuditLogParams) => ['audit-logs', 'list', params] as const,
  users: ['users'] as const,
}

export function useAuditLogs(params: AuditLogParams) {
  return useQuery({
    queryKey: auditLogKeys.list(params),
    queryFn: () => apiFetch<Paginated<AuditLog>>(`/api/audit-logs${auditLogQuery(params)}`),
    placeholderData: (previous) => previous,
  })
}

export function useUsers() {
  return useQuery({
    queryKey: auditLogKeys.users,
    queryFn: () => apiFetch<{ data: UserOption[] }>('/api/users').then((res) => res.data),
    staleTime: 5 * 60 * 1000,
  })
}
