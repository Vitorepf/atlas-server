<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Throwable;

/**
 * P5 mitigation: deterministic hard-case deck from historical loop failures.
 *
 * The harness never rewrites acceptance. It only selects source task packets whose family has enough
 * failed history, then copies allowed_files + acceptance verbatim into a replayable case.
 */
final class AtlasLoopHardCaseHarness
{
    public const SCHEMA_VERSION = 'atlas.loop.hard_case_harness.v1';

    public function __construct(
        private readonly ?AtlasLoopDecompositionOutcomeRecorder $outcomeRecorder = null,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $taskPackets
     * @param  array<string,mixed>  $options
     * @return list<array<string,mixed>>
     */
    public function curate(array $taskPackets = [], ?AtlasLoopAttemptLedger $ledger = null, array $options = []): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $threshold = max(0.0, min(1.0, (float) ($options['failure_rate_threshold'] ?? 0.5)));
        $minAttempts = max(1, (int) ($options['min_attempts'] ?? 3));
        $stats = $this->hardFamilies($ledger, $threshold, $minAttempts);
        if ($stats === [] || $taskPackets === []) {
            return [];
        }

        $cases = [];
        foreach ($taskPackets as $packet) {
            $family = $this->packetFamily($packet);
            if ($family === '' || ! isset($stats[$family])) {
                continue;
            }
            $allowedFiles = $this->arrayValue($packet['allowed_files'] ?? []);
            $acceptance = is_array($packet['acceptance'] ?? null) ? $packet['acceptance'] : [];
            $minimalPacket = [
                'task_packet_id' => (string) ($packet['task_packet_id'] ?? $family),
                'objective' => (string) ($packet['objective'] ?? ''),
                'objective_kind' => $family,
                'allowed_files' => $allowedFiles,
                'acceptance' => $acceptance,
            ];
            if (isset($packet['acceptance_criteria'])) {
                $minimalPacket['acceptance_criteria'] = $packet['acceptance_criteria'];
            }

            $caseId = substr(hash('sha256', json_encode([
                'family' => $family,
                'allowed_files' => $allowedFiles,
                'acceptance' => $acceptance,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 24);

            $cases[] = [
                'schema_version' => self::SCHEMA_VERSION.'.hard_case',
                'case_id' => $caseId,
                'family' => $family,
                'failure_rate' => $stats[$family]['failure_rate'],
                'attempts' => $stats[$family]['attempts'],
                'failed' => $stats[$family]['failed'],
                'source' => $stats[$family]['source'],
                'task_packet' => $minimalPacket,
                'allowed_files' => $allowedFiles,
                'acceptance' => $acceptance,
                'acceptance_sha256' => hash('sha256', json_encode($acceptance, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            ];
        }

        usort($cases, static function (array $a, array $b): int {
            return ((float) $b['failure_rate'] <=> (float) $a['failure_rate'])
                ?: ((int) $b['attempts'] <=> (int) $a['attempts'])
                ?: strcmp((string) $a['family'], (string) $b['family'])
                ?: strcmp((string) $a['case_id'], (string) $b['case_id']);
        });

        return array_values($cases);
    }

    public function enabled(): bool
    {
        try {
            return function_exists('config')
                && (bool) config('atlas.loop.hard_case_harness_enabled', false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string,array{family:string,attempts:int,failed:int,failure_rate:float,source:string}>
     */
    private function hardFamilies(?AtlasLoopAttemptLedger $ledger, float $threshold, int $minAttempts): array
    {
        $stats = [];
        foreach ($this->ledgerFamilyStats($ledger, $threshold, $minAttempts) as $row) {
            $stats[$row['family']] = $row;
        }
        foreach ($this->recorderFamilyStats($threshold, $minAttempts) as $row) {
            if (! isset($stats[$row['family']])) {
                $stats[$row['family']] = $row;
                continue;
            }
            $existing = $stats[$row['family']];
            $attempts = $existing['attempts'] + $row['attempts'];
            $failed = $existing['failed'] + $row['failed'];
            $stats[$row['family']] = [
                'family' => $row['family'],
                'attempts' => $attempts,
                'failed' => $failed,
                'failure_rate' => $attempts > 0 ? round($failed / $attempts, 4) : 0.0,
                'source' => $existing['source'].'+'.$row['source'],
            ];
        }

        return array_filter(
            $stats,
            static fn (array $row): bool => $row['attempts'] >= $minAttempts && $row['failure_rate'] > $threshold,
        );
    }

    /**
     * @return list<array{family:string,attempts:int,failed:int,failure_rate:float,source:string}>
     */
    private function ledgerFamilyStats(?AtlasLoopAttemptLedger $ledger, float $threshold, int $minAttempts): array
    {
        if ($ledger === null) {
            return [];
        }

        $families = [];
        foreach ($ledger->attempts() as $attempt) {
            $family = $this->attemptFamily($attempt);
            if ($family === '') {
                continue;
            }
            $families[$family] ??= ['family' => $family, 'attempts' => 0, 'failed' => 0, 'failure_rate' => 0.0, 'source' => 'attempt_ledger'];
            $families[$family]['attempts']++;
            if (! (bool) ($attempt['passed'] ?? false)) {
                $families[$family]['failed']++;
            }
        }

        foreach ($families as $family => $row) {
            $attempts = (int) $row['attempts'];
            $families[$family]['failure_rate'] = $attempts > 0 ? round((int) $row['failed'] / $attempts, 4) : 0.0;
        }

        return array_values(array_filter(
            $families,
            static fn (array $row): bool => $row['attempts'] >= $minAttempts && $row['failure_rate'] > $threshold,
        ));
    }

    /**
     * @return list<array{family:string,attempts:int,failed:int,failure_rate:float,source:string}>
     */
    private function recorderFamilyStats(float $threshold, int $minAttempts): array
    {
        try {
            return ($this->outcomeRecorder ?? new AtlasLoopDecompositionOutcomeRecorder)
                ->hardObjectiveKinds($threshold, $minAttempts);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $attempt
     */
    private function attemptFamily(array $attempt): string
    {
        $signature = trim((string) ($attempt['signature'] ?? ''));
        foreach (['family:', 'objective_kind:', 'packet:'] as $prefix) {
            if (str_starts_with($signature, $prefix)) {
                return mb_substr($signature, strlen($prefix));
            }
        }

        return $signature;
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function packetFamily(array $packet): string
    {
        foreach (['objective_kind', 'task_family', 'work_class', 'kind', 'task_packet_id'] as $key) {
            $value = trim((string) ($packet[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array<int|string,mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
