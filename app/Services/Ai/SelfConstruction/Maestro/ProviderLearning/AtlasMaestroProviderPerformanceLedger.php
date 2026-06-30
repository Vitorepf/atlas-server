<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderLearning;

/**
 * FACT-only ledger of per-(provider, task_class) outcomes. Tracks aggregates ONLY:
 *   success_count, give_back_count, duration_ms_sum, duration_ms_count, last_outcome_at.
 *
 * NO scoring, ranking, auto-routing — those are higher-layer concerns.
 *
 * Persistence: single JSON snapshot under storage/atlas/maestro/provider_performance_ledger.json
 * (following the AtlasLoopProjectionOutcomeLedger pattern). Byte-identical writes when no fact
 * changed: aggregates are kept in canonical sorted key order so the serialized blob is stable.
 */
final class AtlasMaestroProviderPerformanceLedger
{
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_GIVE_BACK = 'give_back';

    /** Atlas-native execution: zero provider cost, tracked as first-class provider. */
    public const ATLAS_NATIVE = 'atlas_native';

    private static ?string $rootOverride = null;

    public static function setRootForTesting(?string $root): void
    {
        self::$rootOverride = $root;
    }

    public function recordOutcome(
        string $provider,
        string $taskClass,
        string $outcome,
        int $durationMs,
        int $lastOutcomeAt,
        ?string $taskFamily = null,
        ?string $modelTier = null,
        ?int $tokenCostEstimate = null,
        ?bool $hasRequiredEvidence = null,
    ): void {
        $this->withLockedFile(function (array $state) use ($provider, $taskClass, $outcome, $durationMs, $lastOutcomeAt, $taskFamily, $modelTier, $tokenCostEstimate, $hasRequiredEvidence): array {
            $facts = $state['facts'] ?? [];
            $facts[$taskClass] ??= [];
            $row = $facts[$taskClass][$provider] ?? [
                'success_count' => 0,
                'give_back_count' => 0,
                'duration_ms_sum' => 0,
                'duration_ms_count' => 0,
                'last_outcome_at' => 0,
                'token_cost_sum' => 0,
                'has_required_evidence_count' => 0,
            ];
            $knownOutcome = false;
            if ($outcome === self::OUTCOME_SUCCESS) {
                $row['success_count']++;
                $knownOutcome = true;
            } elseif ($outcome === self::OUTCOME_GIVE_BACK) {
                $row['give_back_count']++;
                $knownOutcome = true;
            }
            if ($knownOutcome && $durationMs >= 0) {
                $row['duration_ms_sum'] += $durationMs;
                $row['duration_ms_count']++;
            }
            if ($lastOutcomeAt > (int) $row['last_outcome_at']) {
                $row['last_outcome_at'] = $lastOutcomeAt;
            }
            if ($taskFamily !== null) {
                $row['task_family'] = $taskFamily;
            }
            if ($modelTier !== null) {
                $row['model_tier'] = $modelTier;
            }
            if ($tokenCostEstimate !== null && $tokenCostEstimate >= 0) {
                $row['token_cost_sum'] = (int) ($row['token_cost_sum'] ?? 0) + $tokenCostEstimate;
            }
            if ($hasRequiredEvidence === true) {
                $row['has_required_evidence_count'] = (int) ($row['has_required_evidence_count'] ?? 0) + 1;
            }
            ksort($row);
            $facts[$taskClass][$provider] = $row;

            // family-level index
            if ($taskFamily !== null) {
                $familyFacts = (array) ($state['family_facts'] ?? []);
                $familyFacts[$taskFamily] ??= [];
                $frow = $familyFacts[$taskFamily][$provider] ?? [
                    'success_count' => 0,
                    'give_back_count' => 0,
                    'duration_ms_sum' => 0,
                    'duration_ms_count' => 0,
                ];
                if ($outcome === self::OUTCOME_SUCCESS) {
                    $frow['success_count']++;
                } elseif ($outcome === self::OUTCOME_GIVE_BACK) {
                    $frow['give_back_count']++;
                }
                if ($knownOutcome && $durationMs >= 0) {
                    $frow['duration_ms_sum'] += $durationMs;
                    $frow['duration_ms_count']++;
                }
                ksort($frow);
                $familyFacts[$taskFamily][$provider] = $frow;
                $state['family_facts'] = $familyFacts;
            }

            $state['facts'] = $facts;

            return $state;
        });
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function factsForClass(string $taskClass): array
    {
        $state = $this->load();
        $classFacts = (array) ($state['facts'][$taskClass] ?? []);
        $out = [];
        foreach ($classFacts as $provider => $row) {
            $row = (array) $row;
            $row['avg_duration_ms'] = ((int) $row['duration_ms_count']) > 0
                ? (int) round(((int) $row['duration_ms_sum']) / ((int) $row['duration_ms_count']))
                : 0;
            $out[(string) $provider] = $row;
        }
        ksort($out);

        return $out;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function factsForProvider(string $provider): array
    {
        $state = $this->load();
        $out = [];
        foreach ((array) ($state['facts'] ?? []) as $taskClass => $byProvider) {
            if (! is_array($byProvider) || ! isset($byProvider[$provider])) {
                continue;
            }
            $row = (array) $byProvider[$provider];
            $row['avg_duration_ms'] = ((int) $row['duration_ms_count']) > 0
                ? (int) round(((int) $row['duration_ms_sum']) / ((int) $row['duration_ms_count']))
                : 0;
            $out[(string) $taskClass] = $row;
        }
        ksort($out);

        return $out;
    }

    /**
     * Family-level read surface: aggregate facts by task_family.
     *
     * @return array<string, array<string,mixed>>
     */
    public function factsForFamily(string $taskFamily): array
    {
        $state = $this->load();
        $familyFacts = (array) ($state['family_facts'][$taskFamily] ?? []);
        $out = [];
        foreach ($familyFacts as $provider => $row) {
            $row = (array) $row;
            $row['avg_duration_ms'] = ((int) ($row['duration_ms_count'] ?? 0)) > 0
                ? (int) round(((int) ($row['duration_ms_sum'] ?? 0)) / ((int) ($row['duration_ms_count'] ?? 0)))
                : 0;
            $out[(string) $provider] = $row;
        }
        ksort($out);

        return $out;
    }

    /**
     * @return array<string, array<string, array<string,mixed>>>
     */
    public function allFacts(): array
    {
        $state = $this->load();
        $all = (array) ($state['facts'] ?? []);
        ksort($all);
        foreach ($all as $taskClass => &$byProvider) {
            ksort($byProvider);
        }
        unset($byProvider);

        return $all;
    }

    public function snapshotPath(): string
    {
        $root = self::$rootOverride ?? storage_path('atlas/maestro');

        return rtrim($root, '/').'/provider_performance_ledger.json';
    }

    /**
     * @return array<string,mixed>
     */
    private function load(): array
    {
        $path = $this->snapshotPath();
        if (! is_file($path)) {
            return ['facts' => []];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['facts' => []];
    }

    /**
     * @param  callable(array<string,mixed>): array<string,mixed>  $mutator
     */
    private function withLockedFile(callable $mutator): void
    {
        $path = $this->snapshotPath();
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return;
        }
        $fh = @fopen($path, 'cb+');
        if ($fh === false) {
            return;
        }
        try {
            @flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $state = is_string($raw) && $raw !== '' ? (array) json_decode($raw, true) : ['facts' => []];
            $next = $mutator(is_array($state) ? $state : ['facts' => []]);
            $next = $this->canonicalize($next);
            $encoded = (string) json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $encoded);
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    private function canonicalize(array $state): array
    {
        $facts = (array) ($state['facts'] ?? []);
        ksort($facts);
        foreach ($facts as &$byProvider) {
            if (! is_array($byProvider)) {
                continue;
            }
            ksort($byProvider);
            foreach ($byProvider as &$row) {
                if (is_array($row)) {
                    ksort($row);
                }
            }
            unset($row);
        }
        unset($byProvider);
        $state['facts'] = $facts;
        ksort($state);

        return $state;
    }
}
