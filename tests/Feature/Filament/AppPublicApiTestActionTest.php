<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\AppResource\Pages\ManageApps;
use App\Models\App as AppModel;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications as NotificationsComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppPublicApiTestActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_config_response_from_public_api_action(): void
    {
        $admin = User::factory()->admin()->create();
        $app = AppModel::factory()->create([
            'name' => 'Demo App',
            'api_slug' => 'demo-app',
            'theme_color' => '#2563EB',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageApps::class)
            ->callAction(TestAction::make('test_public_api')->table($app), data: [
                'api_type' => 'config',
            ]);

        $notificationsComponent = new NotificationsComponent;
        $notificationsComponent->mount();

        $notification = $notificationsComponent->notifications->first();

        $this->assertNotNull($notification);
        $this->assertSame('APIレスポンスを取得しました', $notification->toArray()['title']);
        $this->assertStringContainsString('"name": "Demo App"', (string) $notification->toArray()['body']);
        $this->assertStringContainsString('"theme_color": "#2563EB"', (string) $notification->toArray()['body']);
    }

    public function test_public_api_action_can_preview_feed_by_category(): void
    {
        $admin = User::factory()->admin()->create();
        $app = AppModel::factory()->create(['api_slug' => 'feed-preview-app', 'is_active' => true]);

        $tech = Category::factory()->for($app)->create(['name' => 'Tech', 'api_slug' => 'tech']);
        $sports = Category::factory()->for($app)->create(['name' => 'Sports', 'api_slug' => 'sports']);
        $site = Site::factory()->for($app)->create(['name' => 'Preview Media']);

        Article::factory()->for($app)->for($tech)->for($site)->create([
            'title' => 'AI Update',
            'url' => 'https://example.com/ai-update',
        ]);

        Article::factory()->for($app)->for($sports)->for($site)->create([
            'title' => 'Sports Update',
            'url' => 'https://example.com/sports-update',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageApps::class)
            ->callAction(TestAction::make('test_public_api')->table($app), data: [
                'api_type' => 'feed',
                'category_slug' => 'tech',
            ]);

        $notificationsComponent = new NotificationsComponent;
        $notificationsComponent->mount();

        $notification = $notificationsComponent->notifications->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('"title": "AI Update"', (string) $notification->toArray()['body']);
        $this->assertStringNotContainsString('Sports Update', (string) $notification->toArray()['body']);
    }
}
