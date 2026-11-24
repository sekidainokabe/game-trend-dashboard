<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * SPA 向けのクッキー（セッション）認証。トークンは発行しない。
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request, AuditLogger $audit): UserResource
    {
        $guard = Auth::guard('web');

        // attempt() ではなく、確かめる（validate）→ ログインさせる（login）に分け、間で無効化を確かめる
        if (! $guard->validate($request->validated())) {
            // どちらが違うかは教えない（登録済みのメールアドレスを探られないようにするため）
            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが違います。',
            ]);
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();
        if ($user->isDisabled()) {
            // パスワードまで正しい人にだけ出るので、登録済みかどうかを探る手がかりにはならない
            throw ValidationException::withMessages([
                'email' => 'このアカウントは使えません。管理者に連絡してください。',
            ]);
        }

        $guard->login($user);

        // セッション固定攻撃への対策：ログイン前のセッション ID を使い回さない
        $request->session()->regenerate();

        // 失敗したログインは残さない（回数の制限は throttle:login で行っている）
        $audit->record($user, AuditAction::Login, $user);

        return new UserResource($user);
    }

    /**
     * 自分のパスワードの変更。今のパスワードを確かめる（席を外した間に、他人に変えられないようにする）
     */
    public function updatePassword(Request $request, AuditLogger $audit): Response
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('changePassword', $user);

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        DB::transaction(function () use ($user, $validated, $audit) {
            $user->update(['password' => $validated['password']]);
            // パスワードの値は残さない
            $audit->record($user, AuditAction::PasswordChanged, $user);
        });

        return response()->noContent();
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        // セッションを破棄し、CSRF トークンも作り直す
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
