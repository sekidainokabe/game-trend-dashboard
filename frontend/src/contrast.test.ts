/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/**
 * 文字色のコントラスト（WCAG 2.1 AA）。jsdom では画面の色を計算できないため、index.css の色の変数から直接計算する。
 * 文字は 4.5:1 以上、グラフの線や点のような文字以外のものは 3:1 以上
 */

type Theme = Record<string, string>

/** index.css の色の変数を、明るい表示と暗い表示に分けて読む */
function readThemes(): { light: Theme; dark: Theme } {
  // テストはフロントエンドのフォルダで動く
  const css = readFileSync('src/index.css', 'utf8')
  const light: Theme = {}
  const dark: Theme = {}
  // 暗い表示の @media の中かどうかは、波かっこの深さで判断する
  let depth = 0
  let darkDepth: number | null = null
  for (const line of css.split('\n')) {
    if (line.includes('prefers-color-scheme: dark')) darkDepth = depth
    const m = line.match(/^\s*(--[\w-]+):\s*(#[0-9a-f]{3,6})\s*;/i)
    if (m) (darkDepth !== null ? dark : light)[m[1]] = m[2]
    depth += (line.match(/{/g) ?? []).length - (line.match(/}/g) ?? []).length
    if (darkDepth !== null && depth <= darkDepth) darkDepth = null
  }
  return { light, dark: { ...light, ...dark } }
}

function luminance(hex: string): number {
  const full = hex.length === 4 ? `#${[...hex.slice(1)].map((c) => c + c).join('')}` : hex
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(full.slice(i, i + 2), 16) / 255)
  const lin = (c: number) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
}

function contrast(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

const themes = readThemes()

/** [文字（または図形）の色, 背景の色, 必要な比] */
const PAIRS: [string, string, number][] = [
  ['--text', '--bg', 4.5],
  ['--text', '--surface', 4.5],
  ['--text-h', '--surface', 4.5],
  ['--accent', '--bg', 4.5],
  ['--accent', '--surface', 4.5],
  ['--danger', '--bg', 4.5],
  ['--up', '--bg', 4.5],
  ['--up', '--surface', 4.5],
  ['--down', '--bg', 4.5],
  ['--down', '--surface', 4.5],
  ['--warn', '--bg', 4.5],
  ['--warn', '--surface', 4.5],
  ['--cat-1', '--bg', 3],
  ['--cat-2', '--bg', 3],
  ['--cat-3', '--bg', 3],
  ['--cat-4', '--bg', 3],
  ['--cat-5', '--bg', 3],
  ['--cat-other', '--bg', 3],
]

describe('色のコントラスト', () => {
  it('計算が正しい（白と黒は 21:1）', () => {
    expect(contrast('#ffffff', '#000000')).toBeCloseTo(21)
  })

  for (const [name, theme] of Object.entries(themes)) {
    it(`${name === 'light' ? '明るい' : '暗い'}表示で、文字は 4.5:1、図形は 3:1 以上`, () => {
      const missing = PAIRS.flatMap(([fg, bg]) => [fg, bg]).filter((v) => !theme[v])
      expect(missing).toEqual([])
      const failures = PAIRS.filter(([fg, bg, min]) => contrast(theme[fg], theme[bg]) < min).map(
        ([fg, bg, min]) => `${fg} on ${bg}: ${contrast(theme[fg], theme[bg]).toFixed(2)} < ${min}`,
      )
      expect(failures).toEqual([])
    })
  }

  it('セールの札（黄色の背景に濃い文字）', () => {
    expect(contrast(themes.light['--sale'], '#111827')).toBeGreaterThanOrEqual(4.5)
    expect(contrast(themes.dark['--sale'], '#111827')).toBeGreaterThanOrEqual(4.5)
  })
})
