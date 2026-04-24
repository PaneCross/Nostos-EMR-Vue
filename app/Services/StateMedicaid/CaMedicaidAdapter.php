<?php

namespace App\Services\StateMedicaid;

/** CA MEDS adapter. Wraps 837P with CA-MEDS header for portal upload. */
class CaMedicaidAdapter implements StateAdapter
{
    public function stateCode(): string { return 'CA'; }
    public function format(): string { return 'CA-MEDS-837P'; }

    public function transform(string $payload, array $metadata = []): string
    {
        $header = sprintf(
            "# CA-MEDS Submission Header\n# Tenant: %s\n# Generated: %s\n# Format: %s\n---\n",
            $metadata['tenant_id'] ?? '?',
            now()->toIso8601String(),
            $this->format()
        );
        return $header . $payload;
    }
}
