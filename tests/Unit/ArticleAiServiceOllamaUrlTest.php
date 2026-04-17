<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ArticleAiService;
use Tests\TestCase;

class ArticleAiServiceOllamaUrlTest extends TestCase
{
    public function test_uses_host_only_base_url_for_generate_endpoint(): void
    {
        config(['ai.providers.ollama.url' => 'https://ollama.unicorn.tokyo']);

        $service = new ArticleAiService;

        $method = new \ReflectionMethod($service, 'ollamaGenerateUrl');
        $method->setAccessible(true);

        $url = $method->invoke($service);

        $this->assertSame('https://ollama.unicorn.tokyo/api/generate', $url);
    }

    public function test_normalizes_api_suffix_to_generate_endpoint(): void
    {
        config(['ai.providers.ollama.url' => 'https://ollama.unicorn.tokyo/api']);

        $service = new ArticleAiService;

        $method = new \ReflectionMethod($service, 'ollamaGenerateUrl');
        $method->setAccessible(true);

        $url = $method->invoke($service);

        $this->assertSame('https://ollama.unicorn.tokyo/api/generate', $url);
    }
}
