import { Line, LineChart, ResponsiveContainer, YAxis } from 'recharts'
import { changeDirection, formatChange } from '../lib/dashboard'

/** 24時間前からの増減。色だけに頼らず、矢印と符号でも分かるようにする */
export function ChangeBadge({ pct }: { pct: number | null }) {
  const direction = changeDirection(pct)
  const arrow = { up: '▲', down: '▼', flat: '' }[direction]
  return (
    <span className={`change change-${direction}`} title="24時間前との比較">
      {arrow && <span aria-hidden="true">{arrow} </span>}
      {formatChange(pct)}
    </span>
  )
}

/** ゲームの画像。画像がないゲームは名前の頭文字で代える */
export function GameThumb({ src, name, size = 'small' }: { src: string | null; name: string | null; size?: 'small' | 'large' }) {
  if (!src) {
    return (
      <span className={`thumb thumb-${size} thumb-empty`} aria-hidden="true">
        {(name ?? '?').slice(0, 1)}
      </span>
    )
  }
  // 画像は装飾（名前は隣に文字で出す）なので alt は空にする
  return <img className={`thumb thumb-${size}`} src={src} alt="" loading="lazy" />
}

/** 直近7日の小さなグラフ。軸や目盛りは出さず、増えているか減っているかだけを見せる */
export function Sparkline({ values }: { values: (number | null)[] }) {
  if (values.filter((v) => v !== null).length < 2) {
    return <span className="sparkline sparkline-empty">集計中</span>
  }
  const data = values.map((v, i) => ({ i, v }))
  const rising = (values.at(-1) ?? 0) >= (values.find((v) => v !== null) ?? 0)
  return (
    <span className="sparkline" aria-hidden="true">
      <ResponsiveContainer width="100%" height={32}>
        <LineChart data={data}>
          <YAxis hide domain={['dataMin', 'dataMax']} />
          <Line type="monotone" dataKey="v" stroke={rising ? 'var(--up)' : 'var(--down)'} strokeWidth={2} dot={false} isAnimationActive={false} connectNulls />
        </LineChart>
      </ResponsiveContainer>
    </span>
  )
}

export function GenreChips({ genres }: { genres: string[] }) {
  if (genres.length === 0) return null
  return (
    <ul className="chips" aria-label="ジャンル">
      {genres.map((g) => (
        <li key={g}>{g}</li>
      ))}
    </ul>
  )
}
