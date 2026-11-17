<?php

namespace App\Home;

use App\Demo\Demo;
use App\Models\HomeMedia;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Pictures and quotes for the "Investor demo" template. All of them are demo
 * rows (is_demo): marked "Demo", hidden while "Show demo data" is off, and
 * deleted with the rest of the demo data.
 */
final class HomeDemo
{
    /** photo file => description */
    public const PHOTOS = [
        'coffee-grinding' => 'A woman grinding coffee the traditional way',
        'baskets-colorful' => 'Colourful handwoven baskets in the sun',
        'leather-artisan' => 'A leather maker at work',
        'scarves-traditional' => 'Traditional woven scarves',
        'jebena-pot' => 'A clay jebena coffee pot',
        'beads-handmade' => 'Handmade bead necklaces',
    ];

    public const QUOTES = [
        ['Hanna', 'Addis Ababa', 'The basket arrived in two days and it is even more beautiful than in the photo.'],
        ['Dawit', 'Bahir Dar', 'I ordered a scarf for my mother. She has not taken it off since.'],
        ['Selam', 'Hawassa', 'Paying with Telebirr was easy, and they answered my question on WhatsApp the same day.'],
    ];

    public function __construct(private readonly HomeMediaStore $store) {}

    /** @return array{images:list<int>, quotes:list<int>} */
    public function ensure(?User $by): array
    {
        if (! Demo::visible()) {
            throw ValidationException::withMessages(['template' => 'Turn on "Show demo data" (Admin → Demo data) to use the investor demo.']);
        }
        $images = [];
        foreach (self::PHOTOS as $file => $alt) {
            $m = HomeMedia::query()->where('is_demo', true)->where('alt', "Demo photo: $alt")->first();
            if (! $m) {
                $path = database_path("seeders/demo-photos/$file.jpg");
                $m = $this->store->image(new UploadedFile($path, "$file.jpg", 'image/jpeg', null, true), "Demo photo: $alt", $by, true);
            }
            $images[] = $m->id;
        }
        $quotes = [];
        foreach (self::QUOTES as $i => [$name, $city, $text]) {
            $quotes[] = Testimonial::query()->firstOrCreate(['is_demo' => true, 'name' => $name, 'city' => $city],
                ['text' => $text, 'confirmed_real' => false, 'is_active' => true, 'position' => $i, 'created_by' => $by?->id])->id;
        }

        return ['images' => $images, 'quotes' => $quotes];
    }
}
