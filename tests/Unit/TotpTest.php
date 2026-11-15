<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    // RFC 6238 Appendix B (SHA-1), last 6 digits.
    public function test_rfc6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
        $this->assertSame('050471', Totp::code($secret, intdiv(1111111111, 30)));
        $this->assertSame('005924', Totp::code($secret, intdiv(1234567890, 30)));
        $this->assertSame('279037', Totp::code($secret, intdiv(2000000000, 30)));
    }

    public function test_verify_accepts_adjacent_window_and_refuses_replay(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $step = intdiv($now, 30);
        $code = Totp::code($secret, $step - 1);

        $this->assertSame($step - 1, Totp::verify($secret, $code, null, $now));
        $this->assertNull(Totp::verify($secret, $code, $step - 1, $now), 'replayed code must fail');
        $this->assertNull(Totp::verify($secret, '000000x', null, $now));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step + 5), null, $now));
    }

    public function test_base32_round_trip(): void
    {
        $bytes = random_bytes(20);
        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
    }
}
