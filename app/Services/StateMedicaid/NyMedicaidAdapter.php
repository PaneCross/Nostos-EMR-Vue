<?php

namespace App\Services\StateMedicaid;

/** NY eMedNY adapter. Wraps 837P with eMedNY header for portal upload. */
class NyMedicaidAdapter implements StateAdapter
{
    public function stateCode(): string { return 'NY'; }
    public function format(): string { return 'NY-EMEDNY-837P'; }

    public function transform(string $payload, array $metadata = []): string
    {
        $header = sprintf(
            "# NY eMedNY Submission Header\n# Tenant: %s\n# Generated: %s\n# Format: %s\n---\n",
            $metadata['tenant_id'] ?? '?',
            now()->toIso8601String(),
            $this->format()
        );
        return $header . $payload;
    }
}
