import { createBrowserRouter } from 'react-router'
import { AppLayout } from './components/AppLayout'
import { RequireAuth } from './components/RequireAuth'
import { AdminUsersPage } from './pages/AdminUsersPage'
import { AuditLogsPage } from './pages/AuditLogsPage'
import { HomePage } from './pages/HomePage'
import { LoginPage } from './pages/LoginPage'
import { PasswordPage } from './pages/PasswordPage'

// handle.title：ブラウザのタブに出すページ名（lib/pageTitle.ts）
export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage />, handle: { title: 'ログイン' } },
  {
    element: <AppLayout />,
    children: [
      // ダッシュボードはログインなしで見られる（URL を開いた人がすぐ中身を見られるように）
      { index: true, element: <HomePage />, handle: { title: 'ホーム' } },
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
