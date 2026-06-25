<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\HardCaseBench;

/**
 * A candidate hard case proposed by {@see AtlasLoopHardCaseHistoricalFailureMiner}. Candidates are NOT
 * registered automatically — they are proposals the operator (or a higher-altitude policy) evaluates before
 * promotion through the registry's append entry point.
 */
final class HardCaseCandidate
{
    /**
     * @param  array<string,mixed>  $minimalReproSeed  any structured payload the operator may need to repro
     */
    public function __construct(
        public readonly string $caseId,
        public readonly string $slug,
        public readonly string $capturedAt,
        public readonly string $source,
        public readonly string $scopeRoot,
        public readonly string $failureSignature,
        public readonly string $originalAttemptLedgerDigest,
        public readonly array $minimalReproSeed,
        public readonly string $expectedFailureMode,
    ) {
    }

    /**
     * @return array<string,mixed>  shape ready for AtlasLoopHardCaseDatasetRegistry append entry point
     */
    public function toRegistryShape(): array
    {
        return [
            'case_id' => $this->caseId,
            'slug' => $this->slug,
            'captured_at' => $this->capturedAt,
            'source' => $this->source,
            'scope_root' => $this->scopeRoot,
            'failure_signature' => $this->failureSignature,
            'original_attempt_ledger_digest' => $this->originalAttemptLedgerDigest,
            'minimal_repro_seed' => $this->minimalReproSeed,
            'expected_failure_mode' => $this->expectedFailureMode,
        ];
    }
}

/**
 * Mines historical Loop failures (give_back, cancellation, judge_reject, timeout) from an injected event
 * source and emits {@see HardCaseCandidate} records the operator can promote to the registry. Candidates
 * already frozen in the supplied {@see AtlasLoopHardCaseDatasetRegistry} are excluded (dedupe).
 *
 * ANTI-GOODHART: this miner NEVER writes to the registry. Auto-registering would let the loop quietly drop
 * cases it cannot pass. A reflection-asserted test pins the no-registry-write invariant.
 */
final class AtlasLoopHardCaseHistoricalFailureMiner
{
    /** @var callable(int $sinceDays):iterable<array{source:string, scope_root:string, failure_reason:string, diff_shape_hash:?string, captured_at:string, ledger_digest:?string, repro_seed?:array<string,mixed>, expected_failure_mode?:string}> */
    private $eventsSource;

    public function __construct(
        private readonly AtlasLoopHardCaseDatasetRegistry $registry,
        callable $eventsSource,
    ) {
        $this->eventsSource = $eventsSource;
    }

    /**
     * @return list<HardCaseCandidate>
     */
    public function mineCandidates(int $sinceDays = 90): array
    {
        $alreadyKnown = [];
        foreach ($this->registry->all() as $r) {
            $alreadyKnown[(string) ($r['case_id'] ?? '')] = true;
        }

        $candidates = [];
        foreach (($this->eventsSource)($sinceDays) as $event) {
            $source = (string) ($event['source'] ?? '');
            if (! in_array($source, AtlasLoopHardCaseDatasetRegistry::ALLOWED_SOURCES, true)) {
                continue;
            }
            $signature = $this->failureSignature(
                (string) ($event['failure_reason'] ?? ''),
                (string) ($event['diff_shape_hash'] ?? ''),
                (string) ($event['scope_root'] ?? ''),
            );
            $caseId = $this->caseIdFor($source, $signature);
            if (isset($alreadyKnown[$caseId])) {
                continue;
            }
            // Cluster by signature within this batch — same signature + same source ⇒ one candidate.
            if (isset($candidates[$caseId])) {
                continue;
            }
            $candidates[$caseId] = new HardCaseCandidate(
                caseId: $caseId,
                slug: $this->slugFor($source, $signature),
                capturedAt: (string) ($event['captured_at'] ?? ''),
                source: $source,
                scopeRoot: (string) ($event['scope_root'] ?? ''),
                failureSignature: $signature,
                originalAttemptLedgerDigest: (string) ($event['ledger_digest'] ?? ''),
                minimalReproSeed: is_array($event['repro_seed'] ?? null) ? $event['repro_seed'] : [],
                expectedFailureMode: (string) ($event['expected_failure_mode'] ?? $source),
            );
        }

        $out = array_values($candidates);
        usort($out, static fn (HardCaseCandidate $x, HardCaseCandidate $y): int => $x->caseId <=> $y->caseId);

        return $out;
    }

    private function failureSignature(string $reason, string $diffShapeHash, string $scopeRoot): string
    {
        $normalised = strtolower(trim($reason));
        $normalised = (string) preg_replace('/\s+/', '_', $normalised);
        // Composite signature: normalised reason + diff-shape hash + scope_root.
        $raw = $normalised.'|'.$diffShapeHash.'|'.$scopeRoot;

        return hash('sha256', $raw);
    }

    private function caseIdFor(string $source, string $signature): string
    {
        return 'hc-'.$source.'-'.substr($signature, 0, 16);
    }

    private function slugFor(string $source, string $signature): string
    {
        return $source.'/'.substr($signature, 0, 8);
    }
}
