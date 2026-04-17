<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\AppResource\Pages\ManageApps;
use App\Models\App as AppModel;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_header_links_to_admin_home(): void
    {
        $panel = (new AdminPanelProvider($this->app))->panel(Panel::make());

        $this->assertSame('/admin', $panel->getHomeUrl());
    }

    public function test_app_rows_link_to_app_panel_and_keep_edit_action(): void
    {
        $admin = User::factory()->admin()->create();
        $app = AppModel::factory()->create();

        $component = Livewire::actingAs($admin)
            ->test(ManageApps::class)
            ->instance();

        $table = $component->getTable();

        $this->assertSame(Filament::getPanel('app')->getUrl($app), $table->getRecordUrl($app));
        $this->assertTrue(collect($table->getActions())->contains(static fn (EditAction $action): bool => $action instanceof EditAction));
    }

    public function test_app_table_uses_requested_fixed_columns(): void
    {
        $admin = User::factory()->admin()->create();

        $table = Livewire::actingAs($admin)
            ->test(ManageApps::class)
            ->instance()
            ->getTable();

        $this->assertSame([
            'icon_path',
            'name',
            'api_slug',
            'sites_count',
            'articles_count',
            'articles_max_created_at',
            'theme_color',
        ], array_keys($table->getColumns()));
    }
}
