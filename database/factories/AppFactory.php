<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<App>
 */
class AppFactory extends Factory
{
    protected $model = App::class;

    public function definition(): array
    {
        return [
            'name'      => fake()->company(),
            'is_active' => true,
        ];
    }
}
