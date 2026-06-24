<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\CausalGraph;

/**
 * FACT-only detector for symbols whose original intent no live consumer needs anymore.
 *
 * It fails closed: a caller must provide an inventory-grounded symbol plus a known live-consumer count. A
 * bare FQCN, unknown count, or forbidden/petreo target is never harvested as dead intent.
 */
final class AtlasLoopDeadIntentDetector
{
    public const SCHEMA_VERSION = 'atlas.loop.dead_intent_detector.v1';

    /**
     * @param  list<array<string,mixed>|string>  $symbols
     * @return array{schema:string, dead_intent:list<array{fqcn:string, reason:string, live_consumer_count:int}>}
     */
    public function detect(array $symbols): array
    {
        $dead = [];
        foreach ($symbols as $symbol) {
            if (! is_array($symbol) || ! $this->isGrounded($symbol) || $this->isForbidden($symbol)) {
                continue;
            }

            $fqcn = $this->fqcn($symbol);
            $count = $this->liveConsumerCount($symbol);
            if ($fqcn === '' || $count === null || $count !== 0) {
                continue;
            }

            $dead[$fqcn] = [
                'fqcn' => $fqcn,
                'reason' => 'zero_live_consumers',
                'live_consumer_count' => 0,
            ];
        }

        ksort($dead, SORT_STRING);

        return [
            'schema' => self::SCHEMA_VERSION,
            'dead_intent' => array_values($dead),
        ];
    }

    /** @param array<string,mixed> $symbol */
    private function isGrounded(array $symbol): bool
    {
        if (($symbol['grounded'] ?? false) === true) {
            return true;
        }

        if (is_array($symbol['inventory_entry'] ?? null)) {
            return true;
        }

        foreach (['rel_path', 'path', 'source_path'] as $key) {
            if (is_string($symbol[$key] ?? null) && trim((string) $symbol[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $symbol */
    private function isForbidden(array $symbol): bool
    {
        foreach ([$symbol, (array) ($symbol['inventory_entry'] ?? [])] as $data) {
            foreach (['is_forbidden', 'forbidden', 'is_petreo', 'petreo', 'petrified', 'forbidden_target'] as $key) {
                if (($data[$key] ?? false) === true) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string,mixed> $symbol */
    private function liveConsumerCount(array $symbol): ?int
    {
        if (isset($symbol['live_consumer_count']) && is_numeric($symbol['live_consumer_count'])) {
            return max(0, (int) $symbol['live_consumer_count']);
        }

        foreach (['live_consumers', 'consumer_fqcns', 'consumers', 'wired_caller_paths'] as $key) {
            if (is_array($symbol[$key] ?? null)) {
                return count(array_values(array_filter(
                    (array) $symbol[$key],
                    static fn (mixed $value): bool => is_string($value) ? trim($value) !== '' : $value !== null,
                )));
            }
        }

        $entry = $symbol['inventory_entry'] ?? null;
        if (is_array($entry)) {
            return $this->liveConsumerCount($entry);
        }

        return null;
    }

    /** @param array<string,mixed> $symbol */
    private function fqcn(array $symbol): string
    {
        foreach (['fqcn', 'symbol', 'class'] as $key) {
            $value = $symbol[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return ltrim(trim($value), '\\');
            }
        }

        $entry = $symbol['inventory_entry'] ?? null;
        if (is_array($entry)) {
            return $this->fqcn($entry);
        }

        return '';
    }
}
