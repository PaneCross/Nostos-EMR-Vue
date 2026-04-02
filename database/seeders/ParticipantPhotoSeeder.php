<?php

// ─── ParticipantPhotoSeeder ────────────────────────────────────────────────────
// Seeds placeholder profile photos for enrolled participants so the photo upload
// feature is visually testable without manually uploading files.
//
// Generates colored avatar images locally using PHP GD (no network required).
// Each avatar is a solid-color circle with the participant's initials — the
// same pattern used by the React fallback avatar in the frontend.
//
// Storing JPEG files to storage/app/public/participants/{id}/photo.jpg and
// updating photo_path on emr_participants.
//
// Requires PHP GD extension (included in Laravel Sail / Docker image by default).
//
// Run from WSL2:
//   docker compose exec -T laravel.test php artisan db:seed --class=ParticipantPhotoSeeder
//
// NOTE: Entirely offline-safe — no network access required. Runs reliably inside
// Docker containers without internet access.
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Participant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ParticipantPhotoSeeder extends Seeder
{
    // Number of participants to seed with photos (first N by ID)
    private const PHOTO_COUNT = 15;

    // Image dimensions for the generated avatar
    private const IMG_SIZE = 200;

    // Palette of background colors — each participant gets a distinct color
    private const COLORS = [
        [79,  70,  229],   // indigo-600
        [16,  185, 129],   // emerald-500
        [245, 158, 11],    // amber-500
        [239, 68,  68],    // red-500
        [59,  130, 246],   // blue-500
        [168, 85,  247],   // purple-500
        [236, 72,  153],   // pink-500
        [20,  184, 166],   // teal-500
        [249, 115, 22],    // orange-500
        [132, 204, 22],    // lime-500
        [34,  211, 238],   // cyan-400
        [248, 113, 113],   // red-400
        [167, 139, 250],   // violet-400
        [52,  211, 153],   // emerald-400
        [251, 191, 36],    // amber-400
    ];

    public function run(): void
    {
        if (!extension_loaded('gd')) {
            $this->command->error('PHP GD extension is not loaded. Cannot generate avatar images.');
            return;
        }

        // Ensure the storage link is in place
        if (!file_exists(public_path('storage'))) {
            $this->command->warn('storage link missing — run php artisan storage:link first');
        }

        $participants = Participant::whereIn('enrollment_status', ['enrolled', 'active'])
            ->orderBy('id')
            ->limit(self::PHOTO_COUNT)
            ->get();

        if ($participants->isEmpty()) {
            $this->command->warn('No enrolled participants found. Seed DemoEnvironmentSeeder first.');
            return;
        }

        $this->command->info("Generating placeholder photos for {$participants->count()} participants...");

        foreach ($participants as $i => $participant) {
            $color = self::COLORS[$i % count(self::COLORS)];

            try {
                $jpeg = $this->generateAvatar(
                    $participant->first_name,
                    $participant->last_name,
                    $color
                );

                $dir  = "participants/{$participant->id}";
                $path = "{$dir}/photo.jpg";

                Storage::disk('public')->makeDirectory($dir);
                Storage::disk('public')->put($path, $jpeg);

                $participant->update(['photo_path' => $path]);

                $initials = strtoupper(substr($participant->first_name, 0, 1) . substr($participant->last_name, 0, 1));
                $this->command->line("  Generated avatar [{$initials}] → {$participant->mrn} ({$participant->first_name} {$participant->last_name})");

            } catch (\Exception $e) {
                $this->command->warn("  Skipped {$participant->mrn}: {$e->getMessage()}");
            }
        }

        $this->command->info('Done. Run ./start.sh if needed to restore permissions.');
    }

    /**
     * Generate a solid-color circular avatar with initials using PHP GD.
     * Returns raw JPEG bytes ready to write to disk.
     *
     * @param  string  $firstName
     * @param  string  $lastName
     * @param  int[]   $rgb  [r, g, b] background color
     * @return string  JPEG binary content
     */
    private function generateAvatar(string $firstName, string $lastName, array $rgb): string
    {
        $size = self::IMG_SIZE;

        // Create a square true-color canvas
        $img = imagecreatetruecolor($size, $size);

        // Fill with the background color
        $bgColor = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($img, 0, 0, $bgColor);

        // Crop to a circle by making the corners transparent
        // PNG alpha is needed for the circle mask, then we composite onto JPEG
        $circle = imagecreatetruecolor($size, $size);
        imagealphablending($circle, false);
        imagesavealpha($circle, true);
        $transparent = imagecolorallocatealpha($circle, 0, 0, 0, 127);
        imagefill($circle, 0, 0, $transparent);

        // Draw filled ellipse (circle) with background color
        $circleColor = imagecolorallocate($circle, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledellipse($circle, $size / 2, $size / 2, $size, $size, $circleColor);

        // Merge circle onto the original image
        imagealphablending($img, true);
        imagecopy($img, $circle, 0, 0, 0, 0, $size, $size);
        imagedestroy($circle);

        // Draw initials centered on the circle
        $initials = strtoupper(
            substr($firstName, 0, 1) . substr($lastName, 0, 1)
        );

        // Use built-in GD font 5 (largest built-in font, ~9×15 px per char)
        // Scale up by drawing on a larger canvas and downsampling
        $white = imagecolorallocate($img, 255, 255, 255);
        $font  = 5; // largest built-in GD font

        $charW = imagefontwidth($font);
        $charH = imagefontheight($font);
        $textW = strlen($initials) * $charW;

        // Scale the text to roughly 35% of the image size
        $targetW = (int) ($size * 0.38);
        $scale   = max(1, (int) round($targetW / $charW));

        // Draw onto a temporary canvas at 1x then scale up manually
        // (GD built-in fonts cannot be scaled — use a larger temp canvas)
        $tmpSize  = $size * 2;
        $tmpImg   = imagecreatetruecolor($tmpSize, $tmpSize);
        $tmpBg    = imagecolorallocate($tmpImg, $rgb[0], $rgb[1], $rgb[2]);
        $tmpWhite = imagecolorallocate($tmpImg, 255, 255, 255);
        imagefill($tmpImg, 0, 0, $tmpBg);

        // Draw at 2× scale by repeating characters offset by 1px (simple bold)
        $x = (int) (($tmpSize - strlen($initials) * $charW * 2) / 2);
        $y = (int) (($tmpSize - $charH * 2) / 2);

        // Scale text by using imagestring at 2× position steps
        for ($row = 0; $row < 2; $row++) {
            for ($col = 0; $col < 2; $col++) {
                imagestring($tmpImg, $font, $x + $col, $y + $row, $initials, $tmpWhite);
            }
        }

        // Downsample the temp canvas back to target size and overlay
        imagecopyresampled($img, $tmpImg, 0, 0, 0, 0, $size, $size, $tmpSize, $tmpSize);
        imagedestroy($tmpImg);

        // Capture JPEG output to a string
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        return $jpeg;
    }
}
