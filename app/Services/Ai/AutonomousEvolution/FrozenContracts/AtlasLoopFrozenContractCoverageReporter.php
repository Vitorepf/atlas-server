<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FrozenContracts;

use Throwable;

/**
 * Walks the configured set of loop-critical class FQCNs and emits a FACT (NOT a score) about frozen-contract
 * coverage:
 *   - with_contract                       : the loop-critical class carries a frozen-contract docblock
 *   - without_contract                    : it does not
 *   - with_sentinel                       : a paired sentinel test exists under tests/Feature/Loop/
 *   - orphan_contracts_without_sentinel   : it has a contract but the sentinel test is missing
 *
 * NO totals, NO percentages, NO ratios, NO scores. Goodhart-farming hooks are intentionally absent —
 * downstream code derives whatever aggregates it wants from these FACTS itself.
 *
 * The loop-critical set is loaded from `atlas.loop.frozen_contracts.loop_critical` (default empty list).
 */
final class AtlasLoopFrozenContractCoverageReporter
{
    public const CONFIG_LOOP_CRITICAL_KEY = 'atlas.loop.frozen_contracts.loop_critical';

    private const SENTINEL_TESTS_DIR_REL = 'tests/Feature/Loop';

    /** @var null|callable():(list<string>) */
    private $classListSource;

    /** @var null|callable():string */
    private $sentinelTestsDirResolver;

    /**
     * @param  null|callable():(list<string>)  $classListSource  override for testing (default = config)
     * @param  null|callable():string          $sentinelTestsDirResolver  override for testing (default = base_path/tests/Feature/Loop)
     */
    public function __construct(?callable $classListSource = null, ?callable $sentinelTestsDirResolver = null)
    {
        $this->classListSource = $classListSource;
        $this->sentinelTestsDirResolver = $sentinelTestsDirResolver;
    }

    /**
     * @return array{with_contract:list<string>, without_contract:list<string>, with_sentinel:list<string>, orphan_contracts_without_sentinel:list<string>}
     */
    public function report(): array
    {
        $classes = $this->loadClassList();
        $sentinelDir = $this->loadSentinelDir();

        $withContract = [];
        $withoutContract = [];
        $withSentinel = [];
        $orphans = [];

        foreach ($classes as $fqcn) {
            $hasContract = $this->classCarriesFrozenContract($fqcn);
            if ($hasContract) {
                $withContract[] = $fqcn;
            } else {
                $withoutContract[] = $fqcn;
            }

            $hasSentinel = $this->sentinelExistsForClass($sentinelDir, $fqcn);
            if ($hasSentinel) {
                $withSentinel[] = $fqcn;
            }
            if ($hasContract && ! $hasSentinel) {
                $orphans[] = $fqcn;
            }
        }

        sort($withContract, SORT_STRING);
        sort($withoutContract, SORT_STRING);
        sort($withSentinel, SORT_STRING);
        sort($orphans, SORT_STRING);

        return [
            'with_contract' => $withContract,
            'without_contract' => $withoutContract,
            'with_sentinel' => $withSentinel,
            'orphan_contracts_without_sentinel' => $orphans,
        ];
    }

    /**
     * @return list<string>
     */
    private function loadClassList(): array
    {
        $source = $this->classListSource;
        if (is_callable($source)) {
            $raw = $source();

            return array_values(array_filter(array_map('strval', is_array($raw) ? $raw : []), static fn (string $c): bool => $c !== ''));
        }
        if (! function_exists('config')) {
            return [];
        }
        try {
            $raw = (array) config(self::CONFIG_LOOP_CRITICAL_KEY, []);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $raw), static fn (string $c): bool => $c !== ''));
    }

    private function loadSentinelDir(): ?string
    {
        $resolver = $this->sentinelTestsDirResolver;
        if (is_callable($resolver)) {
            $dir = (string) $resolver();

            return $dir === '' ? null : rtrim($dir, '/');
        }
        if (function_exists('base_path')) {
            try {
                return rtrim((string) base_path(self::SENTINEL_TESTS_DIR_REL), '/');
            } catch (Throwable) {
            }
        }

        return null;
    }

    /**
     * A class "carries a frozen contract" iff its class-level docblock contains a recognised invariant
     * prefix (INVARIANT / PETREO / PÉTREO / FROZEN). Cheap reflection probe.
     */
    private function classCarriesFrozenContract(string $fqcn): bool
    {
        try {
            $rc = new \ReflectionClass($fqcn);
        } catch (Throwable) {
            return false;
        }
        $doc = (string) $rc->getDocComment();
        if ($doc === '') {
            return false;
        }

        return preg_match('/\b(INVARIANT|PETREO|PÉTREO|FROZEN)\b/i', $doc) === 1;
    }

    private function sentinelExistsForClass(?string $sentinelDir, string $fqcn): bool
    {
        if ($sentinelDir === null || ! is_dir($sentinelDir)) {
            return false;
        }
        $short = $this->shortClass($fqcn);
        $path = $sentinelDir.'/'.$short.'FrozenContractSentinelTest.php';

        return is_file($path);
    }

    private function shortClass(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
