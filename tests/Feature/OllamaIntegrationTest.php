<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaIntegrationTest extends TestCase
{
    public function test_ollama_health(): void
    {
        if (! env('OLLAMA_INTEGRATION', false)) {
            $this->markTestSkipped('Ollama integration tests disabled');
        }

        $base = rtrim((string) config('ai.providers.ollama.url', 'https://ollama.unicorn.tokyo'), '/');

        $res = Http::timeout(5)->get($base);

        $this->assertTrue($res->successful());
        $this->assertStringContainsString('Ollama is running', (string) $res->body());
    }
}
