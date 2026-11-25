<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ユーザー管理（/api/admin/users）、自分のパスワードの変更（/api/me/password）、無効化したユーザーの締め出し
 */
class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $approver;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // デモ用アカウントと同じメールアドレスで作る（デモ用アカウントの保護を確かめるため）
        $this->editor = User::factory()->create(['email' => 'member@example.com', 'role' => UserRole::Member, 'password' => 'secret-password']);
        $this->approver = User::factory()->create(['email' => 'member2@example.com', 'role' => UserRole::Member]);
        $this->admin = User::factory()->create(['email' => 'admin@example.com', 'role' => UserRole::Admin]);

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    /**
     * 利用者を切り替える。sanctum のガードは最初の利用者を覚えており、セッションには前の利用者のパスワードの
     * ハッシュが残る（違う人だと判断されて 401 になる）ため、どちらも作り直す
     */
    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->actingAs($user, 'web');
    }

    /** @return array<string, string> */
    private function newUser(): array
    {
        return ['name' => '新人 三郎', 'email' => 'new@example.com', 'role' => 'member', 'password' => 'initial-pass'];
    }

    public function test_admin_以外はユーザー管理を使えない(): void
    {
        foreach ([$this->editor, $this->approver] as $user) {
            $this->as($user);
            $this->getJson('/api/admin/users')->assertForbidden();
            $this->postJson('/api/admin/users', $this->newUser())->assertForbidden();
            $this->patchJson("/api/admin/users/{$this->editor->id}", ['role' => 'admin'])
                ->assertForbidden()
                ->assertJsonPath('message', 'ユーザーを管理する権限がありません。');
        }

        $this->assertSame(UserRole::Member, $this->editor->fresh()->role);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_一覧は名前とメールアドレスで検索でき、行ごとに変更できるかを返す(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.email', 'member@example.com')
            ->assertJsonPath('data.0.can.update', true)
            ->assertJsonPath('data.0.locked_reason', null)
            // 自分自身の行は変えられない
            ->assertJsonPath('data.2.can.update', false)
            ->assertJsonPath('data.2.locked_reason', '自分自身の役割や状態は、ここでは変えられません。');

        $this->getJson('/api/admin/users?q=member2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->approver->id);

        // % は LIKE の記号ではなく、文字として探す
        $this->getJson('/api/admin/users?q=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_ユーザーを追加すると、そのパスワードでログインでき、操作履歴が残る(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->postJson('/api/admin/users', $this->newUser())
            ->assertCreated()
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.disabled_at', null);

        $created = User::findOrFail($response->json('data.id'));
        $this->assertTrue(Hash::check('initial-pass', $created->password));

        $log = AuditLog::sole();
        $this->assertSame(AuditAction::UserCreated, $log->action);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(['type' => 'user', 'id' => $created->id], ['type' => $log->target_type, 'id' => $log->target_id]);
        $this->assertSame(['role' => 'member'], $log->detail);
    }

    public function test_追加するときの入力の誤りは日本語で返す(): void
    {
        $this->actingAs($this->admin, 'web')
            ->postJson('/api/admin/users', [...$this->newUser(), 'email' => 'member@example.com', 'role' => 'owner', 'password' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email' => 'そのメールアドレスはすでに使われています。',
                'role' => '役割は選択肢にない値です。',
                'password' => 'パスワードは8文字以上で入力してください。',
            ]);

        $this->assertSame(0, AuditLog::count());
    }

    public function test_役割の変更・無効化・パスワードの再設定は、変わった項目だけを操作履歴に残す(): void
    {
        $this->actingAs($this->admin, 'web')
            ->patchJson("/api/admin/users/{$this->editor->id}", ['role' => 'admin', 'disabled' => true, 'password' => 'reset-pass-1'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $editor = $this->editor->fresh();
        $this->assertSame(UserRole::Admin, $editor->role);
        $this->assertTrue($editor->isDisabled());
        $this->assertTrue(Hash::check('reset-pass-1', $editor->password));

        $log = AuditLog::sole();
        $this->assertSame(AuditAction::UserUpdated, $log->action);
        // パスワードの値は残さない（JSON 型はキーを並べ替えることがあるため、順番は比べない）
        $this->assertEquals([
            'role' => ['from' => 'member', 'to' => 'admin'],
            'disabled' => ['from' => false, 'to' => true],
            'password_reset' => true,
        ], $log->detail);

        // 有効に戻す。役割は同じ値なので、履歴には無効化だけが残る
        $this->patchJson("/api/admin/users/{$this->editor->id}", ['role' => 'admin', 'disabled' => false])->assertOk();
        $this->assertFalse($this->editor->fresh()->isDisabled());
        $this->assertEquals(['disabled' => ['from' => true, 'to' => false]], AuditLog::latest('id')->first()->detail);

        // 何も変わらなければ、履歴は増えない
        $this->patchJson("/api/admin/users/{$this->editor->id}", ['role' => 'admin'])->assertOk();
        $this->assertSame(2, AuditLog::count());
    }

    public function test_admin_は自分自身の役割と状態を変えられない(): void
    {
        $this->actingAs($this->admin, 'web')
            ->patchJson("/api/admin/users/{$this->admin->id}", ['role' => 'member'])
            ->assertForbidden()
            ->assertJsonPath('message', '自分自身の役割や状態は、ここでは変えられません。');

        $this->patchJson("/api/admin/users/{$this->admin->id}", ['disabled' => true])->assertForbidden();

        $this->assertSame(UserRole::Admin, $this->admin->fresh()->role);
        $this->assertFalse($this->admin->fresh()->isDisabled());
    }

    public function test_無効化したユーザーは、正しいパスワードでもログインできない(): void
    {
        $this->editor->forceFill(['disabled_at' => now()])->save();

        $this->postJson('/api/login', ['email' => 'member@example.com', 'password' => 'secret-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'このアカウントは使えません。管理者に連絡してください。']);
        $this->assertGuest('web');

        // パスワードが違うときは、無効化されているかどうかを教えない
        $this->postJson('/api/login', ['email' => 'member@example.com', 'password' => 'wrong-password'])
            ->assertJsonValidationErrors(['email' => 'メールアドレスまたはパスワードが違います。']);

        $this->assertSame(0, AuditLog::count());
    }

    public function test_ログイン中に無効化されたユーザーは、次の操作で締め出される(): void
    {
        $this->postJson('/api/login', ['email' => 'member@example.com', 'password' => 'secret-password'])->assertOk();
        $this->getJson('/api/me')->assertOk();

        $this->editor->forceFill(['disabled_at' => now()])->save();
        // 本番ではリクエストごとにユーザーを DB から読み直す。テストでもガードが覚えている古いユーザーを捨てる（セッションは残す）
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'このアカウントは使えません。管理者に連絡してください。');
        $this->assertGuest('web');
    }

    public function test_自分のパスワードを変えるには、今のパスワードが要る(): void
    {
        $this->postJson('/api/login', ['email' => 'member@example.com', 'password' => 'secret-password'])->assertOk();
        AuditLog::query()->delete();

        $this->putJson('/api/me/password', ['current_password' => 'wrong-password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'パスワードが違います。']);

        $this->putJson('/api/me/password', ['current_password' => 'secret-password', 'password' => 'new-password-1', 'password_confirmation' => 'other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'パスワードが確認用の入力と一致しません。']);
        $this->assertSame(0, AuditLog::count());

        $this->putJson('/api/me/password', ['current_password' => 'secret-password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertNoContent();

        $this->assertTrue(Hash::check('new-password-1', $this->editor->fresh()->password));
        $log = AuditLog::sole();
        $this->assertSame(AuditAction::PasswordChanged, $log->action);
        $this->assertNull($log->detail);

        // 変えた本人のセッションは続く（Sanctum がセッションに持つパスワードのハッシュも新しくなる）
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me')->assertOk();
    }

    public function test_パスワードを再設定されると、その人のログイン中のセッションは切れる(): void
    {
        $this->postJson('/api/login', ['email' => 'member@example.com', 'password' => 'secret-password'])->assertOk();
        $this->getJson('/api/me')->assertOk();

        // 別の場所で admin が再設定した、という状況を DB の更新で作る
        $this->editor->forceFill(['password' => 'reset-pass-1'])->save();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/me')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_公開デモの_admin_は見るだけで、ユーザーを追加も変更もできない(): void
    {
        config(['demo.accounts_locked' => true]);
        $readOnly = '公開デモのため、管理画面は見るだけです（変更はできません）。';

        $this->actingAs($this->admin, 'web')
            ->getJson('/api/me')
            ->assertJsonPath('data.can.manage_users', true)
            ->assertJsonPath('data.can.create_users', false);

        // 一覧は見られる
        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('data.1.can.update', false)
            ->assertJsonPath('data.1.locked_reason', $readOnly);

        $this->postJson('/api/admin/users', $this->newUser())
            ->assertForbidden()
            ->assertJsonPath('message', $readOnly);
        $this->patchJson("/api/admin/users/{$this->approver->id}", ['disabled' => true])
            ->assertForbidden()
            ->assertJsonPath('message', $readOnly);
        $this->assertFalse($this->approver->fresh()->isDisabled());
        $this->assertDatabaseCount('users', 3);
    }

    public function test_公開デモでも、デモ用ではない_admin_はユーザーを追加できるが、デモ用アカウントは変えられない(): void
    {
        config(['demo.accounts_locked' => true]);
        $owner = User::factory()->create(['email' => 'owner@example.com', 'role' => UserRole::Admin]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/admin/users/{$this->editor->id}", ['disabled' => true])
            ->assertForbidden()
            ->assertJsonPath('message', 'デモ用のアカウントは変更できません。');
        $this->assertFalse($this->editor->fresh()->isDisabled());

        $this->getJson('/api/admin/users')
            ->assertJsonPath('data.0.can.update', false)
            ->assertJsonPath('data.0.locked_reason', 'デモ用のアカウントは変更できません。');

        // 新しく追加したユーザーは、ふつうに変えられる
        $id = $this->postJson('/api/admin/users', $this->newUser())->assertCreated()->json('data.id');
        $this->patchJson("/api/admin/users/{$id}", ['role' => 'admin'])->assertOk();

        // デモ用アカウントは、自分のパスワードも変えられない
        $this->as($this->editor)
            ->getJson('/api/me')
            ->assertJsonPath('data.can.change_password', false);
        $this->putJson('/api/me/password', ['current_password' => 'secret-password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertForbidden()
            ->assertJsonPath('message', 'デモ用のアカウントのパスワードは変更できません。');
        $this->assertTrue(Hash::check('secret-password', $this->editor->fresh()->password));
    }

    public function test_admin_にはユーザー管理の権限が付く(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/api/me')
            ->assertJsonPath('data.can.manage_users', true);
    }
}
