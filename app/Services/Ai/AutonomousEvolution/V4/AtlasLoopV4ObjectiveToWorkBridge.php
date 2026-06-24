<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V4;

final class AtlasLoopV4ObjectiveToWorkBridge
{
    /**
     * @param  array<string,mixed>  $metaObjective
     * @param  list<mixed>  $comprehensionInventory
     * @return array{bridged:bool,scoped_inventory:list<string>,leverage_hint:?string,refuse_reason:?string}
     */
    public function bridge(array $metaObjective, array $comprehensionInventory): array
    {
        if (($metaObjective['originated'] ?? null) !== true) {
            return $this->refuse('upstream_not_originated');
        }

        $inventory = $this->normalizedInventory($comprehensionInventory);
        $mentions = $this->symbolMentions((string) ($metaObjective['objective'] ?? ''));
        $scoped = array_values(array_intersect($inventory, $mentions));
        sort($scoped, SORT_STRING);

        if ($scoped === []) {
            return $this->refuse('no_scoped_inventory');
        }

        return [
            'bridged' => true,
            'scoped_inventory' => $scoped,
            'leverage_hint' => trim((string) ($metaObjective['target_metric'] ?? '')).':+'.(string) ($metaObjective['target_delta'] ?? ''),
            'refuse_reason' => null,
        ];
    }

    /**
     * @return array{bridged:false,scoped_inventory:list<string>,leverage_hint:null,refuse_reason:string}
     */
    private function refuse(string $reason): array
    {
        return [
            'bridged' => false,
            'scoped_inventory' => [],
            'leverage_hint' => null,
            'refuse_reason' => $reason,
        ];
    }

    /**
     * @param  list<mixed>  $inventory
     * @return list<string>
     */
    private function normalizedInventory(array $inventory): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $symbol): string => trim((string) $symbol),
            $inventory,
        ), static fn (string $symbol): bool => $symbol !== ''));
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function symbolMentions(string $objective): array
    {
        preg_match_all('/[A-Z][A-Za-z0-9_\\\\]{2,}/', $objective, $matches);
        $mentions = array_values(array_unique($matches[0] ?? []));
        sort($mentions, SORT_STRING);

        return $mentions;
    }
}
