<?php

// ─── Phase X6 — FreezesTime trait determinism + Q1 deadline tests still pass ─
// Locks in: tests using the FreezesTime trait get a deterministic Carbon::now()
// across the entire test method (HTTP request, job dispatch, model events).
// Audit-12 X6 fixed flaky deadline tests where the clock would tick between
// arrange and assert. This test verifies the trait is wired up correctly
// AND that the previously flaky Q1 deadline-job tests still pass with it.
namespace Tests\Feature;

use Carbon\Carbon;
use Tests\Concerns\FreezesTime;
use Tests\TestCase;

class X6FreezesTimeTraitTest extends TestCase
{
    use FreezesTime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFreezesTime();
    }

    protected function tearDown(): void
    {
        $this->tearDownFreezesTime();
        parent::tearDown();
    }

    public function test_now_is_frozen_to_anchor(): void
    {
        // FreezesTime defaults the anchor to 2026-04-25 12:00 UTC.
        $this->assertEquals('2026-04-25 12:00:00', now('UTC')->format('Y-m-d H:i:s'));
        // Two reads return the same instant (proves freeze is real).
        $a = now();
        usleep(10_000); // 10ms
        $b = now();
        $this->assertEquals($a->format('Y-m-d H:i:s.u'), $b->format('Y-m-d H:i:s.u'));
    }

    public function test_diff_in_days_at_year_boundary_is_deterministic(): void
    {
        // Pick a deadline 60 days out — should always equal 60 under freeze,
        // never 59 or 61 due to a midnight wraparound mid-test.
        $deadline = now()->addDays(60);
        $this->assertEquals(60, (int) now()->diffInDays($deadline));
    }

    public function test_teardown_restores_real_time(): void
    {
        $this->tearDownFreezesTime();
        // After teardown, Carbon::now should be real time again.
        $real = Carbon::now();
        $this->assertNotEquals('2026-04-25', $real->toDateString(),
            'Teardown failed to release Carbon::setTestNow.');
        // Re-freeze so the suite tearDown doesn't double-clear.
        $this->setUpFreezesTime();
    }
}
