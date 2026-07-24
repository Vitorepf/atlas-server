<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use App\Models\AtlasMemoryEntry;

/**
 * Provider-safe safety projection for AtlasMemoryEntry CLI/API surfaces.
 *
 * Full-pass reuse: de-duplicates private safetySummary() on AtlasMemory* commands.
 *
 * @return array<string, mixed>
 */
final class MemoryEntrySafetySummary
{
    public static function forEntry(AtlasMemoryEntry $entry): array
    {
        $providerExportAllowed = $entry->external_ai_allowed === true
            && $entry->privacy_class !== 'secret'
            && $entry->redaction_status !== 'blocked';

        return [
            'schema_version' => 'atlas.memory_entry.safety.v1',
            'memory_eligible' => $entry->status === 'active',
            'context_eligible' => $entry->status === 'active' && $providerExportAllowed,
            'provider_export_allowed' => $providerExportAllowed,
            'open_brain_context_allowed' => $entry->status === 'active' && $providerExportAllowed,
            'raw_content_exposed' => false,
            'privacy_class' => $entry->privacy_class,
            'redaction_status' => $entry->redaction_status,
            'content_hash' => $entry->content_hash,
        ];
    }
}
