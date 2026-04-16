<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\App as AppModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 一般ユーザーはadminパネルへアクセスできない。
     */
    public function test_non_admin_user_is_blocked_from_admin_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/admin');

        $response->assertForbidden();
    }

    /**
     * 管理者ユーザーはadminパネルへアクセスできる。
     */
    public function test_admin_user_can_access_admin_panel(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
    }

    /**
     * App1所属ユーザーはApp2テナントへアクセスできない。
     */
    public function test_user_cannot_access_unassigned_tenant_panel(): void
    {
        $appOne = AppModel::factory()->create(['api_slug' => 'app-one']);
        $appTwo = AppModel::factory()->create(['api_slug' => 'app-two']);

        $user = User::factory()->create();
        $user->apps()->attach($appOne);

        $this->actingAs($user)
            ->get('/app/'.$appOne->api_slug)
            ->assertOk();

        $this->actingAs($user)
            ->get('/app/'.$appTwo->api_slug)
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/app/'.$appTwo->api_slug.'/articles')
            ->assertNotFound();
    }

    /**
     * 管理者は割当なしでも全テナントへアクセスできる。
     */
    public function test_admin_user_can_access_any_tenant_panel(): void
    {
        $app = AppModel::factory()->create(['api_slug' => 'shared-app']);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/app/'.$app->api_slug)
            ->assertOk();
    }
}
