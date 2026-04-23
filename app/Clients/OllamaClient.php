<?php

declare(strict_types=1);

namespace App\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

class OllamaClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function generateStructuredResponse(array $payload, int $timeoutSeconds, string $operation): array
    {
        try {
            $response = Http::connectTimeout(10)
                ->timeout($timeoutSeconds)
                ->retry(2, 400)
                ->post($this->generateUrl(), $payload)
                ->throw(function (Response $response, RequestException $exception) use ($operation): void {
                    Log::error('[AI] Ollama APIエラー', [
                        'operation' => $operation,
                        'status' => $response->status(),
                        'body_preview' => mb_substr($response->body(), 0, 200),
                        'message' => $exception->getMessage(),
                    ]);
                });
        } catch (ConnectionException|RequestException $exception) {
            Log::error('[AI] Ollama通信エラー', [
                'operation' => $operation,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        return $this->decodeStructuredBody($response, $operation);
    }

    public function generateUrl(): string
    {
        $base = rtrim((string) config('ai.providers.ollama.url', 'https://ollama.unicorn.tokyo'), '/');

        if (str_ends_with($base, '/api/generate')) {
            return $base;
        }

        if (str_ends_with($base, '/api')) {
            return $base.'/generate';
        }

        return $base.'/api/generate';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStructuredBody(Response $response, string $operation): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            Log::error('[AI] OllamaレスポンスがJSONオブジェクトではありません', [
                'operation' => $operation,
                'body_preview' => mb_substr($response->body(), 0, 200),
            ]);

            throw new UnexpectedValueException('Ollama response is not a JSON object.');
        }

        $structuredText = $json['response'] ?? null;

        if (! is_string($structuredText) || trim($structuredText) === '') {
            Log::warning('[AI] Ollamaレスポンスにresponse文字列がありません', [
                'operation' => $operation,
                'response_keys' => array_keys($json),
            ]);

            throw new UnexpectedValueException('Ollama response does not contain a valid response field.');
        }

        $decoded = json_decode($structuredText, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[AI] Structured OutputのJSONデコードに失敗', [
                'operation' => $operation,
                'preview' => mb_substr($structuredText, 0, 200),
                'json_error' => json_last_error_msg(),
            ]);

            throw new UnexpectedValueException('Failed to decode Ollama structured output.');
        }

        return $decoded;
    }
}
