import { useEffect, useRef } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { ROLE_LABELS, useLogout, useMe } from '../lib/auth'
import { useDocumentTitle } from '../lib/pageTitle'

export function AppLayout() {
  const navigate = useNavigate()
  const { data: user } = useMe()
  const logout = useLogout()
  const { pathname } = useLocation()
  const mainRef = useRef<HTMLElement>(null)
  // 前に表示していたページ。最初の表示ではフォーカスを動かさない（開発時の StrictMode で effect が2回動いても同じ）
  const previousPath = useRef(pathname)
  useDocumentTitle()

  // SPA ではページを移っても、フォーカスは押したリンクに残ったままになる。
  // キーボードや読み上げソフトの利用者が新しいページの先頭から読めるよう、本文にフォーカスを移す。
  // 絞り込み（? 以降）が変わるだけでは移さない（入力中のフォーカスを奪わないため）
  useEffect(() => {
    if (previousPath.current === pathname) {
      return
    }
    previousPath.current = pathname
    mainRef.current?.focus()
  }, [pathname])

  const handleLogout = () => {
    logout.mutate(undefined, { onSuccess: () => navigate('/login', { replace: true }) })
  }

  return (
    <>
      {/* キーボードで、毎回ナビゲーションを通らずに本文へ移るためのリンク。フォーカスしたときだけ見える */}
      <a href="#main" className="skip-link">
        本文へ移動
      </a>
      <header className="app-header">
        <Link to="/" className="app-title">ゲーム人気ダッシュボード</Link>
        {/* NavLink は表示中のページのリンクに active クラスを付ける */}
        <nav className="app-nav" aria-label="メニュー">
          <NavLink to="/" end>
            ホーム
          </NavLink>
          <NavLink to="/ranking">ランキング</NavLink>
          <NavLink to="/genres">ジャンル</NavLink>
          <NavLink to="/ratings">評価と人気</NavLink>
          <NavLink to="/releases">発売カレンダー</NavLink>
          {user?.can.view_audit_logs && <NavLink to="/audit-logs">操作履歴</NavLink>}
          {user?.can.manage_users && <NavLink to="/admin/users">ユーザー管理</NavLink>}
        </nav>
        {user === null && (
          <div className="app-user">
            <Link to="/login">ログイン</Link>
          </div>
        )}
        {user && (
          <div className="app-user">
            {/* 名前から、自分のパスワードの変更へ */}
            <Link to="/account/password" title="パスワードの変更">
              {user.name}（{ROLE_LABELS[user.role]}）
            </Link>
            <button type="button" onClick={handleLogout} disabled={logout.isPending}>
              ログアウト
            </button>
          </div>
        )}
      </header>
      {/* tabIndex={-1}：Tab キーでは止まらないが、プログラムからはフォーカスできる */}
      <main id="main" className="app-main" ref={mainRef} tabIndex={-1}>
        <Outlet />
      </main>
      <footer className="app-footer">
        データ提供：<a href="https://store.steampowered.com/">Steam</a>、<a href="https://rawg.io/">RAWG</a>。ゲームの名称・画像の権利は各権利者に帰属します。
      </footer>
    </>
  )
}
