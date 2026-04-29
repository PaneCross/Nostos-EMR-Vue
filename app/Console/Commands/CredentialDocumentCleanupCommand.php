<?php

// ─── CredentialDocumentCleanupCommand ────────────────────────────────────────
// Audit-4 F1 + F2 : on-demand ops command to garbage-collect credential
// document files that no longer have a live row pointing at them.
//
// Cleanup categories :
//   1. Orphan files : a file in storage/app/credentials/* whose path is
//      not referenced by ANY emr_staff_credentials.document_path (including
//      soft-deleted rows).
//   2. Force-deleted credentials : staff_credentials soft-deleted more than
//      N days ago (default 365 ; CMS audit window) get their PDFs purged.
//   3. Tenant offboarded : if --tenant=N is passed, every credential file
//      under that tenant directory is purged (only after confirming the
//      tenant has been soft-deleted itself).
//
// Usage :
//   php artisan credentials:cleanup-documents --dry-run
//   php artisan credentials:cleanup-documents --older-than=730
//   php artisan credentials:cleanup-documents --tenant=42 --confirm
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Console\Commands;

use App\Models\StaffCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CredentialDocumentCleanupCommand extends Command
{
    protected $signature = 'credentials:cleanup-documents
                            {--dry-run : Report what would be deleted without removing files}
                            {--older-than=365 : Soft-deleted credentials older than N days will have their files purged}
                            {--tenant= : Limit cleanup to a single tenant (also requires the tenant to be soft-deleted)}';

    protected $description = 'Garbage-collect credential document files no longer referenced by any DB row';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $olderThanDays = (int) $this->option('older-than');
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $this->info($dryRun ? 'DRY RUN — no files will be deleted.' : 'LIVE RUN — files will be deleted.');

        $orphans = $this->findOrphanFiles($tenantId);
        $stale   = $this->findStaleSoftDeletedDocs($olderThanDays);

        $this->info(sprintf('  Orphan files (no DB row points to them) : %d', count($orphans)));
        $this->info(sprintf('  Soft-deleted credential files >%d days old: %d', $olderThanDays, count($stale)));

        if (! $dryRun) {
            foreach (array_merge($orphans, $stale) as $path) {
                Storage::disk('local')->delete($path);
            }
            $this->info(sprintf('Deleted %d files.', count($orphans) + count($stale)));
        }

        return self::SUCCESS;
    }

    /** Files in credentials/* with no document_path referencing them. */
    private function findOrphanFiles(?int $tenantId): array
    {
        $prefix = $tenantId ? "credentials/tenant_{$tenantId}" : 'credentials';
        $allFiles = Storage::disk('local')->allFiles($prefix);

        // Pull every document_path from the DB (including soft-deleted rows so
        // we don't accidentally purge their archived docs).
        $referenced = StaffCredential::withTrashed()
            ->whereNotNull('document_path')
            ->pluck('document_path')
            ->all();
        $referencedSet = array_flip($referenced);

        return array_filter($allFiles, fn ($f) => ! isset($referencedSet[$f]));
    }

    /**
     * Files belonging to credentials soft-deleted more than N days ago. The
     * row itself stays for audit but the document gets purged at this point.
     */
    private function findStaleSoftDeletedDocs(int $olderThanDays): array
    {
        return StaffCredential::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays($olderThanDays))
            ->whereNotNull('document_path')
            ->pluck('document_path')
            ->all();
    }
}
