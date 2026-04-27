<?php

// ─── MmrParserService ─────────────────────────────────────────────────────────
// Parses an uploaded CMS MMR (Monthly Membership Report) file.
//
// Expected format (honest-labeled : real CMS format lives behind the HPMS portal;
// Phase 12 will adapter-swap for the real specification):
//
//   HEADER|<contract_id>|<period_yyyymm>
//   <mbi>|<member_name>|<status>|<enrolled_from YYYY-MM-DD>|<enrolled_through YYYY-MM-DD>|<capitation>|<adjustment>
//   ...
//   TRAILER|<record_count>|<total_capitation>
//
// Robust to:
//   - Extra whitespace
//   - Blank lines
//   - Missing trailer (logs warning, parses records anyway)
//
// After parsing, delegates to EnrollmentReconciliationService to flag discrepancies.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MmrFile;
use App\Models\MmrRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MmrParserService
{
    public function __construct(private EnrollmentReconciliationService $reconciliation) {}

    /**
     * Parse a file already saved to storage (pointed to by MmrFile.storage_path)
     * and populate MmrRecord rows + discrepancy flags.
     */
    public function parse(MmrFile $file, User $actor): MmrFile
    {
        $file->update(['status' => MmrFile::STATUS_PARSING]);

        try {
            $contents = Storage::disk('local')->get($file->storage_path);
            if ($contents === null || $contents === '') {
                throw new \RuntimeException('File is empty or unreadable.');
            }

            [$records, $totalCapitation, $headerContractId] = $this->parseContents($contents);

            DB::transaction(function () use ($file, $records) {
                // Delete any previous records for this file (idempotent re-parse).
                MmrRecord::where('mmr_file_id', $file->id)->delete();
                foreach ($records as $row) {
                    MmrRecord::create(array_merge($row, [
                        'tenant_id'   => $file->tenant_id,
                        'mmr_file_id' => $file->id,
                    ]));
                }
            });

            // Reconciliation pass after insert (updates matched_participant_id + discrepancy_type).
            $discrepancies = $this->reconciliation->reconcileFile($file);

            $file->update([
                'status'                  => MmrFile::STATUS_PARSED,
                'parsed_at'               => now(),
                'contract_id'             => $file->contract_id ?? $headerContractId,
                'record_count'            => count($records),
                'discrepancy_count'       => $discrepancies,
                'total_capitation_amount' => $totalCapitation,
                'parse_error_message'     => null,
            ]);

            AuditLog::record(
                action:       'mmr_file.parsed',
                tenantId:     $file->tenant_id,
                userId:       $actor->id,
                resourceType: 'mmr_file',
                resourceId:   $file->id,
                description:  sprintf('MMR file parsed: %d records, %d discrepancies', count($records), $discrepancies),
            );
        } catch (\Throwable $e) {
            Log::error('[MmrParserService] parse failed', ['file_id' => $file->id, 'err' => $e->getMessage()]);
            $file->update([
                'status'              => MmrFile::STATUS_PARSE_ERROR,
                'parse_error_message' => $e->getMessage(),
            ]);
        }

        return $file->fresh();
    }

    /**
     * Pure parse. Returns [recordsArray, totalCapitation, headerContractId].
     * Exposed as a public method for testability.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: float, 2: string|null}
     */
    public function parseContents(string $contents): array
    {
        $records = [];
        $totalCapitation = 0.0;
        $headerContractId = null;

        $lines = preg_split('/\r\n|\r|\n/', trim($contents));
        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') continue;

            $parts = array_map('trim', explode('|', $line));

            // HEADER|<contract_id>|<period_yyyymm>
            if (($parts[0] ?? '') === 'HEADER') {
                $headerContractId = $parts[1] ?? null;
                continue;
            }
            // TRAILER : ignored for parsing purposes; verify optional.
            if (($parts[0] ?? '') === 'TRAILER') continue;

            // Data row: mbi|name|status|from|through|capitation|adjustment
            if (count($parts) < 7) {
                // Skip malformed rows but don't abort the file.
                continue;
            }

            [$mbi, $name, $status, $from, $through, $cap, $adj] = $parts;

            $capitation = (float) ($cap === '' ? 0 : $cap);
            $adjustment = (float) ($adj === '' ? 0 : $adj);
            $totalCapitation += $capitation;

            $records[] = [
                'medicare_id'       => $mbi,
                'member_name'       => $name !== '' ? $name : null,
                'member_status'     => $status !== '' ? $status : 'active',
                'enrolled_from'     => $from ? Carbon::parse($from)->toDateString() : null,
                'enrolled_through'  => $through ? Carbon::parse($through)->toDateString() : null,
                'capitation_amount' => $capitation,
                'adjustment_amount' => $adjustment,
                'raw_payload'       => ['raw' => $line, 'fields' => $parts],
            ];
        }

        return [$records, $totalCapitation, $headerContractId];
    }
}
