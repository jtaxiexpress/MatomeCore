<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Slack\ExceptionAlertClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ExceptionAlertClassifierTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::forget(ExceptionAlertClassifier::CACHE_KEY);

        parent::tearDown();
    }

    public function test_it_detects_database_connection_errors(): void
    {
        $previous = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        $exception = new QueryException('mysql', 'select 1', [], $previous);

        $classifier = new ExceptionAlertClassifier;

        $this->assertTrue($classifier->shouldNotify($exception));
    }

    public function test_it_detects_external_api_timeout_errors(): void
    {
        $exception = new RuntimeException('External API request failed: cURL error 28: Operation timed out after 60001 milliseconds');

        $classifier = new ExceptionAlertClassifier;

        $this->assertTrue($classifier->shouldNotify($exception));
    }

    public function test_it_excludes_ollama_connection_errors(): void
    {
        $exception = new RuntimeException('cURL error 7: Failed to connect to ollama.unicorn.tokyo port 11434 after 0 ms: Connection refused');

        $classifier = new ExceptionAlertClassifier;

        $this->assertFalse($classifier->shouldNotify($exception));
    }

    public function test_it_uses_cached_custom_rules(): void
    {
        Cache::put(ExceptionAlertClassifier::CACHE_KEY, [
            'database_markers' => ['custom-db-marker'],
            'timeout_markers' => ['custom-timeout-marker'],
            'ollama_markers' => ['custom-ollama-marker'],
        ]);

        $classifier = new ExceptionAlertClassifier;

        $this->assertTrue($classifier->shouldNotify(new RuntimeException('custom-db-marker')));
        $this->assertTrue($classifier->shouldNotify(new RuntimeException('custom-timeout-marker')));
        $this->assertFalse($classifier->shouldNotify(new RuntimeException('custom-ollama-marker')));
    }
}
