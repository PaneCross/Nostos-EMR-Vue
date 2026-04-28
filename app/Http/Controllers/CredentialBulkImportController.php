<?php

// ─── CredentialBulkImportController ──────────────────────────────────────────
// One-shot CSV upload for onboarding existing staff credentials in bulk.
// Useful at go-live when an org migrates from a spreadsheet.
//
// CSV columns (header row required, exact names):
//   email                : matches an existing user by email (skip if not found)
//   credential_code      : matches a CredentialDefinition by code (optional ;
//                          falls back to free-form when blank)
//   credential_type      : required when no code given
//   title                : required when no code given
//   license_state        : optional, 2 chars
//   license_number       : optional
//   issued_at            : optional ISO date
//   expires_at           : optional ISO date
//   verification_source  : optional (state_board|npdb|uploaded_doc|self_attestation|other)
//   dot_medical_card_expires_at : optional ISO date (driver_record only)
//   mvr_check_date              : optional ISO date (driver_record only)
//   vehicle_class_endorsements  : optional free text (driver_record only)
//   notes                : optional
//
// Returns a per-row outcome report so the user can fix and re-upload.
//
// Gate: it_admin OR super_admin.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CredentialDefinition;
use App\Models\StaffCredential;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CredentialBulkImportController extends Controller
{
    private function gate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_unless(
            $u->isSuperAdmin() || $u->department === 'it_admin',
            403,
            'Only IT Admin (or Super Admin) may bulk-import credentials.'
        );
    }

    public function page(Request $request): InertiaResponse
    {
        $this->gate($request);
        return Inertia::render('ItAdmin/CredentialBulkImport');
    }

    public function import(Request $request): JsonResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $contents = file_get_contents($request->file('csv')->getRealPath());
        $rows = $this->parseCsv($contents);

        if (empty($rows)) {
            return response()->json(['error' => 'CSV is empty or unreadable.'], 422);
        }

        $report = ['created' => 0, 'skipped' => 0, 'errors' => [], 'rows' => []];

        // Pre-cache definitions + users for efficiency
        $defByCode  = CredentialDefinition::forTenant($tenantId)->get()->keyBy('code');
        $userByEmail = User::where('tenant_id', $tenantId)->get()->keyBy('email');

        foreach ($rows as $idx => $row) {
            $rowNum = $idx + 2; // header is row 1, first data row is 2
            $email  = trim($row['email'] ?? '');

            $user = $userByEmail->get(strtolower($email));
            if (! $user) {
                $report['skipped']++;
                $report['rows'][] = [
                    'row' => $rowNum, 'email' => $email,
                    'outcome' => 'skipped', 'reason' => "No user with email {$email}",
                ];
                continue;
            }

            $code = trim($row['credential_code'] ?? '');
            $def  = $code !== '' ? $defByCode->get($code) : null;

            $type  = $def?->credential_type ?? trim($row['credential_type'] ?? '');
            $title = $def?->title ?? trim($row['title'] ?? '');

            if ($type === '' || $title === '') {
                $report['skipped']++;
                $report['rows'][] = [
                    'row' => $rowNum, 'email' => $email,
                    'outcome' => 'skipped',
                    'reason' => 'credential_type and title are required when no credential_code is given',
                ];
                continue;
            }

            // Enforce definition-driven rules : doc_required + requires_psv.
            // Bulk import has no file attachment so doc_required defs are
            // skipped with a reason ; admin must add the row via the per-user
            // page where they can upload a doc.
            if ($def && $def->default_doc_required) {
                $report['skipped']++;
                $report['rows'][] = [
                    'row' => $rowNum, 'email' => $email,
                    'outcome' => 'skipped',
                    'reason' => "{$def->title} requires a supporting document : add it from the per-user credentials page (bulk import does not accept files).",
                ];
                continue;
            }
            if ($def && $def->requires_psv) {
                $src = $row['verification_source'] ?? '';
                if (! in_array($src, ['state_board', 'npdb'], true)) {
                    $report['skipped']++;
                    $report['rows'][] = [
                        'row' => $rowNum, 'email' => $email,
                        'outcome' => 'skipped',
                        'reason' => "{$def->title} requires primary-source verification : verification_source must be 'state_board' or 'npdb'.",
                    ];
                    continue;
                }
            }

            try {
                $cred = StaffCredential::create([
                    'tenant_id' => $tenantId,
                    'user_id'   => $user->id,
                    'credential_definition_id' => $def?->id,
                    'credential_type' => $type,
                    'title'           => $title,
                    'license_state'   => $row['license_state']   ?? null,
                    'license_number'  => $row['license_number']  ?? null,
                    'issued_at'       => $row['issued_at']       ?? null,
                    'expires_at'      => $row['expires_at']      ?? null,
                    'verification_source' => $row['verification_source'] ?? null,
                    'cms_status'      => 'active',
                    'verified_at'     => now(),
                    'verified_by_user_id' => $request->user()->id,
                    // V2 : optional driver-record fields when type=driver_record
                    'dot_medical_card_expires_at' => $row['dot_medical_card_expires_at'] ?? null,
                    'mvr_check_date'              => $row['mvr_check_date']              ?? null,
                    'vehicle_class_endorsements'  => $row['vehicle_class_endorsements']  ?? null,
                    'notes'           => $row['notes'] ?? null,
                ]);

                $report['created']++;
                $report['rows'][] = [
                    'row' => $rowNum, 'email' => $email,
                    'outcome' => 'created', 'credential_id' => $cred->id,
                    'title' => $title,
                ];
            } catch (\Throwable $e) {
                $report['errors'][] = "Row {$rowNum}: {$e->getMessage()}";
                $report['rows'][] = [
                    'row' => $rowNum, 'email' => $email,
                    'outcome' => 'error', 'reason' => $e->getMessage(),
                ];
            }
        }

        AuditLog::record(
            action: 'staff_credential.bulk_imported',
            resourceType: 'StaffCredential',
            resourceId: 0,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: ['created' => $report['created'], 'skipped' => $report['skipped']],
        );

        return response()->json($report);
    }

    /** Parse CSV into associative-array rows keyed by header. */
    private function parseCsv(string $contents): array
    {
        // Strip BOM
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = preg_split('/\r\n|\r|\n/', trim($contents));
        if (count($lines) < 2) return [];

        $headers = array_map('trim', str_getcsv(array_shift($lines)));
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $values = str_getcsv($line);
            if (count($values) !== count($headers)) {
                // pad short rows with nulls
                $values = array_pad($values, count($headers), null);
            }
            $rows[] = array_combine($headers, $values);
        }
        return $rows;
    }
}
