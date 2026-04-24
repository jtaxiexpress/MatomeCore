<?php

namespace Tests\Feature;

use App\Models\AboutSection;
use App\Models\App;
use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AboutSectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_about_page_loads_successfully(): void
    {
        $this->get('/about')->assertOk();
    }

    public function test_about_page_shows_visible_sections(): void
    {
        AboutSection::factory()->create([
            'title' => '公開セクション',
            'is_visible' => true,
        ]);

        AboutSection::factory()->create([
            'title' => '非公開セクション',
            'is_visible' => false,
        ]);

        $this->get('/about')
            ->assertOk()
            ->assertSee('公開セクション')
            ->assertDontSee('非公開セクション');
    }

    public function test_about_page_shows_sections_in_sort_order(): void
    {
        AboutSection::factory()->create(['title' => '2番目', 'sort_order' => 20, 'is_visible' => true]);
        AboutSection::factory()->create(['title' => '1番目', 'sort_order' => 10, 'is_visible' => true]);

        $response = $this->get('/about')->assertOk();
        $content = $response->getContent();

        $this->assertNotFalse(strpos($content, '1番目'));
        $this->assertNotFalse(strpos($content, '2番目'));
        $this->assertLessThan(
            strpos($content, '2番目'),
            strpos($content, '1番目'),
            '1番目 should appear before 2番目'
        );
    }

    public function test_about_section_can_be_created(): void
    {
        $section = AboutSection::factory()->create([
            'title' => 'テストセクション',
            'content' => '<p>テスト本文</p>',
            'sort_order' => 5,
            'is_visible' => true,
        ]);

        $this->assertModelExists($section);
        $this->assertEquals('テストセクション', $section->title);
        $this->assertTrue($section->is_visible);
    }

    public function test_ranking_page_loads_successfully(): void
    {
        $this->get('/ranking')->assertOk();
    }

    public function test_ranking_page_shows_loading_skeleton_markup(): void
    {
        $this->get('/ranking')
            ->assertOk()
            ->assertSee('wire:loading.delay.short', false)
            ->assertSee('animate-pulse', false)
            ->assertSee('opacity-60', false);
    }

    public function test_rss_list_page_loads_successfully(): void
    {
        $this->get('/rss-list')->assertOk();
    }

    public function test_rss_list_page_shows_copy_feedback_markup(): void
    {
        App::factory()->create(['is_active' => true]);

        $this->get('/rss-list')
            ->assertOk()
            ->assertSee('Copied!', false);
    }

    public function test_rss_list_page_hides_category_jump_ui(): void
    {
        App::factory()->create(['is_active' => true]);

        $this->get('/rss-list')
            ->assertOk()
            ->assertDontSee('カテゴリに移動:', false)
            ->assertDontSee('📱', false);
    }

    public function test_sites_index_page_loads_successfully(): void
    {
        $this->get('/sites')->assertOk();
    }

    public function test_sites_index_page_shows_copy_feedback_markup(): void
    {
        Cache::flush();

        $app = App::factory()->create(['is_active' => true]);
        Site::factory()->recycle($app)->create([
            'url' => 'https://example.com',
            'rss_url' => 'https://example.com/feed',
        ]);

        $this->get('/sites')
            ->assertOk()
            ->assertDontSee('当アンテナで記事を収集させていただいている登録サイトの一覧です。', false)
            ->assertDontSee('サイト名をクリックするとサイトへ、📋 をクリックするとRSSのURLをコピーできます。', false)
            ->assertDontSee('📱', false)
            ->assertDontSee('カテゴリRSS:', false);
    }
}
