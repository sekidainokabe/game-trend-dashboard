import { useEffect } from 'react'
import { useMatches } from 'react-router'

/** ルートの handle に書くページ名（router.tsx） */
export type RouteHandle = { title: string }

const APP_NAME = 'master-studio'

/** ブラウザのタブや、読み上げソフトがページを移ったときに読む題名。「操作履歴 - master-studio」の形 */
export function documentTitle(pageTitle: string | undefined): string {
  return pageTitle ? `${pageTitle} - ${APP_NAME}` : APP_NAME
}

/** いま表示しているルートのページ名 */
export function useRouteTitle(): string | undefined {
  const matches = useMatches()
  // 子のルートほど後ろに並ぶので、いちばん奥のページ名を使う
  return matches
    .map((m) => (m.handle as RouteHandle | undefined)?.title)
    .filter((t): t is string => t !== undefined)
    .at(-1)
}

/** ページを移るたびに、タブの題名を変える */
export function useDocumentTitle(): string | undefined {
  const title = useRouteTitle()
  useEffect(() => {
    document.title = documentTitle(title)
  }, [title])
  return title
}
