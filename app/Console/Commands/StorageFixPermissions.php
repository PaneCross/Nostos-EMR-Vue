<?php

// ─── storage:fix-permissions ─────────────────────────────────────────────────
// Phase A1 tech-debt sweep. Heals Laravel Sail's "root-owned file" problem
// that recurs after tests run with `SUPERVISOR_PHP_USER=root`.
//
// Symptom: `UnableToCreateDirectory` on storage/app/private/* in later test
// runs because a prior run left directories owned by root.
//
// Usage:
//   ./vendor/bin/sail artisan storage:fix-permissions
//   ./vendor/bin/sail artisan storage:fix-permissions --sudo    (if not root)
//
// Also used internally by the composer `test` script + start.sh scaffolding.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StorageFixPermissions extends Command
{
    protected $signature = 'storage:fix-permissions
        {--user=sail : owner:group to chown to (default sail:sail)}';
    protected $description = "Chown storage/ + bootstrap/cache/ to the expected user. Fixes Sail's root-owned-file recurrence.";

    public function handle(): int
    {
        $target = $this->option('user') ?: 'sail';
        if (!str_contains($target, ':')) {
            $target .= ':' . $target; // user:group shorthand
        }

        $paths = [
            base_path('storage'),
            base_path('bootstrap/cache'),
        ];

        foreach ($paths as $path) {
            $cmd = sprintf('chown -R %s %s 2>&1', escapeshellarg($target), escapeshellarg($path));
            $output = shell_exec($cmd);

            if ($output !== null && trim($output) !== '') {
                if (str_contains($output, 'Operation not permitted')) {
                    $this->warn("  {$path}: needs sudo (run via `sail exec -u root` or root shell)");
                } else {
                    $this->line("  {$path}: {$output}");
                }
            } else {
                $this->info("  {$path}: ok");
            }
        }

        // Ensure Laravel's expected subdirs exist + are writable.
        foreach ([
            'app/private',
            'app/public',
            'framework/cache',
            'framework/sessions',
            'framework/testing',
            'framework/views',
            'logs',
        ] as $sub) {
            $p = base_path("storage/{$sub}");
            if (!is_dir($p)) @mkdir($p, 0775, true);
            @chmod($p, 0775);
        }

        return self::SUCCESS;
    }
}
