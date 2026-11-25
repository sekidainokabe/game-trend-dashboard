/** 公開デモの admin に、管理画面は見るだけであることを伝える */
export function DemoReadOnlyNotice() {
  return (
    <p className="notice" role="note">
      公開デモのため、管理画面は見るだけです。誰でも管理者としてログインできるので、データや取得に影響する変更はできないようにしています。
    </p>
  )
}
