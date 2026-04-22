<?php

// ─── TrrParserService ─────────────────────────────────────────────────────────
// Parses an uploaded CMS TRR (Transaction Reply Report) file.
//
// Expected format (honest-labeled — Phase 12 will adapter-swap for real HPMS):
//
//   HEADER|<contract_id>
//   <mbi>|<txn_code>|<result>|<trc_code>|<trc_description>|<effective YYYY-MM-DD>|<txn_date YYYY-MM-DD>
//   ...
//   TRAILER|<record_count>
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\TrrFile;
use App\Models\TrrRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TrrParserService
{
    public function parse(TrrFile $file, User $actor): TrrFile
    {
        $file->update(['status' => TrrFile::STATUS_PARSING]);

        try {
            $contents = Storage::disk('local')->get($file->storage_path);
            if ($contents === null || $contents === '') {
                throw new \RuntimeException('File is empty or unreadable.');
            }

            [$records, $headerContractId, $counts] = $this->parseContents($contents);

            DB::transaction(function () use ($file, $records) {
                TrrRecord::where('trr_file_id', $file->id)->delete();
                foreach ($records as $row) {
                    // Attempt to match to a local participant by MBI (clear comparison —
                    // Participant.medicare_id is encrypted column cast; we match via
                    // Eloquent equality which decrypts-on-read).
                    $matched = Participant::where('tenant_id', $file->tenant_id)
                        ->get(['id', 'medicare_id'])
                        ->first(fn ($p) => $p->medicare_id === $row['medicare_id']);

                    TrrRecord::create(array_merge($row, [
                        'tenant_id'              => $file->tenant_id,
                        'trr_file_id'            => $file->id,
                        'matched_participant_id' => $matched?->id,
                    ]));
                }
            });

            $file->update([
                'status'              => TrrFile::STATUS_PARSED,
                'parsed_at'           => now(),
                'contract_id'         => $file->contract_id ?? $headerContractId,
                'record_count'        => count($records),
                'accepted_count'      => $counts['accepted'] ?? 0,
                'rejected_count'      => $counts['rejected'] ?? 0,
                'parse_error_message' => null,
            ]);

            AuditLog::record(
                action:       'trr_file.parsed',
                tenantId:     $file->tenant_id,
                userId:       $actor->id,
                resourceType: 'trr_file',
                resourceId:   $file->id,
                description:  sprintf('TRR file parsed: %d records (%d accepted, %d rejected)',
                    count($records), $counts['accepted'] ?? 0, $counts['rejected'] ?? 0),
            );
        } catch (\Throwable $e) {
            Log::error('[TrrParserService] parse failed', ['file_id' => $file->id, 'err' => $e->getMessage()]);
            $file->update([
                'status'              => TrrFile::STATUS_PARSE_ERROR,
                'parse_error_message' => $e->getMessage(),
            ]);
        }

        return $file->fresh();
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: string|null, 2: array<string, int>}
     */
    public function parseContents(string $contents): array
    {
        $records = [];
        $headerContractId = null;
        $counts = ['accepted' => 0, 'rejected' => 0, 'pending' => 0, 'informational' => 0];

        $lines = preg_split('/\r\n|\r|\n/', trim($contents));
        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') continue;

            $parts = array_map('trim', explode('|', $line));

            if (($parts[0] ?? '') === 'HEADER') {
                $headerContractId = $parts[1] ?? null;
                continue;
            }
            if (($parts[0] ?? '') === 'TRAILER') continue;

            if (count($parts) < 7) continue;

            [$mbi, $txnCode, $result, $trcCode, $trcDesc, $effective, $txnDate] = $parts;

            $result = in_array($result, ['accepted', 'rejected', 'pending', 'informational'], true)
                ? $result
                : 'informational';
            $counts[$result] = ($counts[$result] ?? 0) + 1;

            $records[] = [
                'medicare_id'        => $mbi,
                'transaction_code'   => $txnCode,
                'transaction_label'  => TrrRecord::TRANSACTION_CODE_LABELS[$txnCode] ?? null,
                'transaction_result' => $result,
                'trc_code'           => $trcCode !== '' ? $trcCode : null,
                'trc_description'    => $trcDesc !== '' ? $trcDesc : null,
                'effective_date'     => $effective ? Carbon::parse($effective)->toDateString() : null,
                'transaction_date'   => $txnDate ? Carbon::parse($txnDate)->toDateString() : null,
                'raw_payload'        => ['raw' => $line, 'fields' => $parts],
            ];
        }

        return [$records, $headerContractId, $counts];
    }
}
