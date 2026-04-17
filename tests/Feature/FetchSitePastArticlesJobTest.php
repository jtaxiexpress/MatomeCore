<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\FetchSitePastArticlesJob;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FetchSitePastArticlesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_chunked_duplicate_check_splits_article_url_queries_into_batches_of_one_thousand(): void
    {
        $site = Site::factory()->create();

        $job = new FetchSitePastArticlesJob($site);
        $method = new \ReflectionMethod($job, 'pluckExistingUrlsChunked');
        $method->setAccessible(true);

        $chunkSizes = [];
        $articleMock = \Mockery::mock('alias:App\Models\Article');
        $articleMock->shouldReceive('whereIn')
            ->twice()
            ->andReturnUsing(function (string $column, array $chunk) use (&$chunkSizes) {
                $this->assertSame('url', $column);
                $chunkSizes[] = count($chunk);

                $existingUrls = [];

                if (in_array('https://example.com/articles/10', $chunk, true)) {
                    $existingUrls[] = 'https://example.com/articles/10';
                }

                if (in_array('https://example.com/articles/1010', $chunk, true)) {
                    $existingUrls[] = 'https://example.com/articles/1010';
                }

                return new class($existingUrls)
                {
                    /**
                     * @param  string[]  $urls
                     */
                    public function __construct(private array $urls) {}

                    public function pluck(string $column): self
                    {
                        return $this;
                    }

                    /**
                     * @return string[]
                     */
                    public function toArray(): array
                    {
                        return $this->urls;
                    }
                };
            });

        $candidateUrls = array_map(
            static fn (int $index): string => "https://example.com/articles/{$index}",
            range(1, 1501),
        );

        $existingUrls = $method->invoke($job, $candidateUrls);

        $this->assertSame([1000, 501], $chunkSizes);
        $this->assertSame([
            'https://example.com/articles/10',
            'https://example.com/articles/1010',
        ], $existingUrls);
    }
}
