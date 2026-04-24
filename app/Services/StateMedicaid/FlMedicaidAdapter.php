<?php

namespace App\Services\StateMedicaid;

/** FL MMIS adapter. Wraps 837P with MMIS header for portal upload. */
class FlMedicaidAdapter implements StateAdapter
{
    public function stateCode(): string { return 'FL'; }
    public function format(): string { return 'FL-MMIS-837P'; }

    public function transform(string $payload, array $metadata = []): string
    {
        $header = sprintf(
            "# FL MMIS Submission Header\n# Tenant: %s\n# Generated: %s\n# Format: %s\n---\n",
            $metadata['tenant_id'] ?? '?',
            now()->toIso8601String(),
            $this->format()
        );
        return $header . $payload;
    }
}
