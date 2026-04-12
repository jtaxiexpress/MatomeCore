<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'app_id'     => App::factory(),
            'name'       => fake()->word(),
            'sort_order' => 0,
        ];
    }
}
