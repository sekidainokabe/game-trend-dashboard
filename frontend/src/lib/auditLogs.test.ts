import { describe, expect, it } from 'vitest'
import { auditDetailText, auditLogQuery, startOfLocalDay } from './auditLogs'

describe('startOfLocalDay', () => {
  it('ブラウザのタイムゾーンでの、その日の 0 時にする', () => {
    expect(startOfLocalDay('2026-11-13')).toBe(new Date(2026, 10, 13).toISOString())
  })

  it('月末をまたいで翌日にできる', () => {
    expect(startOfLocalDay('2026-11-30', 1)).toBe(new Date(2026, 11, 1).toISOString())
  })
})

describe('auditLogQuery', () => {
  it('条件がなければクエリ文字列を付けない', () => {
    expect(auditLogQuery({})).toBe('')
  })

  it('期間の終わりは、その日を含むよう翌日の 0 時にする', () => {
    const params = new URLSearchParams(auditLogQuery({ userId: 2, action: 'login', from: '2026-11-13', to: '2026-11-13', page: 2 }))
    expect(params.get('user_id')).toBe('2')
    expect(params.get('action')).toBe('login')
    expect(params.get('from')).toBe(new Date(2026, 10, 13).toISOString())
    expect(params.get('to')).toBe(new Date(2026, 10, 14).toISOString())
    expect(params.get('page')).toBe('2')
  })
})

describe('auditDetailText', () => {
  it('ユーザーの変更は、変わった項目だけを日本語で並べる', () => {
    expect(
      auditDetailText({
        action: 'user.update',
        detail: { role: { from: 'member', to: 'admin' }, disabled: { from: false, to: true }, password_reset: true },
      }),
    ).toBe('役割：メンバー → 管理者・無効化・パスワードを再設定')
    expect(auditDetailText({ action: 'user.update', detail: { disabled: { from: true, to: false } } })).toBe('有効に戻す')
    expect(auditDetailText({ action: 'user.create', detail: { role: 'admin' } })).toBe('役割：管理者')
  })

  it('ログインのように補足がない操作は空にする', () => {
    expect(auditDetailText({ action: 'login', detail: null })).toBe('')
  })
})
