<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class CrawlHttpClient
{
    /**
     * @var array<int, string>
     */
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:137.0) Gecko/20100101 Firefox/137.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
    ];

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $options
     */
    public function get(
        string $url,
        array $headers = [],
        int $timeoutSeconds = 10,
        int $connectTimeoutSeconds = 10,
        array $options = [],
    ): Response {
        $request = function () use ($url, $headers, $timeoutSeconds, $connectTimeoutSeconds, $options): Response {
            return Http::withHeaders(array_merge([
                'User-Agent' => $this->randomUserAgent(),
            ], $headers))
                ->withOptions($options)
                ->connectTimeout($connectTimeoutSeconds)
                ->timeout($timeoutSeconds)
                ->get($url);
        };

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return $request();
        }

        try {
            $response = Redis::throttle('crawl-host:'.$host)
                ->allow(1)
                ->every(1)
                ->block(3)
                ->sleep(200)
                ->then(
                    callback: fn (): Response => $request(),
                    failure: function () use ($request, $host): Response {
                        Log::warning('[CrawlHttpClient] ドメイン単位レート制限で待機が上限に達したため、1秒待機後に実行します。', [
                            'host' => $host,
                        ]);

                        usleep(1_000_000);

                        return $request();
                    },
                );

            return $response instanceof Response ? $response : $request();
        } catch (Throwable $e) {
            Log::warning('[CrawlHttpClient] Redis throttle 実行中に例外が発生したため通常実行にフォールバックします。', [
                'host' => $host,
                'message' => $e->getMessage(),
            ]);

            return $request();
        }
    }

    private function randomUserAgent(): string
    {
        return self::USER_AGENTS[array_rand(self::USER_AGENTS)];
    }
}
