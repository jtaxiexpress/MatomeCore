<?php

declare(strict_types=1);

namespace App\Support\Slack;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ExceptionAlertClassifier
{
    public const CACHE_KEY = 'exception_alert_rules';

    /**
     * @var array<string, array<int, string>>|null
     */
    private ?array $rules = null;

    public function shouldNotify(Throwable $exception): bool
    {
        if ($this->isOllamaConnectionError($exception)) {
            return false;
        }

        if ($this->isDatabaseConnectionError($exception)) {
            return true;
        }

        return $this->isExternalApiTimeoutError($exception);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function defaultRules(): array
    {
        return [
            'database_markers' => [
                'sqlstate[hy000] [2002]',
                'sqlstate[hy000] [2006]',
                'server has gone away',
                'database connection [',
                'could not find driver',
                'unknown database',
                'access denied for user',
                'no such file or directory',
                'unable to connect to database',
            ],
            'timeout_markers' => [
                'timed out',
                'timeout',
                'curl error 28',
                'operation timed out',
                'connection timed out',
                'gateway timeout',
                'request timeout',
            ],
            'ollama_markers' => [
                'ollama',
                '11434',
                'localhost:11434',
                'host.docker.internal',
                'ollama.unicorn.tokyo',
                '/api/generate',
            ],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function currentRules(): array
    {
        $cachedRules = Cache::get(self::CACHE_KEY);

        if (! is_array($cachedRules)) {
            return self::defaultRules();
        }

        $defaultRules = self::defaultRules();

        return [
            'database_markers' => self::normalizeMarkers($cachedRules['database_markers'] ?? $defaultRules['database_markers']),
            'timeout_markers' => self::normalizeMarkers($cachedRules['timeout_markers'] ?? $defaultRules['timeout_markers']),
            'ollama_markers' => self::normalizeMarkers($cachedRules['ollama_markers'] ?? $defaultRules['ollama_markers']),
        ];
    }

    private function isDatabaseConnectionError(Throwable $exception): bool
    {
        $message = $this->normalizedMessage($exception);

        if ($exception instanceof QueryException) {
            return $this->containsAny($message, $this->databaseMarkers());
        }

        return $this->containsAny($message, $this->databaseMarkers());
    }

    private function isExternalApiTimeoutError(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException && ! $this->isOllamaConnectionError($exception)) {
            return true;
        }

        $message = $this->normalizedMessage($exception);

        return $this->containsAny($message, $this->timeoutMarkers())
            && ! $this->isDatabaseConnectionError($exception)
            && ! $this->isOllamaConnectionError($exception);
    }

    private function isOllamaConnectionError(Throwable $exception): bool
    {
        $message = $this->normalizedMessage($exception);

        $markers = $this->ollamaMarkers();

        $ollamaUrl = (string) config('ai.providers.ollama.url', '');

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
     * @return array<int, string>
     */
    private function databaseMarkers(): array
    {
        return $this->rules()['database_markers'] ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function timeoutMarkers(): array
    {
        return $this->rules()['timeout_markers'] ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function ollamaMarkers(): array
    {
        return $this->rules()['ollama_markers'] ?? [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return $this->rules ??= self::currentRules();
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeMarkers(mixed $markers): array
    {
        if (! is_array($markers)) {
            return [];
        }

        return collect($markers)
            ->filter(static fn ($marker): bool => is_string($marker))
            ->map(static fn (string $marker): string => mb_strtolower(trim($marker)))
            ->filter(static fn (string $marker): bool => $marker !== '')
            ->unique()
            ->values()
            ->all();
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
