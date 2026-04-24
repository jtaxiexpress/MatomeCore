<?php

namespace Tests\Feature;

use App\Models\AboutSection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

    public function test_rss_list_page_loads_successfully(): void
    {
        $this->get('/rss-list')->assertOk();
    }

    public function test_sites_index_page_loads_successfully(): void
    {
        $this->get('/sites')->assertOk();
    }
}
