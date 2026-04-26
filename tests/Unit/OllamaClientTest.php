<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Clients\OllamaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use UnexpectedValueException;

class OllamaClientTest extends TestCase
{
    public function test_generate_structured_response_returns_decoded_array(): void
    {
        config(['ai.providers.ollama.url' => 'https://ollama.unicorn.tokyo']);

        Http::preventStrayRequests();
        Http::fake([
            'https://ollama.unicorn.tokyo/api/generate' => Http::response([
                'response' => json_encode([
                    'category_id' => 12,
                    'rewritten_title' => '整形済みタイトル',
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);

        $client = new OllamaClient;

        $result = $client->generateStructuredResponse([
            'model' => 'gemma4:e2b',
            'prompt' => 'test',
            'stream' => false,
            'format' => 'json',
        ], 120, '単体推論');

        $this->assertSame(12, $result['category_id']);
        $this->assertSame('整形済みタイトル', $result['rewritten_title']);
    }

    public function test_generate_structured_response_throws_connection_exception(): void
    {
        $this->expectException(ConnectionException::class);

        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::failedConnection(),
        ]);

        $client = new OllamaClient;
        $client->generateStructuredResponse(['model' => 'gemma4:e2b'], 120, '単体推論');
    }

    public function test_generate_structured_response_throws_request_exception_on_http_error(): void
    {
        $this->expectException(RequestException::class);

        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(['error' => 'server error'], 500),
        ]);

        $client = new OllamaClient;
        $client->generateStructuredResponse(['model' => 'gemma4:e2b'], 120, '単体推論');
    }

    public function test_generate_structured_response_throws_when_structured_body_is_invalid(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response([
                'response' => '{invalid-json}',
            ], 200),
        ]);

        $client = new OllamaClient;
        $client->generateStructuredResponse(['model' => 'gemma4:e2b'], 120, '単体推論');
    }
}
