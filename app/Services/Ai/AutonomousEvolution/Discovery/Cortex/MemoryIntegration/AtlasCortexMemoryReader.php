<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration;

use App\Models\AtlasMemoryEntry;

final class AtlasCortexMemoryReader
{
    /**
     * @return list<array{
     *     memory_entry_id:string,
     *     memory_type:string,
     *     scope_type:string,
     *     scope_id:?string,
     *     title:?string,
     *     summary:?string,
     *     body:string,
     *     tags:list<string>,
     *     recorded_at:?string
     * }>
     */
    public function read(): array
    {
        return AtlasMemoryEntry::query()
            ->active()
            ->notSuperseded()
            ->where('external_ai_allowed', true)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (AtlasMemoryEntry $entry): bool => $this->publishedToCortex($entry))
            ->map(fn (AtlasMemoryEntry $entry): array => $this->normalize($entry))
            ->values()
            ->all();
    }

    private function publishedToCortex(AtlasMemoryEntry $entry): bool
    {
        $tags = array_values(array_filter((array) ($entry->tags ?? []), 'is_string'));
        $metadata = is_array($entry->metadata) ? $entry->metadata : [];
        $publishFlags = is_array($metadata['publish'] ?? null) ? $metadata['publish'] : [];

        return in_array('cortex_publish', $tags, true)
            || in_array('publish:cortex', $tags, true)
            || ($metadata['publish_to_cortex'] ?? false) === true
            || ($publishFlags['cortex'] ?? false) === true;
    }

    /**
     * @return array{
     *     memory_entry_id:string,
     *     memory_type:string,
     *     scope_type:string,
     *     scope_id:?string,
     *     title:?string,
     *     summary:?string,
     *     body:string,
     *     tags:list<string>,
     *     recorded_at:?string
     * }
     */
    private function normalize(AtlasMemoryEntry $entry): array
    {
        $tags = array_values(array_filter((array) ($entry->tags ?? []), 'is_string'));
        sort($tags, SORT_STRING);

        return [
            'memory_entry_id' => (string) $entry->getKey(),
            'memory_type' => (string) $entry->memory_type,
            'scope_type' => (string) $entry->scope_type,
            'scope_id' => $entry->scope_id === null ? null : (string) $entry->scope_id,
            'title' => $entry->redacted_title !== null ? (string) $entry->redacted_title : ($entry->title === null ? null : (string) $entry->title),
            'summary' => $entry->redacted_summary !== null ? (string) $entry->redacted_summary : ($entry->summary === null ? null : (string) $entry->summary),
            'body' => $entry->redacted_body !== null ? (string) $entry->redacted_body : (string) $entry->body,
            'tags' => $tags,
            'recorded_at' => $entry->recorded_at?->toIso8601String(),
        ];
    }
}
