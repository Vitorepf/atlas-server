<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Receipts;

use App\Services\Ai\AutonomousEvolution\AtlasLoopImpactReceiptService;
use Carbon\CarbonImmutable;

final class AtlasLoopCycleReceiptComposer
{
    public const SCHEMA_VERSION = 'atlas.loop.cycle_receipt.body.v1';

    /**
     * @param  array<string,mixed>  $sources
     * @return array<string,mixed>
     */
    public function compose(string $cycleId, array $sources): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'cycle_id' => $cycleId,
            'base_commit' => $this->stringSource($sources, ['base_commit', 'base_head', 'git_contract.base_commit', 'git_contract.base_head', 'cycle_git_contract.base_commit', 'cycle_git_contract.base_head']),
            'head_commit' => $this->stringSource($sources, ['head_commit', 'head_head', 'git_contract.head_commit', 'git_contract.head_head', 'git_contract.main_head_after', 'cycle_git_contract.head_commit', 'cycle_git_contract.main_head_after']),
            'facts' => [
                'impact' => $this->impactReceipts($sources),
                'frozen_verdicts' => $this->listSource($sources, ['frozen_verdicts', 'frozen.verdicts', 'frozen_judge.verdicts']),
                'telemetry' => $this->mapSource($sources, ['telemetry', 'attempt_ledger.telemetry', 'attempts.telemetry']),
                'feedback' => $this->mapSource($sources, ['feedback', 'maestro_feedback']),
                'maestro' => $this->mapSource($sources, ['maestro', 'maestro_handle']),
            ],
            'composed_at_iso' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $sources
     * @return list<array<string,mixed>>
     */
    private function impactReceipts(array $sources): array
    {
        $receipts = $this->listSource($sources, ['impact', 'impact_receipts', 'impact.receipts']);

        return array_values(array_filter(
            $receipts,
            static fn (array $receipt): bool => ($receipt['schema_version'] ?? null) === AtlasLoopImpactReceiptService::SCHEMA_VERSION,
        ));
    }

    /**
     * @param  array<string,mixed>  $sources
     * @param  list<string>  $paths
     */
    private function stringSource(array $sources, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $this->get($sources, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $sources
     * @param  list<string>  $paths
     * @return list<array<string,mixed>>
     */
    private function listSource(array $sources, array $paths): array
    {
        foreach ($paths as $path) {
            $value = $this->get($sources, $path);
            if (! is_array($value)) {
                continue;
            }

            $out = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $out[] = $item;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $sources
     * @param  list<string>  $paths
     * @return array<string,mixed>
     */
    private function mapSource(array $sources, array $paths): array
    {
        foreach ($paths as $path) {
            $value = $this->get($sources, $path);
            if (is_array($value)) {
                return $this->canonicalMap($value);
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $sources
     */
    private function get(array $sources, string $path): mixed
    {
        $cursor = $sources;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function canonicalMap(array $value): array
    {
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_array($item) ? $this->canonicalMap($item) : $item;
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
