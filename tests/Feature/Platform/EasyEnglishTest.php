<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Very easy English: no technical words where members and staff read (see docs/glossary.md). */
class EasyEnglishTest extends TestCase
{
    use RefreshDatabase;

    private const JARGON = '/\b(ledger|commissions?|idempoten\w*|payable|webhook|posting|debit|credit|santim|bps|accrual|pending)\b/i';

    /** Visible text only: no tags, scripts, styles or attribute values. */
    private function text(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html) ?? '';

        return html_entity_decode(strip_tags(preg_replace('/<[^>]+>/', ' ', $html) ?? ''));
    }

    public function test_member_pages_use_easy_words(): void
    {
        $this->actingAs(User::factory()->member()->create());
        foreach (['/account', '/account/team', '/account/earnings', '/account/wallet', '/account/orders', '/account/badges', '/account/security'] as $uri) {
            $text = $this->text($this->get($uri)->assertOk()->getContent());
            $this->assertDoesNotMatchRegularExpression(self::JARGON, $text, $uri);
        }
        $this->get('/account/wallet')->assertSee('On the way')->assertSee('Ready to use')->assertSee('Money history');
    }

    public function test_settings_switches_and_messages_use_easy_words(): void
    {
        foreach (config('settings') as $key => $def) {
            $this->assertDoesNotMatchRegularExpression('/\b(commission|L[123]\b|pending|bps|ISO)\b/i', $def[2], "label of $key");
            if (str_starts_with($key, 'notify.')) {
                $this->assertDoesNotMatchRegularExpression(self::JARGON, (string) $def[1], "message $key");
            }
        }
        foreach (config('features') as $key => $def) {
            $this->assertDoesNotMatchRegularExpression(self::JARGON, $def[0].' '.($def[4] ?? ''), "switch $key");
        }
    }

    public function test_the_glossary_exists_and_covers_the_main_words(): void
    {
        $g = file_get_contents(base_path('docs/glossary.md'));
        foreach (['On the way', 'Ready to use', 'Money history', 'Team earnings', 'Money given back', 'Bonus adjusted this month'] as $word) {
            $this->assertStringContainsString($word, $g);
        }
    }
}
