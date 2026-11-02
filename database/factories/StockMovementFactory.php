<?php

namespace Database\Factories;

use App\Models\StockMovement;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'type' => $this->faker->randomElement(['IN', 'OUT']),
            'quantity' => $this->faker->numberBetween(1, 100),
            'destination_warehouse_id' => null,
            'reference' => strtoupper($this->faker->bothify('MOV-####')),
            'notes' => $this->faker->sentence(),
            'user_id' => User::factory(),
        ];
    }
}

