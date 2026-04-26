<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogViewerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_log_viewer(): void
    {
        $response = $this->get(route('log-viewer.index'));

        $this->assertContains($response->getStatusCode(), [302, 403]);
    }

    public function test_authenticated_user_without_admin_permission_cannot_access_log_viewer(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('log-viewer.index'));

        $response->assertForbidden();
    }

    public function test_admin_user_with_log_viewer_permission_can_access_log_viewer(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_screen_permissions' => [AdminScreen::LogViewer->value],
        ]);

        $response = $this->actingAs($admin)->get(route('log-viewer.index'));

        $response->assertOk();
    }
}
