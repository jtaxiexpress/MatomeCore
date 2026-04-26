<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\SystemSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class SystemSettingsOllamaModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::forget('ollama_available_models');
        Cache::forget('ollama_model');

        parent::tearDown();
    }

    public function test_system_settings_page_loads_when_ollama_model_api_is_unavailable(): void
    {
        $admin = User::factory()->admin()->create();
        $ollamaTagsUrl = rtrim((string) config('services.ollama.url', 'https://ollama.unicorn.tokyo'), '/').'/api/tags';

        Cache::put('ollama_model', 'qwen3.5:9b');
        Http::preventStrayRequests();
        Http::fake([
            $ollamaTagsUrl => Http::failedConnection(),
        ]);

        Livewire::actingAs($admin)
            ->test(SystemSettings::class)
            ->assertOk();
    }

    public function test_prompt_fields_appear_under_their_related_model_sections(): void
    {
        $admin = User::factory()->admin()->create();
        $ollamaTagsUrl = rtrim((string) config('services.ollama.url', 'https://ollama.unicorn.tokyo'), '/').'/api/tags';

        Cache::put('ollama_model', 'qwen3.5:9b');
        Http::preventStrayRequests();
        Http::fake([
            $ollamaTagsUrl => Http::failedConnection(),
        ]);

        Livewire::actingAs($admin)
            ->test(SystemSettings::class)
            ->assertSeeHtmlInOrder([
                'AIモデル設定（記事）',
                'システム共通ベースプロンプト（役割と基本ルール）',
            ])
            ->assertDontSeeHtml('AIプロンプト設定（記事）')
            ->assertDontSeeHtml('AIプロンプト設定（サイト解析）')
            ->assertDontSeeHtml('AIモデル設定（サイト解析）')
            ->assertDontSeeHtml('AIサイト解析プロンプト');
    }

    public function test_ollama_model_options_are_loaded_and_cached(): void
    {
        $page = new SystemSettings;
        $method = new ReflectionMethod($page, 'getOllamaModelOptions');
        $method->setAccessible(true);
        $ollamaTagsUrl = rtrim((string) config('services.ollama.url', 'https://ollama.unicorn.tokyo'), '/').'/api/tags';

        Cache::put('ollama_model', 'qwen3.5:9b');
        Cache::forget('ollama_available_models');

        Http::preventStrayRequests();
        Http::fake([
            $ollamaTagsUrl => Http::response([
                'models' => [
                    ['name' => 'gemma4:e2b'],
                    ['name' => 'llama3.2:3b'],
                ],
            ]),
        ]);

        $firstOptions = $method->invoke($page);
        $secondOptions = $method->invoke($page);

        $this->assertSame([
            'qwen3.5:9b' => 'qwen3.5:9b',
            'gemma4:e2b' => 'gemma4:e2b',
            'llama3.2:3b' => 'llama3.2:3b',
        ], $firstOptions);
        $this->assertSame($firstOptions, $secondOptions);
        Http::assertSentCount(1);
    }

    public function test_ollama_model_options_fall_back_to_current_model_when_api_fails(): void
    {
        $page = new SystemSettings;
        $method = new ReflectionMethod($page, 'getOllamaModelOptions');
        $method->setAccessible(true);
        $ollamaTagsUrl = rtrim((string) config('services.ollama.url', 'https://ollama.unicorn.tokyo'), '/').'/api/tags';

        Cache::put('ollama_model', 'qwen3.5:9b');
        Cache::forget('ollama_available_models');

        Http::preventStrayRequests();
        Http::fake([
            $ollamaTagsUrl => Http::failedConnection(),
        ]);

        $options = $method->invoke($page);

        $this->assertSame([
            'qwen3.5:9b' => 'qwen3.5:9b (取得失敗)',
        ], $options);
    }
}
