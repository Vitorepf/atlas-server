<?php

namespace App\Http\Requests\Concerns;

trait NormalizesMetadata
{
    protected function normalizeMetadata(): void
    {
        if (! $this->has('metadata')) {
            return;
        }

        $metadata = $this->input('metadata');

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            $this->merge([
                'metadata' => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null,
            ]);
        }
    }
}
