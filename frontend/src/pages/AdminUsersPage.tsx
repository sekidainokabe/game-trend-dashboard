import { useId, useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router'
import { ApiError } from '../lib/api'
import {
  generatePassword,
  useAdminUsers,
  useCreateUser,
  useUpdateUser,
  userApiErrorMessage,
  type AdminUser,
  type NewUserInput,
} from '../lib/adminUsers'
import { DemoReadOnlyNotice } from '../components/DemoReadOnlyNotice'
import { ROLE_LABELS, useMe, type UserRole } from '../lib/auth'
import { formatDateTime } from '../lib/format'

const ROLES = Object.keys(ROLE_LABELS) as UserRole[]

/** ユーザー管理（admin だけ）。ユーザーは消さずに無効化する */
export function AdminUsersPage() {
  const { data: me } = useMe()

  // ナビゲーションには出していないが、URL を直接開かれたときのため。最終的にはサーバーが 403 を返す
  if (me && !me.can.manage_users) {
    return (
      <>
        <h1>ユーザー管理</h1>
        <p role="alert">ユーザー管理は、管理者だけが使えます。</p>
      </>
    )
  }
  return <AdminUserList canCreate={me?.can.create_users ?? false} />
}

function AdminUserList({ canCreate }: { canCreate: boolean }) {
  const [searchParams, setSearchParams] = useSearchParams()
  const q = searchParams.get('q') ?? ''
  const page = Math.max(1, Number(searchParams.get('page')) || 1)
  const users = useAdminUsers({ q, page })
  const meta = users.data?.meta

  const [query, setQuery] = useState(q)
  const handleSearch = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    setSearchParams(query.trim() === '' ? {} : { q: query.trim() })
  }
  const goToPage = (next: number) => {
    const params = new URLSearchParams(searchParams)
    if (next > 1) params.set('page', String(next))
    else params.delete('page')
    setSearchParams(params)
  }

  return (
    <>
      <h1>ユーザー管理</h1>
      <p className="sub">
        辞めた人や異動した人は、削除せずに無効化します（過去の変更セットや操作履歴に名前を残すため）。無効化するとすぐにログインできなくなります。
      </p>

      {canCreate ? <AddUserForm /> : <DemoReadOnlyNotice />}

      <form className="filters" role="search" onSubmit={handleSearch}>
        <label className="filter-select">
          名前・メールアドレスで検索
          <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} maxLength={100} />
        </label>
        <button type="submit" className="secondary">
          検索
        </button>
        {meta && (
          <span className="sub" role="status">
            {meta.total} 人
          </span>
        )}
      </form>

      {users.isPending && <p>読み込み中…</p>}
      {users.isError && <p role="alert">ユーザーの一覧を取得できませんでした。</p>}
      {users.isSuccess && (
        <>
          <div className="table-scroll">
            <table className="data-table">
              <thead>
                <tr>
                  <th>名前</th>
                  <th>メールアドレス</th>
                  <th>役割</th>
                  <th>状態</th>
                  <th>追加した日時</th>
                  <th>操作</th>
                </tr>
              </thead>
              <tbody>
                {users.data.data.map((user) => (
                  <UserRow key={user.id} user={user} />
                ))}
              </tbody>
            </table>
          </div>
          {users.data.data.length === 0 && <p className="sub">条件に合うユーザーはいません。</p>}
        </>
      )}

      {meta && meta.last_page > 1 && (
        <nav className="pagination" aria-label="ページ送り">
          <button type="button" disabled={page <= 1} onClick={() => goToPage(page - 1)}>
            前へ
          </button>
          <span>
            {meta.current_page} / {meta.last_page} ページ
          </span>
          <button type="button" disabled={page >= meta.last_page} onClick={() => goToPage(page + 1)}>
            次へ
          </button>
        </nav>
      )}
    </>
  )
}

const EMPTY_USER: NewUserInput = { name: '', email: '', role: 'member', password: '' }

/** 追加フォーム。初期パスワードは管理者が決めて本人に伝える（メールでの招待はしない） */
function AddUserForm() {
  const create = useCreateUser()
  const [input, setInput] = useState<NewUserInput>(EMPTY_USER)
  const [added, setAdded] = useState<{ name: string; password: string } | null>(null)
  const set = (changes: Partial<NewUserInput>) => setInput((prev) => ({ ...prev, ...changes }))

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    setAdded(null)
    create.mutate(input, {
      onSuccess: (user) => {
        // 伝え忘れを防ぐため、追加した直後だけ初期パスワードを表示する
        setAdded({ name: user.name, password: input.password })
        setInput(EMPTY_USER)
      },
    })
  }

  const apiError = create.error instanceof ApiError ? create.error : null
  const fieldError = (field: string) => apiError?.fieldError(field)
  const hasFieldError = ['name', 'email', 'role', 'password'].some((f) => fieldError(f))

  return (
    <details className="add-user">
      <summary>ユーザーを追加</summary>
      <form onSubmit={handleSubmit} className="stack-form">
        <label>
          名前（必須、100文字以内）
          <input
            value={input.name}
            onChange={(e) => set({ name: e.target.value })}
            maxLength={100}
            required
            aria-invalid={fieldError('name') ? true : undefined}
          />
          {fieldError('name') && <span className="error">{fieldError('name')}</span>}
        </label>
        <label>
          メールアドレス（必須。ログインに使います）
          <input
            type="email"
            value={input.email}
            onChange={(e) => set({ email: e.target.value })}
            maxLength={255}
            required
            autoComplete="off"
            aria-invalid={fieldError('email') ? true : undefined}
          />
          {fieldError('email') && <span className="error">{fieldError('email')}</span>}
        </label>
        <label>
          役割
          <select value={input.role} onChange={(e) => set({ role: e.target.value as UserRole })}>
            {ROLES.map((role) => (
              <option key={role} value={role}>
                {ROLE_LABELS[role]}
              </option>
            ))}
          </select>
          <span className="sub">編集者：変更セットを作る／承認者：レビューして反映する／管理者：すべての操作とユーザー管理</span>
        </label>
        <label>
          初期パスワード（8文字以上。本人に伝え、ログイン後に変えてもらいます）
          <span className="inline-field">
            <input
              value={input.password}
              onChange={(e) => set({ password: e.target.value })}
              minLength={8}
              required
              autoComplete="new-password"
              className="mono"
              aria-invalid={fieldError('password') ? true : undefined}
            />
            <button type="button" className="secondary small" onClick={() => set({ password: generatePassword() })}>
              自動で作る
            </button>
          </span>
          {fieldError('password') && <span className="error">{fieldError('password')}</span>}
        </label>
        {create.isError && !hasFieldError && (
          <p className="error" role="alert">
            {userApiErrorMessage(create.error)}
          </p>
        )}
        <div className="button-row">
          <button type="submit" disabled={create.isPending}>
            {create.isPending ? '追加中…' : '追加する'}
          </button>
        </div>
        {added && (
          <p className="notice" role="status">
            {added.name} さんを追加しました。初期パスワード <span className="mono">{added.password}</span>{' '}
            を本人に伝えてください（この表示は画面を離れると消えます）。
          </p>
        )}
      </form>
    </details>
  )
}

function UserRow({ user }: { user: AdminUser }) {
  const update = useUpdateUser()
  const [resetting, setResetting] = useState(false)
  const [password, setPassword] = useState('')
  const [notice, setNotice] = useState<string | null>(null)
  const passwordId = useId()
  const disabled = user.disabled_at !== null

  const run = (changes: Parameters<typeof update.mutate>[0]['changes'], done: string) => {
    setNotice(null)
    update.mutate({ id: user.id, changes }, { onSuccess: () => setNotice(done) })
  }

  const handleRole = (role: UserRole) => {
    if (window.confirm(`${user.name} さんの役割を「${ROLE_LABELS[role]}」に変えます。よろしいですか？`)) {
      run({ role }, '役割を変えました。')
    }
  }
  const handleToggle = () => {
    const message = disabled
      ? `${user.name} さんを有効に戻します。もう一度ログインできるようになります。よろしいですか？`
      : `${user.name} さんを無効化します。ログイン中でも、次の操作から使えなくなります。よろしいですか？`
    if (window.confirm(message)) {
      run({ disabled: !disabled }, disabled ? '有効に戻しました。' : '無効化しました。')
    }
  }
  const handleReset = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const newPassword = password
    setNotice(null)
    update.mutate(
      { id: user.id, changes: { password: newPassword } },
      {
        onSuccess: () => {
          setResetting(false)
          setPassword('')
          setNotice(`パスワードを ${newPassword} に再設定しました。本人に伝えてください。ログイン中の画面は切れます。`)
        },
      },
    )
  }

  const passwordError = update.error instanceof ApiError ? update.error.fieldError('password') : undefined

  return (
    <tr className={disabled ? 'row-disabled' : undefined}>
      <td className="nowrap">{user.name}</td>
      <td>{user.email}</td>
      <td className="nowrap">
        {user.can.update ? (
          <select
            aria-label={`${user.name} さんの役割`}
            value={user.role}
            onChange={(e) => handleRole(e.target.value as UserRole)}
            disabled={update.isPending}
          >
            {ROLES.map((role) => (
              <option key={role} value={role}>
                {ROLE_LABELS[role]}
              </option>
            ))}
          </select>
        ) : (
          ROLE_LABELS[user.role]
        )}
      </td>
      <td className="nowrap">
        {disabled ? (
          <span className="badge badge-rejected" title={`無効化した日時：${formatDateTime(user.disabled_at)}`}>
            無効
          </span>
        ) : (
          <span className="badge badge-applied">有効</span>
        )}
      </td>
      <td className="nowrap">{formatDateTime(user.created_at)}</td>
      <td>
        {user.can.update ? (
          <div className="user-actions">
            <div className="button-row">
              <button type="button" className={disabled ? 'small' : 'small danger'} onClick={handleToggle} disabled={update.isPending}>
                {disabled ? '有効に戻す' : '無効化'}
              </button>
              {!resetting && (
                <button
                  type="button"
                  className="small secondary"
                  onClick={() => {
                    setPassword(generatePassword())
                    setResetting(true)
                  }}
                  disabled={update.isPending}
                >
                  パスワードを再設定
                </button>
              )}
            </div>
            {resetting && (
              <form className="inline-field" onSubmit={handleReset}>
                <label htmlFor={passwordId} className="visually-hidden">
                  {user.name} さんの新しいパスワード
                </label>
                <input
                  id={passwordId}
                  className="mono"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  minLength={8}
                  required
                  autoComplete="new-password"
                  aria-invalid={passwordError ? true : undefined}
                />
                <button type="submit" className="small" disabled={update.isPending}>
                  決定
                </button>
                <button type="button" className="small secondary" onClick={() => setResetting(false)}>
                  やめる
                </button>
              </form>
            )}
            {passwordError && <span className="error">{passwordError}</span>}
            {update.isError && !passwordError && (
              <span className="error" role="alert">
                {userApiErrorMessage(update.error)}
              </span>
            )}
            {notice && (
              <span className="sub" role="status">
                {notice}
              </span>
            )}
          </div>
        ) : (
          <span className="sub">{user.locked_reason}</span>
        )}
      </td>
    </tr>
  )
}
