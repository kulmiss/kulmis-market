<?php

namespace Database\Seeders;

use App\Catalog\ImageProcessor;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Demo catalog for local testing and Lighthouse CI: `php artisan db:seed --class=DemoSeeder`. */
class DemoSeeder extends Seeder
{
    public function run(ImageProcessor $images): void
    {
        $this->call(ShopBasicsSeeder::class);

        $cats = collect(['Baskets', 'Textiles', 'Pottery', 'Leather'])->mapWithKeys(fn ($n, $i) => [$n => Category::query()->firstOrCreate(
            ['slug' => Str::slug($n)], ['name' => $n, 'position' => $i])]);

        $items = [
            ['Mesob basket, red & black', 'Baskets', 350_000, 120_000, [181, 83, 43]],
            ['Woven bread basket', 'Baskets', 180_000, 60_000, [201, 149, 43]],
            ['Handwoven gabi blanket', 'Textiles', 520_000, 190_000, [236, 223, 205]],
            ['Tibeb cotton scarf', 'Textiles', 160_000, 55_000, [63, 107, 69]],
            ['Jebena coffee pot', 'Pottery', 140_000, 45_000, [74, 58, 44]],
            ['Clay incense burner', 'Pottery', 90_000, 30_000, [127, 54, 27]],
            ['Leather shoulder bag', 'Leather', 780_000, 280_000, [53, 41, 31]],
            ['Leather card holder', 'Leather', 120_000, 40_000, [156, 68, 34]],
        ];
        foreach ($items as $i => [$name, $cat, $price, $cost, $rgb]) {
            $product = Product::query()->firstOrCreate(['slug' => Str::slug($name)], [
                'name' => $name, 'category_id' => $cats[$cat]->id, 'price' => $price,
                'cost_materials' => (int) ($cost * 0.6), 'cost_labour' => (int) ($cost * 0.35), 'cost_packaging' => (int) ($cost * 0.05),
                'short_description' => 'Handmade in Ethiopia by our partner makers.',
                'description' => "Every piece is made by hand, so each one is a little different.\n\n- Natural materials\n- Made in Ethiopia\n- Ships all over the country",
                'weight_grams' => 600 + 150 * $i, 'stock_mode' => $i % 3 === 2 ? Product::MADE_TO_ORDER : Product::STOCK,
                'stock' => $i % 3 === 2 ? null : 12, 'making_days' => $i % 3 === 2 ? 10 : null,
                'is_featured' => $i < 4, 'is_members_only' => $i === 6, 'status' => 'active',
            ]);
            if ($product->images()->doesntExist()) {
                $images->store($product, $this->image($rgb), $name);
            }
        }
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function image(array $rgb): UploadedFile
    {
        $img = imagecreatetruecolor(1200, 1200);
        for ($y = 0; $y < 1200; $y += 4) {
            $shade = (int) (30 * sin($y / 90));
            $c = imagecolorallocate($img, max(0, min(255, $rgb[0] + $shade)), max(0, min(255, $rgb[1] + $shade)), max(0, min(255, $rgb[2] + $shade)));
            imagefilledrectangle($img, 0, $y, 1200, $y + 4, $c);
        }
        $path = tempnam(sys_get_temp_dir(), 'km').'.jpg';
        imagejpeg($img, $path, 85);

        return new UploadedFile($path, 'demo.jpg', 'image/jpeg', null, true);
    }
}
