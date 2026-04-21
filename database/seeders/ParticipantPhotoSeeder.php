<?php

// ─── ParticipantPhotoSeeder ─────────────────────────────────────────────────────
// Seeds placeholder profile photos for enrolled participants.
// Generates colored avatar images using PHP GD. Each avatar is a solid-color
// square with the participant's initials scaled up for readability.
// CSS rounded-full on the frontend clips it to a circle.
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Participant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ParticipantPhotoSeeder extends Seeder
{
    private const PHOTO_COUNT = 15;
    private const IMG_SIZE    = 200;

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

                $initials = strtoupper(
                    substr($participant->first_name, 0, 1) . substr($participant->last_name, 0, 1)
                );
                $this->command->line("  [{$initials}] {$participant->mrn} — {$participant->first_name} {$participant->last_name}");

            } catch (\Exception $e) {
                $this->command->warn("  Skipped {$participant->mrn}: {$e->getMessage()}");
            }
        }

        $this->command->info('Done. Run ./start.sh if needed to restore storage permissions.');
    }

    /**
     * Generate a solid-color avatar JPEG with large, centered initials.
     *
     * Draws text at native GD font resolution on a tiny canvas, then scales it
     * up with bilinear resampling (imagecopyresampled) onto the full-size image.
     * This produces large, smooth initials without needing TTF font files.
     */
    private function generateAvatar(string $firstName, string $lastName, array $rgb): string
    {
        $size = self::IMG_SIZE;

        // Solid color background
        $img     = imagecreatetruecolor($size, $size);
        $bgColor = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($img, 0, 0, $bgColor);

        // Draw initials on a tiny native-resolution canvas
        $initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
        $font     = 5;                        // largest built-in GD font
        $fW       = imagefontwidth($font);    // 9 px per char
        $fH       = imagefontheight($font);   // 15 px
        $pad      = 2;
        $srcW     = strlen($initials) * $fW + $pad * 2;
        $srcH     = $fH + $pad * 2;

        $src   = imagecreatetruecolor($srcW, $srcH);
        $srcBg = imagecolorallocate($src, $rgb[0], $rgb[1], $rgb[2]);
        $white = imagecolorallocate($src, 255, 255, 255);
        imagefill($src, 0, 0, $srcBg);
        imagestring($src, $font, $pad, $pad, $initials, $white);

        // Scale text up to ~44% of avatar width via bilinear resampling.
        // Source ~20x19 px -> destination ~88x68 px on 200x200 image.
        // At w-16 h-16 (64 CSS px), initials render ~28 px tall — clearly readable.
        $dstW = (int) round($size * 0.44);
        $dstH = (int) round($dstW * $srcH / $srcW);
        $dstX = (int) (($size - $dstW) / 2);
        $dstY = (int) (($size - $dstH) / 2);
        imagecopyresampled($img, $src, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);

        ob_start();
        imagejpeg($img, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        return $jpeg;
    }
}
