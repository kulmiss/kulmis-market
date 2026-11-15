<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->words(3, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'price' => 200_000,          // 2,000 ETB
            'cost_materials' => 40_000,
            'cost_labour' => 30_000,
            'cost_packaging' => 5_000,   // total 750 ETB → 2.67× (no warning)
            'weight_grams' => 800,
            'stock_mode' => Product::STOCK,
            'stock' => 10,
            'status' => 'active',
        ];
    }

    public function madeToOrder(int $days = 7): static
    {
        return $this->state(['stock_mode' => Product::MADE_TO_ORDER, 'stock' => null, 'making_days' => $days]);
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }
}
