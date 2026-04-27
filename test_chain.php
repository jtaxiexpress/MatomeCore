<?php

require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Jobs\ProcessArticleJob;
use App\Jobs\ScrapeArticleJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;

Bus::fake();
$job = new ProcessArticleJob(1, 'https://example.com/articles/123', ['title' => 'テスト記事'], 'rss');
$job->handle();

$jobs = Bus::dispatched(ScrapeArticleJob::class);
dump($jobs);
