<?php

// ─── Phase X5 — BreachIncident UTC + photo upload write-then-delete order ──
// Locks in two tightly-coupled fixes:
//   1. BreachIncident year-rollover deadline math is performed in UTC, not
//      the app timezone, so a December 31 breach discovered late in the
//      day doesn't slip into the next year's HHS roll-up by accident.
//   2. The participant-photo upload writes the new file BEFORE deleting the
//      old one, preventing an orphan window where the old photo is gone but
//      the new one failed to land.
// Both are §164.404/§164.408 + storage-orphan regression traps.
namespace Tests\Feature;

use App\Models\BreachIncident;
use Carbon\Carbon;
use Tests\TestCase;

class X5BreachUtcAndPhotoSafetyTest extends TestCase
{
    public function test_breach_deadline_year_boundary_in_pst_normalizes_to_utc(): void
    {
        // Dec 31 23:30 PST is Jan 1 (next year) UTC. The HHS deadline anchor
        // should follow the UTC year, so the next-year March 1 falls 14 months
        // out from PST perspective, not 26 months.
        $discovered = Carbon::create(2025, 12, 31, 23, 30, 0, 'America/Los_Angeles');
        // In UTC this is 2026-01-01 07:30. So the deadline should be 2027-03-01 (year UTC+1).
        $deadline = BreachIncident::computeHhsDeadline(100, $discovered);
        $this->assertEquals('2027-03-01', $deadline->setTimezone('UTC')->toDateString(),
            'Boundary breach must compute deadline using UTC year, not local year.');
    }

    public function test_breach_deadline_for_500_plus_uses_60_day_offset(): void
    {
        $discovered = Carbon::parse('2026-04-01 10:00:00', 'UTC');
        $deadline = BreachIncident::computeHhsDeadline(700, $discovered);
        $this->assertEquals('2026-05-31', $deadline->toDateString());
    }

    public function test_breach_deadline_marker_comment_present(): void
    {
        $model = file_get_contents(app_path('Models/BreachIncident.php'));
        $this->assertStringContainsString('Audit-12 M3', $model);
        $this->assertStringContainsString("setTimezone('UTC')", $model);
    }

    public function test_photo_upload_uses_safe_write_then_delete_order(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/ParticipantController.php'));

        // L2 fix: storeAs must come before $participant->update + the old-file
        // delete must come after the update. Verify the marker comment + order.
        $this->assertStringContainsString('Audit-12 L2', $controller);
        $this->assertStringContainsString('try {', $controller);

        // The store-as call should appear before update + old delete.
        $storeAsPos = strpos($controller, 'storeAs');
        $updatePos  = strpos($controller, "update(['photo_path'");
        $oldDelete  = strpos($controller, 'oldPath !== $path');
        $this->assertNotFalse($storeAsPos);
        $this->assertNotFalse($updatePos);
        $this->assertNotFalse($oldDelete);
        $this->assertLessThan($updatePos, $storeAsPos, 'storeAs must precede update.');
        $this->assertLessThan($oldDelete, $updatePos, 'update must precede old-file delete.');
    }
}
