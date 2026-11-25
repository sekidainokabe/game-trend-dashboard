import { describe, expect, it } from 'vitest'
import { formatMonth, groupByDate, hiddenGems, logTicks, median, shiftMonth, type RatedGame } from './insights'

const game = (id: number, players: number, metacritic: number | null): RatedGame => ({
  id,
  name: `G${id}`,
  image_url: null,
  players,
  metacritic,
  recommendations: null,
  is_free: false,
  price_jpy: null,
  genre_key: null,
  genre: null,
})

describe('評価と人気', () => {
  it('中央値', () => {
    expect(median([])).toBeNull()
    expect(median([3, 1, 2])).toBe(2)
    expect(median([4, 1, 2, 3])).toBe(2.5)
  })

  it('評価が高く、人数が中央値より少ないゲームを、評価の高い順に選ぶ', () => {
    const games = [game(1, 100, 90), game(2, 50, 95), game(3, 10000, 92), game(4, 20, 70), game(5, 10, null), game(6, 5000, 80)]
    // 評価のある5本の人数の中央値は 100。100 未満で 85 点以上は 2 だけ
    expect(hiddenGems(games).map((g) => g.id)).toEqual([2])
  })
})

describe('対数の軸', () => {
  it('値の範囲を挟む 10 倍ごとの目盛り', () => {
    expect(logTicks([350, 47000, 0])).toEqual([100, 1000, 10000, 100000])
    expect(logTicks([])).toEqual([])
  })
})

describe('発売カレンダー', () => {
  it('月を前後にずらす（年をまたぐ）', () => {
    expect(shiftMonth('2026-12', 1)).toBe('2027-01')
    expect(shiftMonth('2027-01', -1)).toBe('2026-12')
    expect(formatMonth('2027-01')).toBe('2027年1月')
  })

  it('発売日ごとにまとめる', () => {
    const games = [
      { id: 1, release_date: '2026-12-01' },
      { id: 2, release_date: '2026-12-01' },
      { id: 3, release_date: '2026-12-05' },
      { id: 4, release_date: null },
    ]
    expect(groupByDate(games).map((d) => [d.date, d.games.map((g) => g.id)])).toEqual([
      ['2026-12-01', [1, 2]],
      ['2026-12-05', [3]],
    ])
  })
})
