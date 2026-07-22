<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration;

final class AtlasCortexMemoryFactGrounder
{
    /**
     * @param  list<array{
     *     memory_entry_id:string,
     *     title:?string,
     *     summary:?string,
     *     body:string
     * }>  $memoryEntries
     * @param  list<array<string,mixed>>  $inventory
     * @return array<string, array{status:string,inventory_items:list<array<string,mixed>>}>
     */
    public function ground(array $memoryEntries, array $inventory): array
    {
        $inventory = $this->normalizeInventory($inventory);
        $mapping = [];

        foreach ($memoryEntries as $entry) {
            $entryId = (string) ($entry['memory_entry_id'] ?? '');
            if ($entryId === '') {
                continue;
            }

            $haystack = mb_strtolower(implode("\n", array_filter([
                is_string($entry['title'] ?? null) ? $entry['title'] : null,
                is_string($entry['summary'] ?? null) ? $entry['summary'] : null,
                is_string($entry['body'] ?? null) ? $entry['body'] : null,
            ])), 'UTF-8');

            $grounded = [];
            foreach ($inventory as $item) {
                if ($this->matchesInventoryItem($haystack, $item)) {
                    $grounded[] = $item;
                }
            }

            $mapping[$entryId] = [
                'status' => $grounded === [] ? 'ungrounded' : 'grounded',
                'inventory_items' => $grounded,
            ];
        }

        ksort($mapping, SORT_STRING);

        return $mapping;
    }

    /**
     * @param  list<array<string,mixed>>  $inventory
     * @return list<array<string,mixed>>
     */
    private function normalizeInventory(array $inventory): array
    {
        $normalized = array_values(array_filter($inventory, fn (mixed $item): bool => is_array($item) && is_string($item['id'] ?? null)));

        usort($normalized, static fn (array $left, array $right): int => strcmp((string) $left['id'], (string) $right['id']));

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function matchesInventoryItem(string $haystack, array $item): bool
    {
        foreach (['symbol', 'class', 'path'] as $field) {
            $value = $item[$field] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (str_contains($haystack, mb_strtolower($value, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }
}
