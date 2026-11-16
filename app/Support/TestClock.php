<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A pretend clock for testing by hand on your own computer: "it is now 15
 * days later". The shop, the jobs and the queue all use it. It only works
 * when APP_ENV=local; a live shop always uses the real time.
 *
 * Stored as seconds ahead of the real time in storage/framework/test-clock.
 */
final class TestClock
{
    public static function allowed(): bool
    {
        return app()->environment('local');
    }

    private static function file(): string
    {
        return storage_path('framework/test-clock');
    }

    /** Seconds ahead of the real clock (0 = the real time). */
    public static function offset(): int
    {
        if (! self::allowed() || ! is_file(self::file())) {
            return 0;
        }

        return max(0, (int) trim((string) @file_get_contents(self::file())));
    }

    public static function active(): bool
    {
        return self::offset() > 0;
    }

    /** Use the pretend time in this process (called on every request, command and job). */
    public static function apply(): void
    {
        if (! self::allowed()) {
            return; // tests and live shops keep their own clock
        }
        $offset = self::offset();
        if ($offset > 0) {
            Carbon::setTestNow(Carbon::createFromTimestamp(time() + $offset, config('app.timezone')));
            self::$applied = true;
        } elseif (self::$applied) {
            Carbon::setTestNow(null); // the clock was reset: back to the real time
            self::$applied = false;
        }
    }

    /** True while this process uses the pretend time (so a reset can undo only what this class did). */
    private static bool $applied = false;

    /** Move forward, e.g. "+15 days", "+29days", "+2 hours". Returns the new pretend time. */
    public static function forward(string $by): Carbon
    {
        self::guard();
        $by = (string) preg_replace('/(\d)([a-z])/i', '$1 $2', trim($by));
        if (! preg_match('/^\+?\d+ (minute|hour|day|week|month)s?$/i', $by)) {
            throw new RuntimeException('Write it like +15days, +2 hours or +1 month (forward only).');
        }
        $real = Carbon::createFromTimestamp(time(), config('app.timezone'));
        $target = Carbon::createFromTimestamp(time() + self::offset(), config('app.timezone'))->modify(ltrim($by, '+'));
        self::write($target->getTimestamp() - $real->getTimestamp());

        return $target;
    }

    public static function reset(): void
    {
        self::guard();
        @unlink(self::file());
        Carbon::setTestNow(null);
        self::$applied = false;
    }

    private static function write(int $seconds): void
    {
        file_put_contents(self::file(), (string) $seconds);
        self::apply();
    }

    private static function guard(): void
    {
        if (! self::allowed()) {
            throw new RuntimeException('The test clock only works on your own computer (APP_ENV=local), never on a live shop.');
        }
    }
}
