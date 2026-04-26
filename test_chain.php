<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Bus;
use App\Jobs\ProcessArticleJob;

Bus::fake();
$job = new ProcessArticleJob(1, 'https://example.com/articles/123', ['title' => 'テスト記事'], 'rss');
$job->handle();

$jobs = Bus::dispatched(\App\Jobs\ScrapeArticleJob::class);
dump($jobs);
