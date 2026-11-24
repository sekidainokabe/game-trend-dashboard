import { describe, expect, it } from 'vitest'
import { documentTitle } from './pageTitle'

describe('documentTitle', () => {
  it('ページ名を先に、アプリ名を後ろに付ける（タブが狭くてもページ名が読める）', () => {
    expect(documentTitle('操作履歴')).toBe('操作履歴 - master-studio')
  })

  it('ページ名がなければアプリ名だけ', () => {
    expect(documentTitle(undefined)).toBe('master-studio')
  })
})
