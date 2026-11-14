<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Production-safe defaults. For demo products: --class=DemoSeeder */
    public function run(): void
    {
        $this->call(ShopBasicsSeeder::class);
    }
}
