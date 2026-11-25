import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from './api'
import { useMe } from './auth'

export type WatchedGame = {
  id: number
  name: string | null
  image_url: string | null
  release_date: string | null
  coming_soon: boolean
  is_free: boolean
  price_jpy: number | null
  discount_percent: number
  players: number | null
  change_pct: number | null
  trend: { date: string; peak: number | null }[]
  genres: string[]
}

/** ログイン中の人のウォッチリスト。キーに利用者を入れ、別の人でログインし直したときに混ざらないようにする */
export function useWatchlist() {
  const { data: me } = useMe()
  return useQuery({
    queryKey: ['watchlist', me?.id],
    queryFn: async () => (await apiFetch<{ data: WatchedGame[] }>('/api/me/watchlist')).data,
    enabled: Boolean(me),
  })
}

/** 登録と解除。押した直後にボタンの表示を変え（楽観的更新）、失敗したら元に戻す */
export function useToggleWatch() {
  const queryClient = useQueryClient()
  const { data: me } = useMe()
  const key = ['watchlist', me?.id]

  return useMutation({
    mutationFn: ({ game, watching }: { game: Pick<WatchedGame, 'id'>; watching: boolean }) =>
      apiFetch<void>(`/api/me/watchlist/${game.id}`, { method: watching ? 'PUT' : 'DELETE' }),
    onMutate: async ({ game, watching }) => {
      await queryClient.cancelQueries({ queryKey: key })
      const previous = queryClient.getQueryData<WatchedGame[]>(key)
      if (previous && !watching) {
        queryClient.setQueryData(key, previous.filter((g) => g.id !== game.id))
      }
      return { previous }
    },
    onError: (_err, _vars, context) => {
      if (context?.previous) queryClient.setQueryData(key, context.previous)
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: key }),
  })
}
