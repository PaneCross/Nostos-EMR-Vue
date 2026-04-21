<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Site;
use Illuminate\Database\Seeder;

/**
 * Back-fills emr_locations.site_id for existing PACE-type locations.
 * Matches Location.name against Site.name (case-insensitive, substring).
 * Called once after the site_id migration; safe to re-run (idempotent).
 */
class LocationSiteLinkSeeder extends Seeder
{
    public function run(): void
    {
        $sites = Site::all();
        $paceLocations = Location::where('location_type', 'pace_center')->get();

        if ($paceLocations->isEmpty()) {
            $this->command->info('  No pace_center locations found — skipping.');
            return;
        }

        $linked = 0;
        $alreadyLinked = 0;

        foreach ($paceLocations as $loc) {
            if ($loc->site_id !== null) {
                $alreadyLinked++;
                continue;
            }

            // Match by name — e.g. "Sunrise PACE East Day Center" → Site "Sunrise PACE East"
            $match = $sites->first(function (Site $site) use ($loc) {
                $siteName = strtolower($site->name);
                $locName  = strtolower($loc->name);
                return str_contains($locName, $siteName) || str_contains($siteName, $locName);
            });

            if ($match) {
                $loc->site_id = $match->id;
                $loc->save();
                $linked++;
                $this->command->info("  Linked Location \"{$loc->name}\" → Site \"{$match->name}\"");
            } else {
                $this->command->warn("  Could not find site match for Location \"{$loc->name}\" — left unlinked.");
            }
        }

        $this->command->info("  Done. Linked {$linked} location(s); {$alreadyLinked} already linked.");
    }
}
