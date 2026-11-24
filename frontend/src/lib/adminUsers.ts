import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, apiFetch, type Paginated } from './api'
import type { UserRole } from './auth'
import { auditLogKeys } from './auditLogs'

/** ユーザー管理（admin だけ）で扱うユーザー */
export type AdminUser = {
  id: number
  name: string
  email: string
  role: UserRole
  disabled_at: string | null
  created_at: string
  /** 自分自身とデモ用アカウントは変えられない。判定はサーバーの UserPolicy */
  can: { update: boolean }
  /** 変えられない理由（変えられるときは null） */
  locked_reason: string | null
}

export type NewUserInput = {
  name: string
  email: string
  role: UserRole
  password: string
}

/** 送った項目だけが変わる */
export type UserChanges = {
  role?: UserRole
  disabled?: boolean
  password?: string
}

export type AdminUserListParams = { q?: string; page?: number }

export function adminUserListQuery({ q, page }: AdminUserListParams): string {
  const params = new URLSearchParams()
  if (q && q.trim() !== '') params.set('q', q.trim())
  if (page !== undefined && page > 1) params.set('page', String(page))
  const query = params.toString()
  return query === '' ? '' : `?${query}`
}

// 紛らわしい文字（0 と O、1 と l と I）を除いた文字。口頭やチャットで伝えやすくする
const PASSWORD_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'

/** 初期パスワードの候補。推測されにくいよう、暗号用の乱数で作る */
export function generatePassword(length = 12): string {
  const values = crypto.getRandomValues(new Uint32Array(length))
  return Array.from(values, (v) => PASSWORD_CHARS[v % PASSWORD_CHARS.length]).join('')
}

/** API のエラーを画面に出す文にする（入力の誤りは項目ごとに出すので、ここでは扱わない） */
export function userApiErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const message = (error.body as { message?: unknown } | null)?.message
    // 403 はサーバーが理由（自分自身・デモ用アカウントなど）を日本語で返す
    if (error.status === 403 && typeof message === 'string') return message
    if (error.status === 404) return 'ユーザーが見つかりません。画面を再読み込みしてください。'
  }
  return '保存できませんでした。時間をおいてもう一度お試しください。'
}

export const adminUserKeys = {
  all: ['admin-users'] as const,
  list: (params: AdminUserListParams) => ['admin-users', 'list', params] as const,
}

export function useAdminUsers(params: AdminUserListParams) {
  return useQuery({
    queryKey: adminUserKeys.list(params),
    queryFn: () => apiFetch<Paginated<AdminUser>>(`/api/admin/users${adminUserListQuery(params)}`),
    placeholderData: (previous) => previous,
  })
}

/** 追加・変更のあとは、ユーザー一覧と、操作履歴の「利用者」の選択肢を取り直す */
function useInvalidateUsers() {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: adminUserKeys.all })
    void queryClient.invalidateQueries({ queryKey: auditLogKeys.users })
  }
}

export function useCreateUser() {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: (input: NewUserInput) =>
      apiFetch<{ data: AdminUser }>('/api/admin/users', { method: 'POST', body: input }).then((res) => res.data),
    onSuccess: invalidate,
  })
}

export function useUpdateUser() {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: ({ id, changes }: { id: number; changes: UserChanges }) =>
      apiFetch<{ data: AdminUser }>(`/api/admin/users/${id}`, { method: 'PATCH', body: changes }).then((res) => res.data),
    onSuccess: invalidate,
  })
}

export type PasswordChangeInput = {
  current_password: string
  password: string
  password_confirmation: string
}

export function useChangePassword() {
  return useMutation({
    mutationFn: (input: PasswordChangeInput) => apiFetch<void>('/api/me/password', { method: 'PUT', body: input }),
  })
}
