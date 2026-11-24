<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * ユーザー管理（admin だけ。docs/SPEC.md 8章）。
 * ユーザーは消さずに無効化する。過去の変更セットや操作履歴の作成者として残すため
 */
class AdminUserController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $users = User::query()
            ->when($validated['q'] ?? null, function ($query, string $q) {
                // % と _ は LIKE の記号なので、文字として探せるようにエスケープする
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return AdminUserResource::collection($users);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', User::class);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(UserRole::class)],
            // 初期パスワードは admin が決めて本人に伝え、本人があとで変える（メールでの招待は対象外）
            'password' => ['required', 'string', Password::min(8)],
        ]);

        $user = DB::transaction(function () use ($request, $validated) {
            $user = User::create($validated);
            $this->audit->record($request->user(), AuditAction::UserCreated, $user, ['role' => $user->role->value]);

            return $user;
        });

        return (new AdminUserResource($user))->response()->setStatusCode(201);
    }

    /**
     * 役割の変更、無効化・有効化、パスワードの再設定。送られた項目だけを変える
     */
    public function update(Request $request, User $user): AdminUserResource
    {
        Gate::authorize('update', $user);
        $validated = $request->validate([
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'disabled' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', Password::min(8)],
        ]);

        DB::transaction(function () use ($request, $user, $validated) {
            // 操作履歴には、実際に変わった項目だけを「変更前 → 変更後」で残す。パスワードは値を残さない
            $changes = [];

            if (isset($validated['role']) && $validated['role'] !== $user->role->value) {
                $changes['role'] = ['from' => $user->role->value, 'to' => $validated['role']];
                $user->role = UserRole::from($validated['role']);
            }
            if (array_key_exists('disabled', $validated)) {
                $disabled = (bool) $validated['disabled'];
                if ($disabled !== $user->isDisabled()) {
                    $changes['disabled'] = ['from' => $user->isDisabled(), 'to' => $disabled];
                    $user->disabled_at = $disabled ? now() : null;
                }
            }
            if (isset($validated['password'])) {
                $changes['password_reset'] = true;
                $user->password = $validated['password'];
            }

            if ($changes !== []) {
                $user->save();
                $this->audit->record($request->user(), AuditAction::UserUpdated, $user, $changes);
            }
        });

        return new AdminUserResource($user);
    }
}
