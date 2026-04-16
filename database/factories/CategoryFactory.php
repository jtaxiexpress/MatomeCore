<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = fake()->word();

        return [
            'app_id' => App::factory(),
            'name' => $name,
            'api_slug' => Str::slug($name),
            'sort_order' => 0,
        ];
    }
}
