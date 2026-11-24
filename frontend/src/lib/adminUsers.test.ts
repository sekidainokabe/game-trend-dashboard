import { describe, expect, it } from 'vitest'
import { ApiError } from './api'
import { adminUserListQuery, generatePassword, userApiErrorMessage } from './adminUsers'

describe('adminUserListQuery', () => {
  it('条件がなければクエリ文字列を付けない', () => {
    expect(adminUserListQuery({})).toBe('')
    expect(adminUserListQuery({ q: '  ', page: 1 })).toBe('')
  })

  it('検索語の前後の空白を除き、2ページ目以降だけ page を付ける', () => {
    const params = new URLSearchParams(adminUserListQuery({ q: ' 花子 ', page: 2 }))
    expect(params.get('q')).toBe('花子')
    expect(params.get('page')).toBe('2')
  })
})

describe('generatePassword', () => {
  it('指定した長さで、紛らわしい文字を含まない', () => {
    for (let i = 0; i < 50; i++) {
      const password = generatePassword()
      expect(password).toHaveLength(12)
      expect(password).toMatch(/^[A-Za-z2-9]+$/)
      expect(password).not.toMatch(/[01OIl]/)
    }
  })

  it('毎回ちがう値になる', () => {
    expect(generatePassword()).not.toBe(generatePassword())
  })
})

describe('userApiErrorMessage', () => {
  it('403 はサーバーが返した理由をそのまま出す', () => {
    const error = new ApiError(403, { message: 'デモ用のアカウントは変更できません。' })
    expect(userApiErrorMessage(error)).toBe('デモ用のアカウントは変更できません。')
  })

  it('それ以外は、もう一度試すよう案内する', () => {
    expect(userApiErrorMessage(new ApiError(500, null))).toBe('保存できませんでした。時間をおいてもう一度お試しください。')
    expect(userApiErrorMessage(new Error('network'))).toBe('保存できませんでした。時間をおいてもう一度お試しください。')
  })
})
