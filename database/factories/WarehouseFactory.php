<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'name' => 'Entrepôt ' . $this->faker->city(),
            'location' => $this->faker->address(),
            'is_active' => true,
        ];
    }
}

