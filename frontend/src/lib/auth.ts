import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, apiFetch, fetchCsrfCookie } from './api'

export type UserRole = 'member' | 'admin'

export type User = {
  id: number
  name: string
  email: string
  role: UserRole
  /** サーバーの Policy で判定した権限。ボタンの出し分けに使う（最終的な判定はサーバー側） */
  can: {
    view_audit_logs: boolean
    manage_users: boolean
    /** 取得の状況・ゲームの管理（admin だけ） */
    manage_games: boolean
    /** ゲームを集計から外す・戻す。公開デモの admin は false（見るだけ） */
    update_games: boolean
    /** ユーザーの追加。公開デモの admin は false（見るだけ） */
    create_users: boolean
    /** 公開デモのアカウントは false（自分のパスワードも変えられない） */
    change_password: boolean
  }
}

export type Credentials = {
  email: string
  password: string
}

const ME_QUERY_KEY = ['me'] as const

/** ログイン中のユーザー。未ログイン（401）のときは null */
export async function fetchMe(): Promise<User | null> {
  try {
    const res = await apiFetch<{ data: User }>('/api/me')
    return res.data
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) {
      return null
    }
    throw err
  }
}

export async function login(credentials: Credentials): Promise<User> {
  // ログイン前に CSRF トークンのクッキーを受け取っておく
  await fetchCsrfCookie()
  const res = await apiFetch<{ data: User }>('/api/login', { method: 'POST', body: credentials })
  return res.data
}

export async function logout(): Promise<void> {
  await apiFetch<void>('/api/logout', { method: 'POST' })
}

export function useMe() {
  return useQuery({ queryKey: ME_QUERY_KEY, queryFn: fetchMe, staleTime: Infinity })
}

export function useLogin() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: login,
    // ログイン API がユーザーを返すので、/api/me を取り直さずにキャッシュへ入れる
    onSuccess: (user) => queryClient.setQueryData(ME_QUERY_KEY, user),
  })
}

export function useLogout() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: logout,
    // 前のユーザーのデータが残らないよう、キャッシュをすべて捨ててから未ログイン状態にする
    onSuccess: () => {
      queryClient.clear()
      queryClient.setQueryData(ME_QUERY_KEY, null)
    },
  })
}

export const ROLE_LABELS: Record<UserRole, string> = {
  member: 'メンバー',
  admin: '管理者',
}
