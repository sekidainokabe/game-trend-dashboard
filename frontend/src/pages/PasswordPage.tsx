import { useState, type FormEvent } from 'react'
import { ApiError } from '../lib/api'
import { useChangePassword, userApiErrorMessage, type PasswordChangeInput } from '../lib/adminUsers'
import { useMe } from '../lib/auth'

const EMPTY: PasswordChangeInput = { current_password: '', password: '', password_confirmation: '' }

/** 自分のパスワードの変更。管理者から伝えられた初期パスワードは、ここで変えてもらう */
export function PasswordPage() {
  const { data: me } = useMe()
  const change = useChangePassword()
  const [input, setInput] = useState<PasswordChangeInput>(EMPTY)
  const [done, setDone] = useState(false)
  const set = (changes: Partial<PasswordChangeInput>) => setInput((prev) => ({ ...prev, ...changes }))

  if (me && !me.can.change_password) {
    return (
      <>
        <h1>パスワードの変更</h1>
        <p className="notice">公開デモ用のアカウントは、ほかの人も使うため、パスワードを変更できません。</p>
      </>
    )
  }

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    setDone(false)
    change.mutate(input, {
      onSuccess: () => {
        setInput(EMPTY)
        setDone(true)
      },
    })
  }

  const apiError = change.error instanceof ApiError ? change.error : null
  const currentError = apiError?.fieldError('current_password')
  const passwordError = apiError?.fieldError('password')

  return (
    <>
      <h1>パスワードの変更</h1>
      <p className="sub">変更すると、ほかのパソコンやブラウザでのログインは切れます。この画面は、そのまま使い続けられます。</p>
      <form onSubmit={handleSubmit} className="stack-form">
        <label>
          今のパスワード
          <input
            type="password"
            value={input.current_password}
            onChange={(e) => set({ current_password: e.target.value })}
            required
            autoComplete="current-password"
            aria-invalid={currentError ? true : undefined}
          />
          {currentError && <span className="error">{currentError}</span>}
        </label>
        <label>
          新しいパスワード（8文字以上）
          <input
            type="password"
            value={input.password}
            onChange={(e) => set({ password: e.target.value })}
            minLength={8}
            required
            autoComplete="new-password"
            aria-invalid={passwordError ? true : undefined}
          />
          {passwordError && <span className="error">{passwordError}</span>}
        </label>
        <label>
          新しいパスワード（確認のため、もう一度）
          <input
            type="password"
            value={input.password_confirmation}
            onChange={(e) => set({ password_confirmation: e.target.value })}
            minLength={8}
            required
            autoComplete="new-password"
          />
        </label>
        {change.isError && !currentError && !passwordError && (
          <p className="error" role="alert">
            {userApiErrorMessage(change.error)}
          </p>
        )}
        <div className="button-row">
          <button type="submit" disabled={change.isPending}>
            {change.isPending ? '変更中…' : '変更する'}
          </button>
        </div>
        {done && (
          <p className="notice" role="status">
            パスワードを変更しました。
          </p>
        )}
      </form>
    </>
  )
}
