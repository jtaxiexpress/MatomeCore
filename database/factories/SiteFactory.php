<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            'app_id'    => App::factory(),
            'name'      => fake()->company(),
            'url'       => fake()->url(),
            'is_active' => true,
        ];
    }
}
