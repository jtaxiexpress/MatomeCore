<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'site_id' => Site::factory(),
            'category_id' => Category::factory(),
            'title' => fake()->sentence(),
            'url' => fake()->unique()->url(),
            'published_at' => now(),
        ];
    }
}
