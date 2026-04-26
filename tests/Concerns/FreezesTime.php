<?php

// ─── FreezesTime — Phase X6 (Audit-12 M2) ───────────────────────────────────
// Trait for tests that assert day-boundary deadline math (BreachDeadlineJob,
// AmendmentDeadlineJob, RoiDeadlineAlert, InrOverdueJob, IdtReviewFrequencyJob).
// Freezes Carbon's "now" at a deterministic anchor in setUp so:
//   - midnight UTC crossover doesn't flake the suite at certain times of day
//   - DST boundaries don't produce off-by-one diffInDays errors
//   - paratest workers don't see slightly different "now" values
//
// Usage:
//   use FreezesTime;            // inside the test class
//   protected function freezeAt(): \Carbon\Carbon { return now()->setTimezone('UTC')->startOfHour(); }
//
// Tests that NEED a specific anchor can override freezeAt().
namespace Tests\Concerns;

use Carbon\Carbon;

trait FreezesTime
{
    /**
     * Anchor time. Override in test classes that need a specific date
     * (e.g. one that exercises a year-boundary or DST edge).
     */
    protected function freezeAt(): Carbon
    {
        return Carbon::create(2026, 4, 25, 12, 0, 0, 'UTC');
    }

    protected function setUpFreezesTime(): void
    {
        Carbon::setTestNow($this->freezeAt());
    }

    protected function tearDownFreezesTime(): void
    {
        Carbon::setTestNow(); // back to real time
    }
}
