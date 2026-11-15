<?php

namespace Tests\Feature\Money;

use App\Support\Idempotency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_runs_once_and_result_is_replayed(): void
    {
        $runs = 0;
        [$ran1, $r1] = Idempotency::once('webhook', 'abc', function () use (&$runs) {
            $runs++;

            return ['order' => 7];
        });
        [$ran2, $r2] = Idempotency::once('webhook', 'abc', function () use (&$runs) {
            $runs++;

            return ['order' => 8];
        });

        $this->assertTrue($ran1);
        $this->assertFalse($ran2);
        $this->assertSame(1, $runs);
        $this->assertSame(['order' => 7], $r2);
    }

    public function test_failed_work_does_not_burn_the_key(): void
    {
        try {
            Idempotency::once('job', 'x', fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        [$ran] = Idempotency::once('job', 'x', fn () => 1);
        $this->assertTrue($ran);
    }
}
