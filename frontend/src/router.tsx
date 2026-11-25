import { createBrowserRouter } from 'react-router'
import { AppLayout } from './components/AppLayout'
import { RequireAuth } from './components/RequireAuth'
import { AdminUsersPage } from './pages/AdminUsersPage'
import { AuditLogsPage } from './pages/AuditLogsPage'
import { GamePage } from './pages/GamePage'
import { GenresPage } from './pages/GenresPage'
import { HomePage } from './pages/HomePage'
import { LoginPage } from './pages/LoginPage'
import { PasswordPage } from './pages/PasswordPage'
import { RankingPage } from './pages/RankingPage'
import { RatingsPage } from './pages/RatingsPage'
import { ReleasesPage } from './pages/ReleasesPage'

// handle.title：ブラウザのタブに出すページ名（lib/pageTitle.ts）
export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage />, handle: { title: 'ログイン' } },
  {
    element: <AppLayout />,
    children: [
      // ダッシュボードはログインなしで見られる（URL を開いた人がすぐ中身を見られるように）
      { index: true, element: <HomePage />, handle: { title: 'ホーム' } },
      { path: 'ranking', element: <RankingPage />, handle: { title: 'ランキング' } },
      { path: 'genres', element: <GenresPage />, handle: { title: 'ジャンルの比較' } },
      { path: 'ratings', element: <RatingsPage />, handle: { title: '評価と人気' } },
      { path: 'releases', element: <ReleasesPage />, handle: { title: '発売カレンダー' } },
      { path: 'games/:id', element: <GamePage />, handle: { title: 'ゲームの詳細' } },
      {
        // ここから下のページはログインが必要
        element: <RequireAuth />,
        children: [
          { path: 'audit-logs', element: <AuditLogsPage />, handle: { title: '操作履歴' } },
          { path: 'admin/users', element: <AdminUsersPage />, handle: { title: 'ユーザー管理' } },
          { path: 'account/password', element: <PasswordPage />, handle: { title: 'パスワードの変更' } },
        ],
      },
    ],
  },
])
