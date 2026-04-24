<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\App;
use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TrackInTrafficTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_query_site_id_takes_precedence_over_referer(): void
    {
        Cache::flush();

        $app = App::factory()->create(['is_active' => true]);
        $preferredSite = Site::factory()->recycle($app)->create(['name' => 'Preferred site']);
        $fallbackSite = Site::factory()->recycle($app)->create([
            'name' => 'Fallback site',
            'url' => 'https://fallback.example.com',
        ]);

        $response = $this->withHeader('referer', 'https://fallback.example.com/articles/1')
            ->get('/?in_site_id='.$preferredSite->id);

        $response->assertOk();

        $this->assertDatabaseHas('site_ins', [
            'site_id' => $preferredSite->id,
        ]);

        $this->assertDatabaseMissing('site_ins', [
            'site_id' => $fallbackSite->id,
        ]);
    }

    public function test_query_site_slug_takes_precedence_over_referer(): void
    {
        Cache::flush();

        $app = App::factory()->create(['is_active' => true]);
        $preferredSite = Site::factory()->recycle($app)->create(['name' => 'Preferred slug site']);
        $fallbackSite = Site::factory()->recycle($app)->create([
            'name' => 'Fallback site',
            'url' => 'https://fallback.example.com',
        ]);

        $response = $this->withHeader('referer', 'https://fallback.example.com/articles/1')
            ->get('/?in_site_slug='.$preferredSite->api_slug);

        $response->assertOk();

        $this->assertDatabaseHas('site_ins', [
            'site_id' => $preferredSite->id,
        ]);

        $this->assertDatabaseMissing('site_ins', [
            'site_id' => $fallbackSite->id,
        ]);
    }

    public function test_referer_is_used_when_no_query_params_are_present(): void
    {
        Cache::flush();

        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create([
            'name' => 'Referer site',
            'url' => 'https://example.com',
        ]);

        $response = $this->withHeader('referer', 'https://example.com/articles/1')
            ->get('/');

        $response->assertOk();

        $this->assertDatabaseHas('site_ins', [
            'site_id' => $site->id,
        ]);
    }

    public function test_bot_is_filtered_before_site_resolution(): void
    {
        Cache::flush();

        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create();

        $response = $this->withHeader('User-Agent', 'Googlebot/2.1')
            ->get('/?in_site_id='.$site->id);

        $response->assertOk();

        $this->assertDatabaseMissing('site_ins', [
            'site_id' => $site->id,
        ]);
    }
}
