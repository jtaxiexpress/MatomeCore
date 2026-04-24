<?php

namespace Database\Factories;

use App\Models\AboutSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AboutSection>
 */
class AboutSectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'content' => '<p>'.implode('</p><p>', $this->faker->paragraphs(2)).'</p>',
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_visible' => true,
        ];
    }
}
