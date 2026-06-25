<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\HardCaseBench;

use Throwable;

/**
 * Reports the FACT of WHICH Loop capabilities are bench-covered vs not — listing case_ids per capability,
 * each case's last run outcome, AND `capabilities_with_zero_cases` (the load-bearing blind-spot list).
 *
 * ANTI-GOODHART: NO aggregate score, NO percent, NO grade. The operator reads the facts; aggregation is
 * intentionally absent so a single number cannot be gamed.
 *
 * READ-ONLY: never writes to the registry. The capability list, the registry, and the per-case last-run
 * outcome lookup are all injected so the reporter stays portable and unit-testable.
 */
final class AtlasLoopHardCaseCoverageReporter
{
    /** @var callable():list<string> */
    private $capabilitiesSource;

    /** @var callable(string $caseId):?string returns the last run outcome (pass|fail|unknown) or null */
    private $lastRunOutcomeProvider;

    /** @var null|callable(array<string,mixed> $case):list<string> maps a case to the capabilities it covers */
    private $capabilityMapper;

    /**
     * @param  callable():list<string>            $capabilitiesSource       canonical Loop capabilities (e.g. originate / decompose / certify / merge / regression-net)
     * @param  callable(string):?string           $lastRunOutcomeProvider   case_id ⇒ last run outcome string (or null when no run)
     * @param  null|callable(array<string,mixed>):list<string>  $capabilityMapper  case ⇒ capability ids. Defaults to a sensible scope_root + source heuristic.
     */
    public function __construct(
        private readonly AtlasLoopHardCaseDatasetRegistry $registry,
        callable $capabilitiesSource,
        callable $lastRunOutcomeProvider,
        ?callable $capabilityMapper = null,
    ) {
        $this->capabilitiesSource = $capabilitiesSource;
        $this->lastRunOutcomeProvider = $lastRunOutcomeProvider;
        $this->capabilityMapper = $capabilityMapper;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        $raw = ($this->capabilitiesSource)();
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $c) {
            $c = trim((string) $c);
            if ($c !== '' && ! in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * @return array{
     *     capabilities:list<string>,
     *     per_capability:array<string,array{case_ids:list<string>, last_outcomes:array<string,?string>}>,
     *     capabilities_with_zero_cases:list<string>
     * }
     */
    public function report(): array
    {
        $capabilities = $this->capabilities();
        $cases = $this->registry->all();

        // Build capability → case_ids index.
        $byCapability = [];
        foreach ($capabilities as $cap) {
            $byCapability[$cap] = ['case_ids' => [], 'last_outcomes' => []];
        }
        foreach ($cases as $case) {
            $caseId = (string) ($case['case_id'] ?? '');
            if ($caseId === '') {
                continue;
            }
            $mapped = $this->mapCaseToCapabilities($case);
            foreach ($mapped as $cap) {
                if (! isset($byCapability[$cap])) {
                    continue; // capability not in canonical list ⇒ ignored
                }
                if (! in_array($caseId, $byCapability[$cap]['case_ids'], true)) {
                    $byCapability[$cap]['case_ids'][] = $caseId;
                    $byCapability[$cap]['last_outcomes'][$caseId] = $this->lastOutcomeFor($caseId);
                }
            }
        }

        $withZero = [];
        foreach ($capabilities as $cap) {
            sort($byCapability[$cap]['case_ids'], SORT_STRING);
            ksort($byCapability[$cap]['last_outcomes']);
            if ($byCapability[$cap]['case_ids'] === []) {
                $withZero[] = $cap;
            }
        }
        sort($withZero, SORT_STRING);

        return [
            'capabilities' => $capabilities,
            'per_capability' => $byCapability,
            'capabilities_with_zero_cases' => $withZero,
        ];
    }

    /**
     * Deterministic text table — capability | covered? | case_ids | last_outcomes.
     */
    public function renderText(): string
    {
        $report = $this->report();
        $lines = ['capability | covered | case_ids | last_outcomes'];
        foreach ($report['capabilities'] as $cap) {
            $bucket = $report['per_capability'][$cap];
            $cases = $bucket['case_ids'];
            $outcomes = [];
            foreach ($cases as $cid) {
                $outcomes[] = $cid.'='.($bucket['last_outcomes'][$cid] ?? 'null');
            }
            $lines[] = sprintf(
                '%s | %s | %s | %s',
                $cap,
                $cases === [] ? 'no' : 'yes',
                $cases === [] ? '-' : implode(',', $cases),
                $outcomes === [] ? '-' : implode(',', $outcomes),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    private function mapCaseToCapabilities(array $case): array
    {
        $mapper = $this->capabilityMapper;
        if (is_callable($mapper)) {
            $mapped = $mapper($case);

            return is_array($mapped) ? array_values(array_filter(array_map('strval', $mapped), static fn (string $c): bool => $c !== '')) : [];
        }

        // Default heuristic: scope_root SUFFIX + source token. Operators override via capabilityMapper.
        $scope = strtolower((string) ($case['scope_root'] ?? ''));
        $source = (string) ($case['source'] ?? '');
        $out = [];
        if ($source === 'judge_reject') {
            $out[] = 'certify';
        }
        if ($source === 'give_back' || $source === 'cancellation') {
            $out[] = 'originate';
        }
        if ($source === 'timeout') {
            $out[] = 'regression-net';
        }
        if (str_contains($scope, 'merge')) {
            $out[] = 'merge';
        }
        if (str_contains($scope, 'decompos')) {
            $out[] = 'decompose';
        }

        return array_values(array_unique($out));
    }

    private function lastOutcomeFor(string $caseId): ?string
    {
        try {
            $result = ($this->lastRunOutcomeProvider)($caseId);
        } catch (Throwable) {
            return null;
        }

        return $result === null ? null : (string) $result;
    }
}
