<?php

// ─── Phase Y4 — Job timeout + jittered backoff hardening (Audit-13 M4)
// Asserts the 4 long-running jobs each declare an explicit $timeout and a
// non-empty backoff() to prevent indefinite hang + lock-step retry storms.
namespace Tests\Feature;

use App\Jobs\Process835RemittanceJob;
use App\Jobs\ProcessHl7AdtJob;
use App\Jobs\ProcessLabResultJob;
use App\Jobs\ProcessTransportStatusWebhookJob;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class Y4JobTimeoutsTest extends TestCase
{
    /** @return array<int, array{0: class-string, 1: int}> */
    public static function jobClasses(): array
    {
        return [
            [Process835RemittanceJob::class, 600],
            [ProcessHl7AdtJob::class, 120],
            [ProcessLabResultJob::class, 120],
            [ProcessTransportStatusWebhookJob::class, 60],
        ];
    }

    #[DataProvider('jobClasses')]
    public function test_job_declares_timeout_and_backoff(string $class, int $expectedTimeout): void
    {
        $rc = new ReflectionClass($class);
        $defaults = $rc->getDefaultProperties();

        $this->assertArrayHasKey('timeout', $defaults, "$class missing \$timeout");
        $this->assertEquals($expectedTimeout, $defaults['timeout'], "$class wrong \$timeout");

        $this->assertTrue(
            $rc->hasMethod('backoff'),
            "$class missing backoff() method (lock-step retry risk)."
        );

        // Each backoff() should yield a non-empty schedule.
        $instance = $rc->newInstanceWithoutConstructor();
        $schedule = $instance->backoff();
        $this->assertIsArray($schedule);
        $this->assertNotEmpty($schedule, "$class backoff() must return a non-empty schedule.");
    }
}
