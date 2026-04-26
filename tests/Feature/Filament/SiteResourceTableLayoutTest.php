<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\AppResource\Pages\ManageApps;
use App\Filament\Resources\AppResource\RelationManagers\SitesRelationManager;
use App\Filament\Resources\SiteResource\Pages\ManageSites;
use App\Models\App as AppModel;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SiteResourceTableLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_resource_table_uses_requested_fixed_columns(): void
    {
        $admin = User::factory()->admin()->create();

        $columns = Livewire::actingAs($admin)
            ->test(ManageSites::class)
            ->instance()
            ->getTable()
            ->getColumns();

        $this->assertSame([
            'name',
            'url',
            'articles_count',
            'articles_max_created_at',
            'is_active',
        ], array_slice(array_keys($columns), 0, 5));

        $this->assertClickableUrlColumn($columns['url']);
    }

    public function test_app_sites_relation_manager_uses_requested_fixed_columns(): void
    {
        $admin = User::factory()->admin()->create();
        $app = AppModel::factory()->create();

        $columns = Livewire::actingAs($admin)
            ->test(SitesRelationManager::class, [
                'ownerRecord' => $app,
                'pageClass' => ManageApps::class,
            ])
            ->instance()
            ->getTable()
            ->getColumns();

        $this->assertSame([
            'name',
            'url',
            'articles_count',
            'articles_max_created_at',
            'is_active',
        ], array_keys($columns));

        $this->assertClickableUrlColumn($columns['url']);
    }

    private function assertClickableUrlColumn(TextColumn $column): void
    {
        $this->assertSame('https://example.com', $column->getUrl('https://example.com'));
        $this->assertTrue($column->shouldOpenUrlInNewTab());
    }
}
