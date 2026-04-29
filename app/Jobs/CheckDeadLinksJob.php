<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\CrawlHttpClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CheckDeadLinksJob implements ShouldQueue
{
    use Queueable;

    /** ジョブのタイムアウト（秒）。500件 × 最大10秒 + 余裕 */
    public int $timeout = 3600;

    /**
     * ブログサービスごとのSoft 404エラー文言リスト。
     * 今後新しいサービスに対応する場合はここに追加するだけでよい。
     *
     * @var string[]
     */
    private array $soft404Phrases = [
        'お探しのページが見つかりませんでした',    // ライブドアブログ等
        '指定されたページまたは記事は存在しません',
        'このページは存在しないか、すでに削除されています',
        'このサイトにアクセスできません',
    ];

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    /**
     * Execute the job.
     */
    public function handle(?CrawlHttpClient $crawlHttpClient = null): void
    {
        $crawlHttpClient ??= app(CrawlHttpClient::class);

        // chunk で25件ずつ処理し、GETレスポンス本文による一括メモリ消費を防ぐ
        Article::orderByRaw('last_checked_at IS NOT NULL ASC')
            ->orderBy('published_at', 'asc')
            ->limit(100)
            ->chunk(25, function ($articles) {
                $this->checkArticlesConcurrent($articles);
            });
    }

    /**
     * チャンク内の記事を完全に並列でリンク切れ判定する。
     */
    private function checkArticlesConcurrent(Collection $articles): void
    {
        $responses = Http::pool(function (Pool $pool) use ($articles) {
            $poolRequests = [];
            foreach ($articles as $article) {
                // 一般的なブラウザのUser-Agentを偽装して設定
                $poolRequests[] = $pool->as((string) $article->id)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
                    ])
                    ->timeout(10)
                    ->get($article->url);
            }

            return $poolRequests;
        });

        foreach ($articles as $article) {
            $response = $responses[(string) $article->id] ?? null;

            if ($response instanceof Response) {
                $isDead = false;

                // ハード404 (完全に削除されている)
                if ($response->status() === 404 || $response->status() === 410) {
                    $isDead = true;
                } elseif ($response->successful()) {
                    // ソフト404 (ステータスは200等だが、中身がエラー画面)
                    $body = $response->body();
                    foreach ($this->soft404Phrases as $phrase) {
                        if (str_contains($body, $phrase)) {
                            $isDead = true;
                            break;
                        }
                    }
                }

                if ($isDead) {
                    $article->delete();

                    continue;
                }
            }

            // 正常、または一時的な相手サーバーの500エラー等はスキップして後回し
            // 例外（タイムアウト等）が発生した場合も、$response が Response オブジェクトではないのでここへ来る
            $article->update(['last_checked_at' => now()]);
        }
    }
}
