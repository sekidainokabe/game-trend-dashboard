<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン中に無効化されたユーザーを、次の API 呼び出しで締め出す。
 *
 * ログイン時の確認だけでは、無効化する前からログインしていた人はセッションが切れるまで使えてしまう。
 * ユーザーの行は認証のたびに読み込んでいるので、ここで disabled_at を見ても SQL は増えない
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDisabled()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => 'このアカウントは使えません。管理者に連絡してください。'], 401);
        }

        return $next($request);
    }
}
