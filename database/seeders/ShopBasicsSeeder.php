<?php

namespace Database\Seeders;

use App\Models\Courier;
use App\Models\ShippingZone;
use Illuminate\Database\Seeder;

/** Starting shipping zones and couriers (edit them in admin → Shipping). */
class ShopBasicsSeeder extends Seeder
{
    public function run(): void
    {
        if (ShippingZone::query()->exists()) {
            return;
        }
        ShippingZone::query()->create(['name' => 'Addis Ababa', 'cities' => ['Addis Ababa'], 'base_fee' => 15_000, 'per_kg_fee' => 5_000, 'eta_days_min' => 1, 'eta_days_max' => 2, 'position' => 1]);
        ShippingZone::query()->create(['name' => 'Main cities', 'cities' => ['Adama', 'Hawassa', 'Bahir Dar', 'Gondar', 'Mekelle', 'Dire Dawa', 'Jimma', 'Dessie', 'Harar', 'Bishoftu'],
            'base_fee' => 30_000, 'per_kg_fee' => 8_000, 'eta_days_min' => 2, 'eta_days_max' => 4, 'position' => 2]);
        ShippingZone::query()->create(['name' => 'Rest of Ethiopia', 'cities' => [], 'base_fee' => 45_000, 'per_kg_fee' => 10_000, 'eta_days_min' => 3, 'eta_days_max' => 7, 'is_default' => true, 'position' => 3]);
        Courier::query()->create(['name' => 'EMS Ethiopia']);
        Courier::query()->create(['name' => 'HudHud Express']);
    }
}
