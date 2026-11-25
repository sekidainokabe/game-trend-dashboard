import { describe, expect, it } from 'vitest'
import { dataFreshness, formatDuration } from './adminData'

describe('取得の状況', () => {
  it('最新の人数が2時間より古ければ、取得が止まっている疑いとする', () => {
    const now = new Date('2026-11-25T10:30:00Z')
    expect(dataFreshness(null, now)).toBe('none')
    expect(dataFreshness('2026-11-25T10:00:00+00:00', now)).toBe('ok')
    expect(dataFreshness('2026-11-25T08:00:00+00:00', now)).toBe('stale')
  })

  it('かかった時間を分と秒で出す', () => {
    expect(formatDuration(null)).toBe('—')
    expect(formatDuration(35)).toBe('35秒')
    expect(formatDuration(760)).toBe('12分40秒')
  })
})
