<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phones (360–430 px): one card per row, 48 px touch targets, charts with a
 * phone drawing, and filters / sort / badge groups folded with a count.
 * (Screenshots and a no-sideways-scroll check at 360/390/414 run in the
 * browser sweep; these tests keep the markup from drifting back.)
 */
class MobileFirstTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_cards_are_one_column_on_phones(): void
    {
        $this->actingAs(User::factory()->member()->create());
        foreach (['/account', '/account/earnings', '/account/wallet', '/account/team'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString('class="mt-5 grid grid-cols-2', $html, $uri);
            $this->assertStringNotContainsString('class="mt-5 grid grid-cols-3', $html, $uri);
        }
        $this->get('/account')->assertSee('grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4', false);
    }

    public function test_top_bar_buttons_are_48_px(): void
    {
        $this->actingAs(User::factory()->member()->create());
        $html = $this->get('/account')->getContent();
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'grid size-12 place-items-center'));
        $this->assertStringContainsString('min-height: 48px', file_get_contents(base_path('resources/css/app.css')));
    }

    public function test_filters_and_badge_groups_fold_on_phones(): void
    {
        $this->actingAs(User::factory()->member()->create());
        $this->get('/account/earnings')->assertSee('data-collapse="earnings-filters"', false)->assertSee('sm:hidden', false);
        $this->get('/account/badges')->assertOk()->assertSee('data-collapse="badges-team"', false);
    }

    public function test_charts_have_a_phone_drawing(): void
    {
        $points = array_map(fn ($d) => ['label' => "$d Nov", 'value' => $d * 1000], range(1, 30));
        $html = view('member.partials.chart', ['points' => $points])->render();
        $this->assertStringContainsString('viewBox="0 0 320', $html, 'narrow drawing for phones');
        $this->assertStringContainsString('viewBox="0 0 600', $html);
        $this->assertStringContainsString('text-anchor="end"', $html, 'the last label is not cut off');
        $this->assertLessThanOrEqual(5, substr_count(explode('viewBox="0 0 600', $html)[0], '<text'), 'few labels on phones');
    }
}
