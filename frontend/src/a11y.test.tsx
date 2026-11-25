import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { createMemoryRouter, RouterProvider } from 'react-router'
import axe from 'axe-core'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AppLayout } from './components/AppLayout'
import { AdminCollectionPage } from './pages/AdminCollectionPage'
import { AdminGamesPage } from './pages/AdminGamesPage'
import { GamePage } from './pages/GamePage'
import { GenresPage } from './pages/GenresPage'
import { HomePage } from './pages/HomePage'
import { RankingPage } from './pages/RankingPage'
import { RatingsPage } from './pages/RatingsPage'
import { ReleasesPage } from './pages/ReleasesPage'
import { WatchlistPage } from './pages/WatchlistPage'

/**
 * アクセシビリティの自動検査（axe-core）。各ページを仮のデータで描き、WCAG 2.1 A・AA の規則に反していないかを調べる。
 * jsdom では色や大きさを計算できないため、文字色のコントラストとグラフの中身はここでは調べない（docs/accessibility.md）
 */

const game = { id: 1, name: 'テストゲーム', image_url: 'https://example.com/a.jpg', players: 1200, change_pct: 12.5 }
const meta = { current_page: 1, last_page: 2, total: 60 }

const API: Record<string, unknown> = {
  '/api/me': { data: { id: 1, name: '管理 花子', email: 'admin@example.com', role: 'admin', can: { view_audit_logs: true, manage_users: true, manage_games: true, change_password: true } } },
  '/api/dashboard': {
    updated_at: '2026-11-24T08:00:00+00:00', total_players: 3_000_000, tracked_games: 96,
    top: [{ ...game, genres: ['RPG'], trend: [{ date: '2026-11-23', peak: 1 }, { date: '2026-11-24', peak: 2 }] }],
    rising: [game],
    upcoming: [{ id: 2, name: '新作', image_url: null, release_date: '2026-12-01', platforms: ['PC'], genres: [] }],
  },
  '/api/ranking': { data: [{ ...game, is_free: false, price_jpy: 2000, discount_percent: 50, metacritic: 90, rawg_rating: null, recommendations: 5000, release_date: '2020-01-01', genres: ['RPG'] }], meta },
  '/api/genres': {
    updated_at: '2026-11-24T08:00:00+00:00',
    genres: [{ key: 'rpg', name: 'RPG', games: 3, total_players: 9000, avg_players: 3000, change_pct: 5, avg_metacritic: 85, rated_games: 2, avg_price_jpy: 3000, free_ratio: 0.3, upcoming_games: 1 }],
    trend: [{ date: '2026-11-23', values: { rpg: 1 } }, { date: '2026-11-24', values: { rpg: 2 } }],
  },
  '/api/ratings': { games: [{ ...game, metacritic: 90, recommendations: 5000, is_free: false, price_jpy: 2000, genre_key: 'rpg', genre: 'RPG' }] },
  '/api/releases': {
    month: '2026-12', months: [{ month: '2026-12', count: 1 }],
    games: [{ id: 2, name: '新作', image_url: null, release_date: '2026-12-01', platforms: ['PC'], is_free: false, price_jpy: 3000, genres: ['RPG'] }],
    undated: [],
  },
  '/api/games/1': { data: { ...game, store_url: 'https://store.steampowered.com/app/1/', rawg_url: null, release_date: '2020-01-01', coming_soon: false, is_free: false, price_jpy: 2000, discount_percent: 0, metacritic: 90, rawg_rating: null, recommendations: 5000, platforms: ['PC'], genres: ['RPG'], peak_30d: 2000 } },
  '/api/games/1/players': { range: '7d', granularity: 'hour', points: [{ t: '2026-11-24T08:00:00+00:00', players: 1 }] },
  '/api/me/watchlist': { data: [{ ...game, release_date: null, coming_soon: false, is_free: false, price_jpy: 2000, discount_percent: 0, trend: [], genres: [] }] },
  '/api/admin/collection': {
    latest_captured_at: '2026-11-24T08:00:00+00:00',
    jobs: [{ job: 'players', label: '今遊んでいる人数', last: null }],
    data: [{ id: 1, job: 'players', status: 'success', total: 100, succeeded: 100, failed: 0, message: null, started_at: '2026-11-24T08:07:00+00:00', duration_sec: 30 }],
    meta,
  },
  '/api/admin/games': { data: [{ id: 1, name: 'テストゲーム', image_url: null, steam_appid: 1, rawg_id: null, players: 1200, coming_soon: false, state: 'collecting', excluded_at: null, details_fetched_at: null }], meta },
}

let container: HTMLDivElement
let root: Root

beforeEach(() => {
  vi.stubGlobal('fetch', async (input: string) => {
    const path = input.split('?')[0]
    const body = API[path]
    return new Response(JSON.stringify(body ?? { message: 'not found' }), { status: body ? 200 : 404, headers: { 'Content-Type': 'application/json' } })
  })
  container = document.createElement('div')
  document.body.appendChild(container)
})

afterEach(() => {
  act(() => root.unmount())
  container.remove()
  vi.unstubAllGlobals()
})

async function renderAt(pattern: string, url: string, element: React.ReactNode, heading: string) {
  const router = createMemoryRouter([{ element: <AppLayout />, children: [{ path: pattern, element }] }], { initialEntries: [url] })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  root = createRoot(container)
  await act(async () => {
    root.render(
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    )
  })
  // データが届いて本文が描かれるまで待つ
  await vi.waitFor(() => expect(container.querySelector('main')?.textContent).toContain(heading), { timeout: 3000 })
}

async function violations() {
  // 文字色のコントラストは jsdom では計算できないので外す
  const result = await axe.run(container, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'], rules: { 'color-contrast': { enabled: false } } })
  return result.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)
}

/** [ルートの形, 開く URL, ページ, 描き終わりの目印の文字] */
const PAGES: [string, string, React.ComponentType, string][] = [
  ['/', '/', HomePage, '人気ランキング'],
  ['/ranking', '/ranking', RankingPage, 'テストゲーム'],
  ['/genres', '/genres', GenresPage, 'ジャンルごとの数字'],
  ['/ratings', '/ratings', RatingsPage, '評価が高いのに'],
  ['/releases', '/releases?month=2026-12', ReleasesPage, '新作'],
  ['/games/:id', '/games/1', GamePage, '遊んでいる人数の推移'],
  ['/watchlist', '/watchlist', WatchlistPage, 'テストゲーム'],
  ['/admin/collection', '/admin/collection', AdminCollectionPage, '今遊んでいる人数'],
  ['/admin/games', '/admin/games', AdminGamesPage, 'テストゲーム'],
]

describe('アクセシビリティ（axe-core）', () => {
  it('検査が働いている（代替テキストのない画像を見つける）', async () => {
    root = createRoot(container)
    // 検査が違反を見つけられることを確かめるため、わざと alt を付けない
    // oxlint-disable-next-line jsx-a11y/alt-text
    await act(async () => root.render(<img src="https://example.com/a.jpg" />))
    expect((await violations()).join()).toContain('image-alt')
  })

  for (const [pattern, url, Page, heading] of PAGES) {
    it(`${url} に WCAG 2.1 AA の違反がない`, async () => {
      await renderAt(pattern, url, <Page />, heading)
      expect(await violations()).toEqual([])
    })
  }
})
