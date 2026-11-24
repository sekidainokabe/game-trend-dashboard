<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 操作履歴の一覧（GET /api/audit-logs）と、絞り込み用の利用者一覧（GET /api/users）
 */
class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        AuditLog::query()->delete();
        $this->member = User::where('role', UserRole::Member)->firstOrFail();
        $this->admin = User::where('role', UserRole::Admin)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    private function log(User $user, AuditAction $action, User $target, string $createdAt = '2026-11-13 03:00:00', ?array $detail = null): AuditLog
    {
        $log = AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'target_type' => 'user',
            'target_id' => $target->id,
            'detail' => $detail,
        ]);
        $log->forceFill(['created_at' => $createdAt])->save();

        return $log;
    }

    public function test_adminだけが見られる(): void
    {
        $this->actingAs($this->member)->getJson('/api/audit-logs')->assertForbidden();
        $this->actingAs($this->member)->getJson('/api/users')->assertForbidden();
        $this->actingAs($this->member)->getJson('/api/me')->assertJsonPath('data.can.view_audit_logs', false);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->getJson('/api/audit-logs')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/me')->assertJsonPath('data.can.view_audit_logs', true);
    }

    public function test_新しい順に、利用者と操作名と対象のユーザー名を付けて返す(): void
    {
        $this->log($this->member, AuditAction::Login, $this->member, '2026-11-13 03:00:00');
        $this->log($this->admin, AuditAction::UserUpdated, $this->member, '2026-11-13 04:00:00', ['role' => ['from' => 'member', 'to' => 'admin']]);

        $this->actingAs($this->admin)->getJson('/api/audit-logs')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.action', 'user.update')
            ->assertJsonPath('data.0.action_label', 'ユーザーの変更')
            ->assertJsonPath('data.0.target', ['type' => 'user', 'id' => $this->member->id, 'title' => $this->member->name])
            ->assertJsonPath('data.0.user', ['id' => $this->admin->id, 'name' => $this->admin->name])
            ->assertJsonPath('data.1.action', 'login');
    }

    public function test_利用者と操作で絞り込める(): void
    {
        $this->log($this->member, AuditAction::Login, $this->member);
        $this->log($this->member, AuditAction::PasswordChanged, $this->member);
        $this->log($this->admin, AuditAction::Login, $this->admin);

        $this->actingAs($this->admin)->getJson("/api/audit-logs?user_id={$this->member->id}")
            ->assertJsonPath('meta.total', 2);
        $this->actingAs($this->admin)->getJson('/api/audit-logs?action=login')
            ->assertJsonPath('meta.total', 2);
        $this->actingAs($this->admin)->getJson("/api/audit-logs?user_id={$this->member->id}&action=user.password")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'user.password');
    }

    public function test_期間は画面から受け取った日時で絞り込む(): void
    {
        // 日本時間の 11/13 は、UTC の 11/12 15:00 から 11/13 15:00 まで
        $this->log($this->member, AuditAction::Login, $this->member, '2026-11-12 14:59:59');
        $inside = $this->log($this->member, AuditAction::Login, $this->member, '2026-11-12 15:00:00');
        $this->log($this->member, AuditAction::Login, $this->member, '2026-11-13 15:00:00');

        $query = http_build_query(['from' => '2026-11-13T00:00:00+09:00', 'to' => '2026-11-14T00:00:00+09:00']);
        $this->actingAs($this->admin)->getJson("/api/audit-logs?{$query}")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $inside->id);
    }

    public function test_おかしな条件は422(): void
    {
        $this->actingAs($this->admin)->getJson('/api/audit-logs?action=unknown')
            ->assertUnprocessable()->assertJsonValidationErrors('action');

        $query = http_build_query(['from' => '2026-11-14T00:00:00+09:00', 'to' => '2026-11-13T00:00:00+09:00']);
        $this->actingAs($this->admin)->getJson("/api/audit-logs?{$query}")
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_利用者一覧はメールアドレスを返さない(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/users')->assertOk();

        $response->assertJsonPath('data.0', ['id' => $this->member->id, 'name' => $this->member->name, 'role' => 'member']);
        $this->assertCount(User::count(), $response->json('data'));
    }
}
