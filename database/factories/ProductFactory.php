<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'sku' => strtoupper($this->faker->unique()->bothify('PRD-####-??')),
            'description' => $this->faker->sentence(),
            'price' => $this->faker->randomFloat(2, 1, 500),
        ];
    }
}

