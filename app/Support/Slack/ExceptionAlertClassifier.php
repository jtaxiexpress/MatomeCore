<?php

declare(strict_types=1);

namespace App\Support\Slack;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class ExceptionAlertClassifier
{
    public function shouldNotify(Throwable $exception): bool
    {
        if ($this->isOllamaConnectionError($exception)) {
            return false;
        }

        if ($this->isDatabaseConnectionError($exception)) {
            return true;
        }

        if ($this->isGeminiRateLimitError($exception)) {
            return true;
        }

        return $this->isExternalApiTimeoutError($exception);
    }

    private function isDatabaseConnectionError(Throwable $exception): bool
    {
        $message = $this->normalizedMessage($exception);

        $databaseMarkers = [
            'sqlstate[hy000] [2002]',
            'sqlstate[hy000] [2006]',
            'server has gone away',
            'database connection [',
            'could not find driver',
            'unknown database',
            'access denied for user',
            'no such file or directory',
            'unable to connect to database',
        ];

        if ($exception instanceof QueryException) {
            return $this->containsAny($message, $databaseMarkers);
        }

        return $this->containsAny($message, $databaseMarkers);
    }

    private function isGeminiRateLimitError(Throwable $exception): bool
    {
        $message = $this->normalizedMessage($exception);

        $rateLimitMarkers = [
            ' 429',
            'http 429',
            'status code 429',
            'too many requests',
            'rate limit',
            'resource_exhausted',
            'quota exceeded',
            'quota',
        ];

        $providerMarkers = [
            'gemini',
            'google',
            'googleapis',
            'generativelanguage',
            'laravel\\ai',
        ];

        return $this->containsAny($message, $rateLimitMarkers)
            && $this->containsAny($message, $providerMarkers);
    }

    private function isExternalApiTimeoutError(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException && ! $this->isOllamaConnectionError($exception)) {
            return true;
        }

        $message = $this->normalizedMessage($exception);

        $timeoutMarkers = [
            'timed out',
            'timeout',
            'curl error 28',
            'operation timed out',
            'connection timed out',
            'gateway timeout',
            'request timeout',
        ];

        return $this->containsAny($message, $timeoutMarkers)
            && ! $this->isDatabaseConnectionError($exception)
            && ! $this->isOllamaConnectionError($exception);
    }

    private function isOllamaConnectionError(Throwable $exception): bool
    {
        $message = $this->normalizedMessage($exception);

        $markers = [
            'ollama',
            '11434',
            'localhost:11434',
            'host.docker.internal',
            '/api/generate',
        ];

        $ollamaUrl = (string) config('services.ollama.url', '');

        if ($ollamaUrl !== '') {
            $markers[] = mb_strtolower($ollamaUrl);

            $host = parse_url($ollamaUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $markers[] = mb_strtolower($host);
            }

            $port = parse_url($ollamaUrl, PHP_URL_PORT);
            if (is_int($port)) {
                $markers[] = (string) $port;
            }
        }

        return $this->containsAny($message, array_filter(array_unique($markers)));
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalizedMessage(Throwable $exception): string
    {
        $chunks = [];
        $current = $exception;

        while ($current !== null) {
            $chunks[] = mb_strtolower($current::class.' '.$current->getMessage());
            $current = $current->getPrevious();
        }

        return implode(' | ', $chunks);
    }
}
