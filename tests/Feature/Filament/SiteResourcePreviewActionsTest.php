<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\SiteResource\Pages\ManageSites;
use App\Models\App as AppModel;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications as NotificationsComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SiteResourcePreviewActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rss_preview_skips_ng_urls_and_limits_results_after_filtering(): void
    {
        $admin = User::factory()->admin()->create();
        $appModel = AppModel::factory()->create();

        $site = Site::factory()->for($appModel)->create([
            'rss_url' => 'https://example.com/feed.xml',
            'ng_url_keywords' => ['skip'],
            'ng_image_urls' => ['https://example.com/ng-thumb.jpg'],
        ]);

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($request->url() === 'https://example.com/feed.xml') {
                return Http::response($this->buildRssFeed(array_map(
                    static function (int $index): array {
                        $isSkipped = $index <= 2;

                        return [
                            'title' => '記事'.$index,
                            'url' => $isSkipped
                                ? "https://example.com/skip-{$index}"
                                : "https://example.com/post-{$index}",
                            'image' => "https://example.com/image-{$index}.jpg",
                            'date' => Carbon::parse('2026-04-18 10:00:00')->addMinutes($index)->toRfc2822String(),
                        ];
                    },
                    range(1, 12),
                )));
            }

            return Http::response(null, 404);
        });

        Livewire::actingAs($admin)
            ->test(ManageSites::class)
            ->mountAction(TestAction::make('test_rss_fetch')->table($site))
            ->assertMountedActionModalSeeHtml('https://example.com/post-12')
            ->assertMountedActionModalDontSeeHtml('https://example.com/skip-1')
            ->assertMountedActionModalDontSeeHtml('https://example.com/skip-2');
    }

    public function test_rss_preview_uses_scraped_images_and_marks_ng_images_as_excluded(): void
    {
        $admin = User::factory()->admin()->create();
        $appModel = AppModel::factory()->create();

        $site = Site::factory()->for($appModel)->create([
            'rss_url' => 'https://example.com/feed.xml',
            'ng_image_urls' => ['https://example.com/ng-thumb.jpg'],
        ]);

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://example.com/feed.xml' => Http::response($this->buildRssFeed([
                    [
                        'title' => '記事1',
                        'url' => 'https://example.com/articles/1',
                        'image' => 'https://example.com/ng-thumb.jpg',
                        'date' => 'Fri, 18 Apr 2026 10:00:00 +0900',
                    ],
                    [
                        'title' => '記事2',
                        'url' => 'https://example.com/articles/2',
                        'image' => null,
                        'date' => 'Fri, 18 Apr 2026 10:01:00 +0900',
                    ],
                ])),
                'https://example.com/articles/1' => Http::response($this->buildArticleHtml(
                    title: '記事1',
                    imageUrl: 'https://example.com/fallback-thumb.jpg',
                )),
                'https://example.com/articles/2' => Http::response($this->buildArticleHtml(
                    title: '記事2',
                    imageUrl: 'https://example.com/ng-thumb.jpg',
                )),
                default => Http::response(null, 404),
            };
        });

        Livewire::actingAs($admin)
            ->test(ManageSites::class)
            ->mountAction(TestAction::make('test_rss_fetch')->table($site))
            ->assertMountedActionModalSeeHtml('https://example.com/fallback-thumb.jpg')
            ->assertMountedActionModalSeeHtml('なし (NGサムネイル画像として除外)');
    }

    public function test_crawl_preview_marks_ng_thumbnail_images_as_excluded(): void
    {
        $admin = User::factory()->admin()->create();
        $appModel = AppModel::factory()->create();

        $site = Site::factory()->for($appModel)->create([
            'crawl_start_url' => 'https://example.com/list',
            'crawler_type' => 'html',
            'link_selector' => 'a.article-link',
            'ng_image_urls' => ['https://example.com/ng-thumb.jpg'],
        ]);

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://example.com/list' => Http::response(<<<'HTML'
<html>
    <body>
        <a class="article-link" href="https://example.com/articles/1">記事1</a>
        <a class="article-link" href="https://example.com/articles/2">記事2</a>
        <a class="article-link" href="https://example.com/articles/3">記事3</a>
    </body>
</html>
HTML),
                'https://example.com/articles/1' => Http::response($this->buildArticleHtml(
                    title: '記事1',
                    imageUrl: 'https://example.com/allowed-thumb.jpg',
                )),
                'https://example.com/articles/2' => Http::response($this->buildArticleHtml(
                    title: '記事2',
                    imageUrl: 'https://example.com/ng-thumb.jpg',
                )),
                'https://example.com/articles/3' => Http::response($this->buildArticleHtml(
                    title: '記事3',
                    imageUrl: 'https://example.com/allowed-thumb-2.jpg',
                )),
                default => Http::response(null, 404),
            };
        });

        Livewire::actingAs($admin)
            ->test(ManageSites::class)
            ->callAction(TestAction::make('test_crawl')->table($site));

        $notificationsComponent = new NotificationsComponent;
        $notificationsComponent->mount();

        $notification = $notificationsComponent->notifications->first();

        $this->assertNotNull($notification);
        $this->assertSame('URL抽出テストに成功しました 【抽出件数】 3件', $notification->toArray()['title']);
        $this->assertStringContainsString('なし (NGサムネイル画像として除外)', (string) $notification->toArray()['body']);
        $this->assertStringContainsString('https://example.com/allowed-thumb.jpg', (string) $notification->toArray()['body']);
    }

    /**
     * @param  array<int, array{title: string, url: string, image: ?string, date: string}>  $items
     */
    private function buildRssFeed(array $items): string
    {
        $itemXml = collect($items)
            ->map(function (array $item): string {
                $imageXml = $item['image'] !== null
                    ? '<enclosure url="'.htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8').'" type="image/jpeg" />'
                    : '';

                return <<<XML
<item>
    <title>{$item['title']}</title>
    <link>{$item['url']}</link>
    <pubDate>{$item['date']}</pubDate>
    {$imageXml}
</item>
XML;
            })
            ->implode('');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>テストフィード</title>
        {$itemXml}
    </channel>
</rss>
XML;
    }

    private function buildArticleHtml(string $title, string $imageUrl): string
    {
        return <<<HTML
<html>
    <head>
        <title>{$title}</title>
        <meta property="og:title" content="{$title}">
        <meta property="og:image" content="{$imageUrl}">
        <meta property="article:published_time" content="2026-04-18T10:00:00+09:00">
    </head>
    <body>
        <article>
            <time datetime="2026-04-18T10:00:00+09:00">2026-04-18</time>
        </article>
    </body>
</html>
HTML;
    }
}
