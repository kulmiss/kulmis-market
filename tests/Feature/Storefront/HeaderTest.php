<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Header: theme, cart, then the account at the far right (showing who is signed in); theme switch can be turned off. */
class HeaderTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $html): string
    {
        return (string) substr($html, (int) strpos($html, '<header'), (int) strpos($html, '</header>') - (int) strpos($html, '<header'));
    }

    public function test_icons_are_theme_then_cart_then_account_at_the_end(): void
    {
        $h = $this->header($this->get('/')->assertOk()->getContent());
        $theme = strpos($h, 'data-theme-toggle');
        $cart = strpos($h, 'aria-label="Cart"');
        $account = strpos($h, 'data-account');
        $this->assertNotFalse($theme);
        $this->assertTrue($theme < $cart && $cart < $account, 'order: theme, cart, account');
        $this->assertStringContainsString('aria-label="Sign in"', $h);
        $this->assertSame(0, substr_count(substr($h, $account), '<a '), 'the account link is the last link in the header');
    }

    public function test_signed_in_user_is_shown(): void
    {
        $user = User::factory()->create(['first_name' => 'Selam']);
        $h = $this->header($this->actingAs($user)->withCookie((string) config('session.cookie'), 'x')->get('/shop')->assertOk()->getContent());
        $this->assertStringContainsString('aria-label="My account (Selam)"', $h);
        $this->assertStringContainsString('>Selam</span>', $h);
        $this->assertStringContainsString('>S</span>', $h);
        $this->assertStringNotContainsString('aria-label="Sign in"', $h);
    }

    public function test_names_are_escaped(): void
    {
        $user = User::factory()->create(['first_name' => '<b>x</b>']);
        $h = $this->header($this->actingAs($user)->get('/shop')->getContent());
        $this->assertStringNotContainsString('<b>x</b>', $h);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $h);
    }

    public function test_theme_switch_off_hides_the_toggle_and_the_dark_mode_script(): void
    {
        $this->assertStringContainsString("localStorage.getItem('km-theme')", $this->get('/')->getContent());
        Features::set('appearance.theme_switch', false);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-theme-toggle', $html);
        $this->assertStringNotContainsString("localStorage.getItem('km-theme')", $html, 'no dark mode at all: always light');

        $member = User::factory()->member()->create();
        $account = $this->actingAs($member)->get('/account')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-theme-toggle', $account);
        $this->assertStringNotContainsString("localStorage.getItem('km-theme')", $account);
    }
}
