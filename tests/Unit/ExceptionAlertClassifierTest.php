<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Slack\ExceptionAlertClassifier;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ExceptionAlertClassifierTest extends TestCase
{
    public function test_it_detects_database_connection_errors(): void
    {
        $previous = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        $exception = new QueryException('mysql', 'select 1', [], $previous);

        $classifier = new ExceptionAlertClassifier;

        $this->assertTrue($classifier->shouldNotify($exception));
    }

    public function test_it_detects_gemini_rate_limit_errors(): void
    {
        $exception = new RuntimeException('Gemini API returned HTTP 429 Too Many Requests from generativelanguage.googleapis.com');

        $classifier = new ExceptionAlertClassifier;

        $this->assertTrue($classifier->shouldNotify($exception));
    }

    public function test_it_detects_external_api_timeout_errors(): void
    {
        $exception = new RuntimeException('Crawl4AI request failed: cURL error 28: Operation timed out after 60001 milliseconds');

        $classifier = new ExceptionAlertClassifier;

        $this->assertTrue($classifier->shouldNotify($exception));
    }

    public function test_it_excludes_ollama_connection_errors(): void
    {
        $exception = new RuntimeException('cURL error 7: Failed to connect to host.docker.internal port 11434 after 0 ms: Connection refused');

        $classifier = new ExceptionAlertClassifier;

        $this->assertFalse($classifier->shouldNotify($exception));
    }
}
