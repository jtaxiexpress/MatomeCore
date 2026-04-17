<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Livewire\Filament\ScopedDatabaseNotifications;
use App\Models\App as AppModel;
use App\Models\User;
use App\Notifications\FilamentDatabaseNotification;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Livewire\Livewire;
use Tests\TestCase;

class DatabaseNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_app_panel_only_shows_notifications_for_current_tenant(): void
    {
        $appOne = AppModel::factory()->create(['api_slug' => 'app-one']);
        $appTwo = AppModel::factory()->create(['api_slug' => 'app-two']);

        $user = User::factory()->create(['is_admin' => false]);
        $user->apps()->attach([$appOne->id, $appTwo->id]);

        $this->sendDatabaseNotification($user, 'Global', null);
        $this->sendDatabaseNotification($user, 'App One', $appOne->id);
        $this->sendDatabaseNotification($user, 'App Two', $appTwo->id);

        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($appOne, isQuiet: true);

        $livewire = Livewire::test(ScopedDatabaseNotifications::class);
        $livewire->assertSee('一括チェック');
        $notifications = $livewire->instance()->getNotifications();
        $items = $this->notificationItems($notifications);

        $this->assertCount(1, $items);
        $this->assertSame($appOne->id, (int) data_get($items[0], 'data.app_id'));
    }

    public function test_admin_panel_shows_all_user_notifications(): void
    {
        $app = AppModel::factory()->create(['api_slug' => 'app-main']);
        $user = User::factory()->admin()->create();

        $this->sendDatabaseNotification($user, 'Global', null);
        $this->sendDatabaseNotification($user, 'Scoped', $app->id);

        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant(null, isQuiet: true);

        $livewire = Livewire::test(ScopedDatabaseNotifications::class);
        $notifications = $livewire->instance()->getNotifications();
        $items = $this->notificationItems($notifications);

        $this->assertCount(2, $items);
    }

    private function sendDatabaseNotification(User $user, string $title, ?int $appId): void
    {
        $payload = FilamentNotification::make()
            ->title($title)
            ->getDatabaseMessage();

        $payload['app_id'] = $appId;

        $user->notify(new FilamentDatabaseNotification($payload));
    }

    /**
     * @return array<int, mixed>
     */
    private function notificationItems(DatabaseNotificationCollection|Paginator $notifications): array
    {
        if ($notifications instanceof Paginator) {
            return array_values($notifications->items());
        }

        return array_values($notifications->all());
    }
}
