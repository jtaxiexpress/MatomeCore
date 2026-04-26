<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\CrawlHttpClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
            ->chunk(25, function ($articles) use ($crawlHttpClient) {
                foreach ($articles as $article) {
                    $this->checkArticle($article, $crawlHttpClient);
                }
            });
    }

    /**
     * 1記事のリンク切れ判定を行い、削除またはチェック日時更新を実行する。
     */
    private function checkArticle(Article $article, CrawlHttpClient $crawlHttpClient): void
    {
        try {
            // 一般的なブラウザを偽装して通信（Soft 404検知のため中身を取得）
            $response = $crawlHttpClient->get(
                url: $article->url,
                timeoutSeconds: 10,
            );

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

                return;
            }
        } catch (\Exception $e) {
            // タイムアウト・ネットワークエラー等は一時的な障害の可能性があるため削除しない
        }

        // 正常、または一時的な相手サーバーの500エラー等はスキップして後回し
        $article->update(['last_checked_at' => now()]);
    }
}
