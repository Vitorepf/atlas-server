<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure index that detects semantic duplicates by normalized capability keys,
 * target families, and acceptance intent — catching renamed duplicates before
 * they enter the queue.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasTaskFabricSemanticDuplicateIndex
{
    public const SCHEMA = 'atlas.task_fabric.semantic_duplicate_index.v1';

    public const DUPLICATE_SEMANTIC = 'semantic_duplicate';

    public const DUPLICATE_NONE = 'unique';

    /**
     * @param  array{
     *   capability_key?:string,
     *   target_family?:string,
     *   allowed_files?:list<string>,
     *   acceptance_intent?:string,
     *   task_packet_id?:string,
     * }  $candidate
     * @param  list<array{
     *   task_packet_id?:string,
     *   capability_key?:string,
     *   target_family?:string,
     *   allowed_files?:list<string>,
     *   acceptance_intent?:string,
     * }>  $existing
     * @return array{
     *   schema:string,
     *   status:string,
     *   matched_packet_id:?string,
     *   matched_target:?string,
     *   reasons:list<string>,
     * }
     */
    public function check(array $candidate, array $existing): array
    {
        $cCap = $this->normalizeKey((string) ($candidate['capability_key'] ?? ''));
        $cFamily = (string) ($candidate['target_family'] ?? '');
        $cIntent = $this->normalizeKey((string) ($candidate['acceptance_intent'] ?? ''));
        $cFiles = $this->normalizeFiles($candidate['allowed_files'] ?? []);

        foreach ($existing as $entry) {
            $eCap = $this->normalizeKey((string) ($entry['capability_key'] ?? ''));
            $eFamily = (string) ($entry['target_family'] ?? '');
            $eIntent = $this->normalizeKey((string) ($entry['acceptance_intent'] ?? ''));

            // Same capability key + same target family → semantic duplicate
            if ($cCap !== '' && $cCap === $eCap && $cFamily === $eFamily) {
                return $this->envelope(
                    self::DUPLICATE_SEMANTIC,
                    (string) ($entry['task_packet_id'] ?? ''),
                    $eFamily,
                    ['matched:capability_key+target_family:'.$cCap]
                );
            }

            // Same acceptance intent + same target family → semantic duplicate
            if ($cIntent !== '' && $cIntent === $eIntent && $cFamily === $eFamily && $cFamily !== '') {
                return $this->envelope(
                    self::DUPLICATE_SEMANTIC,
                    (string) ($entry['task_packet_id'] ?? ''),
                    $eFamily,
                    ['matched:acceptance_intent+target_family:'.$cIntent]
                );
            }
        }

        // Same files but different capabilities → NOT a duplicate (different non-overlapping work)
        // This is the expected outcome and we return unique.

        return $this->envelope(self::DUPLICATE_NONE, null, null, ['no_semantic_match']);
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;

        return trim($key, '_');
    }

    /** @param  list<string>  $files */
    private function normalizeFiles(array $files): array
    {
        $normalized = array_map(fn ($f) => strtolower(trim((string) $f)), $files);
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    private function envelope(string $status, ?string $matchedPacketId, ?string $matchedTarget, array $reasons): array
    {
        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'matched_packet_id' => $matchedPacketId,
            'matched_target' => $matchedTarget,
            'reasons' => $reasons,
        ];
    }
}
