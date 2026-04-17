<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Support\AdminScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserAdminScreenPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_user_form_populates_admin_screen_permissions_for_system_admins(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_screen_permissions' => AdminScreen::selectableValues(),
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ManageUsers::class)
            ->mountAction('create');

        $component
            ->assertActionMounted('create')
            ->assertFormFieldHidden('admin_screen_permissions')
            ->fillForm([
                'is_admin' => true,
            ])
            ->assertFormFieldVisible('admin_screen_permissions')
            ->fillForm([
                'name' => 'Screen Admin',
                'email' => 'screen-admin@example.com',
                'password' => 'password',
                'admin_screen_permissions' => [
                    AdminScreen::AppManagement->value,
                    AdminScreen::SystemSettings->value,
                ],
            ])
            ->callMountedAction();

        $user = User::query()->where('email', 'screen-admin@example.com')->firstOrFail();

        $this->assertTrue($user->is_admin);
        $this->assertSame([
            AdminScreen::AppManagement->value,
            AdminScreen::SystemSettings->value,
        ], $user->admin_screen_permissions);
    }
}
