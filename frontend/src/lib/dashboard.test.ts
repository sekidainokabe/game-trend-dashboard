import { describe, expect, it } from 'vitest'
import { changeDirection, describeSeries, formatChange, formatCompact, formatPointLabel, formatPrice, formatReleaseDate, saleRanges } from './dashboard'

describe('数の表示', () => {
  it('1万以上は「万」で短くする', () => {
    expect(formatCompact(9800)).toBe('9,800')
    expect(formatCompact(3481257)).toBe('348.1万')
  })

  it('増減率は符号を付け、比べる値がなければ「—」', () => {
    expect(formatChange(12.5)).toBe('+12.5%')
    expect(formatChange(-3)).toBe('-3%')
    expect(formatChange(null)).toBe('—')
    expect(changeDirection(null)).toBe('flat')
    expect(changeDirection(-0.1)).toBe('down')
  })

  it('価格は無料・不明・円を分ける', () => {
    expect(formatPrice({ is_free: true, price_jpy: 0 })).toBe('基本無料')
    expect(formatPrice({ is_free: false, price_jpy: null })).toBe('—')
    expect(formatPrice({ is_free: false, price_jpy: 4510 })).toBe('¥4,510')
  })
})

describe('日付の表示', () => {
  it('グラフの横軸は日本時間で出す', () => {
    expect(formatPointLabel('2026-11-24T08:00:00+00:00', 'hour')).toBe('11/24 17時')
    expect(formatPointLabel('2026-11-03', 'day')).toBe('11/3')
  })

  it('発売日に曜日を付ける', () => {
    expect(formatReleaseDate('2026-12-01')).toBe('12/1（火）')
  })
})

describe('saleRanges', () => {
  it('割引の続いた日をひとつの期間にまとめる', () => {
    const points = [
      { t: '2026-11-01', players: 1, discount_percent: 0 },
      { t: '2026-11-02', players: 1, discount_percent: 50 },
      { t: '2026-11-03', players: 1, discount_percent: 50 },
      { t: '2026-11-04', players: 1, discount_percent: 0 },
      { t: '2026-11-05', players: 1, discount_percent: 30 },
    ]
    expect(saleRanges(points)).toEqual([
      { from: '2026-11-02', to: '2026-11-03', percent: 50 },
      { from: '2026-11-05', to: '2026-11-05', percent: 30 },
    ])
  })
})

describe('describeSeries', () => {
  it('グラフの最大・最小・最新を文にする', () => {
    const points = [
      { t: '2026-11-22', players: 800 },
      { t: '2026-11-23', players: 1200 },
      { t: '2026-11-24', players: 1000 },
      { t: '2026-11-25', players: null },
    ]
    expect(describeSeries(points, 'day')).toBe('最大 1,200人（11/23）、最小 800人（11/22）、最新 1,000人')
    expect(describeSeries([], 'day')).toBe('データはまだありません')
  })
})
