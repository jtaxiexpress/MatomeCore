<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource\Pages\ManageArticles;
use App\Filament\Resources\CategoryResource\Pages\ManageCategories;
use App\Models\App as AppModel;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryAndArticleActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Filament::setTenant(null, isQuiet: true);

        parent::tearDown();
    }

    public function test_category_delete_action_reassigns_articles_before_deleting_the_category(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $site = Site::factory()->for($tenant, 'app')->create();
        $sourceCategory = Category::factory()->for($tenant, 'app')->create(['name' => '削除対象']);
        $replacementCategory = Category::factory()->for($tenant, 'app')->create(['name' => '移動先']);
        $article = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create([
            'title' => '元の記事',
        ]);

        Filament::setTenant($tenant, isQuiet: true);

        Livewire::actingAs($admin)
            ->test(ManageCategories::class)
            ->callAction(TestAction::make('delete')->table($sourceCategory), data: [
                'replacement_category_id' => $replacementCategory->id,
            ])
            ->assertNotified('カテゴリを削除しました（1件の記事を移動）');

        $this->assertModelMissing($sourceCategory);

        $article->refresh();
        $this->assertSame($replacementCategory->id, $article->category_id);
    }

    public function test_category_delete_action_requires_replacement_when_articles_exist(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $site = Site::factory()->for($tenant, 'app')->create();
        $sourceCategory = Category::factory()->for($tenant, 'app')->create();
        Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create();

        Filament::setTenant($tenant, isQuiet: true);

        Livewire::actingAs($admin)
            ->test(ManageCategories::class)
            ->callAction(TestAction::make('delete')->table($sourceCategory), data: [])
            ->assertHasFormErrors([
                'replacement_category_id' => 'required',
            ]);

        $this->assertModelExists($sourceCategory);
    }

    public function test_category_delete_action_deletes_without_replacement_when_no_articles_exist(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $sourceCategory = Category::factory()->for($tenant, 'app')->create();

        Filament::setTenant($tenant, isQuiet: true);

        Livewire::actingAs($admin)
            ->test(ManageCategories::class)
            ->callAction(TestAction::make('delete')->table($sourceCategory), data: [])
            ->assertNotified('カテゴリを削除しました');

        $this->assertModelMissing($sourceCategory);
    }

    public function test_article_bulk_action_changes_categories_for_selected_articles(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $site = Site::factory()->for($tenant, 'app')->create();
        $sourceCategory = Category::factory()->for($tenant, 'app')->create(['name' => '元カテゴリ']);
        $newCategory = Category::factory()->for($tenant, 'app')->create(['name' => '新カテゴリ']);
        $firstArticle = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create([
            'title' => '記事1',
        ]);
        $secondArticle = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create([
            'title' => '記事2',
        ]);

        Filament::setTenant($tenant, isQuiet: true);

        Livewire::actingAs($admin)
            ->test(ManageArticles::class)
            ->selectTableRecords([$firstArticle->id, $secondArticle->id])
            ->callAction(TestAction::make('changeCategory')->table()->bulk(), data: [
                'new_category_id' => $newCategory->id,
            ])
            ->assertNotified('2件の記事のカテゴリを変更しました');

        $firstArticle->refresh();
        $secondArticle->refresh();

        $this->assertSame($newCategory->id, $firstArticle->category_id);
        $this->assertSame($newCategory->id, $secondArticle->category_id);
    }

    public function test_category_bulk_delete_action_reassigns_articles_before_deleting_parent_and_child_categories(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $site = Site::factory()->for($tenant, 'app')->create();
        $parentCategory = Category::factory()->for($tenant, 'app')->create(['name' => '親カテゴリ']);
        $childCategory = Category::factory()->for($tenant, 'app')->create([
            'name' => '子カテゴリ',
            'parent_id' => $parentCategory->id,
        ]);
        $replacementCategory = Category::factory()->for($tenant, 'app')->create(['name' => '移動先']);

        $parentArticle = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($parentCategory, 'category')->create();
        $childArticle = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($childCategory, 'category')->create();

        Filament::setTenant($tenant, isQuiet: true);

        Livewire::actingAs($admin)
            ->test(ManageCategories::class)
            ->selectTableRecords([$parentCategory->id])
            ->callAction(TestAction::make('delete')->table()->bulk(), data: [
                'replacement_category_id' => $replacementCategory->id,
            ])
            ->assertNotified('1件のカテゴリを削除し、2件の記事を移動しました');

        $this->assertModelMissing($parentCategory);
        $this->assertModelMissing($childCategory);

        $parentArticle->refresh();
        $childArticle->refresh();

        $this->assertSame($replacementCategory->id, $parentArticle->category_id);
        $this->assertSame($replacementCategory->id, $childArticle->category_id);
    }

    public function test_category_bulk_delete_action_deletes_categories_without_replacement_when_no_articles_exist(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $parentCategory = Category::factory()->for($tenant, 'app')->create(['name' => '親カテゴリ']);
        $childCategory = Category::factory()->for($tenant, 'app')->create([
            'name' => '子カテゴリ',
            'parent_id' => $parentCategory->id,
        ]);

        Filament::setTenant($tenant, isQuiet: true);

        Livewire::actingAs($admin)
            ->test(ManageCategories::class)
            ->selectTableRecords([$parentCategory->id])
            ->callAction(TestAction::make('delete')->table()->bulk(), data: [])
            ->assertNotified('1件のカテゴリを削除しました');

        $this->assertModelMissing($parentCategory);
        $this->assertModelMissing($childCategory);
    }

    public function test_category_bulk_delete_action_stops_when_replacement_category_is_in_selected_records(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $site = Site::factory()->for($tenant, 'app')->create();
        $sourceCategory = Category::factory()->for($tenant, 'app')->create(['name' => '削除対象']);
        $replacementCategory = Category::factory()->for($tenant, 'app')->create(['name' => '移動先']);
        $article = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create();

        Filament::setTenant($tenant, isQuiet: true);

        Livewire::actingAs($admin)
            ->test(ManageCategories::class)
            ->selectTableRecords([$sourceCategory->id, $replacementCategory->id])
            ->callAction(TestAction::make('delete')->table()->bulk(), data: [
                'replacement_category_id' => $replacementCategory->id,
            ])
            ->assertHasFormErrors([
                'replacement_category_id' => 'in',
            ]);

        $this->assertModelExists($sourceCategory);
        $this->assertModelExists($replacementCategory);

        $article->refresh();
        $this->assertSame($sourceCategory->id, $article->category_id);
    }

    public function test_article_bulk_action_requires_destination_category(): void
    {
        $admin = User::factory()->admin()->create();
        $tenant = AppModel::factory()->create();
        $site = Site::factory()->for($tenant, 'app')->create();
        $sourceCategory = Category::factory()->for($tenant, 'app')->create();
        $article = Article::factory()->for($tenant, 'app')->for($site, 'site')->for($sourceCategory, 'category')->create();

        Filament::setTenant($tenant, isQuiet: true);

        Livewire::actingAs($admin)
            ->test(ManageArticles::class)
            ->selectTableRecords([$article->id])
            ->callAction(TestAction::make('changeCategory')->table()->bulk(), data: [
                'new_category_id' => null,
            ])
            ->assertHasFormErrors([
                'new_category_id' => 'required',
            ]);
    }
}
