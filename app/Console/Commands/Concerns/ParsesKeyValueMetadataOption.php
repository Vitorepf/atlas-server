<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Parse repeated --metadata=key=value CLI options into an associative array.
 *
 * Full-pass reuse: byte-identical metadata() helpers on AtlasMemory* commands.
 */
trait ParsesKeyValueMetadataOption
{
    /**
     * @return array<string, string>
     */
    private function metadata(): array
    {
        $metadata = [];
        foreach ((array) $this->option('metadata') as $item) {
            if (! is_string($item) || ! str_contains($item, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $item, 2);
            $key = trim($key);
            if ($key !== '') {
                $metadata[$key] = trim($value);
            }
        }

        return $metadata;
    }
}
