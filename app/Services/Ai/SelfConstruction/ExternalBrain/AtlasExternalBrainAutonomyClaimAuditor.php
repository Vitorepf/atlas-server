<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure auditor. Checks autonomy claims ("95% complete", "24/7 autonomous", "queue healthy",
 * "model amplifier works") against concrete evidence rather than accepting them at face value.
 *
 * Classifies each claim as proven, partial, stale, proxy, or unsupported, and names the smallest
 * next evidence task that would move the claim toward proven.
 *
 * Input shape: {claim:string, evidence_refs?:list<string>, proxy_signals?:list<string>,
 *               evidence_age_seconds?:int}
 *
 * Pure — no I/O, no provider calls, no enqueue.
 */
final class AtlasExternalBrainAutonomyClaimAuditor
{
    public const SCHEMA = 'atlas.self_construction.external_brain.autonomy_claim_auditor.v1';

    public const STATUS_PROVEN = 'proven';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_STALE = 'stale';

    public const STATUS_PROXY = 'proxy';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public const STATUS_OVERCLAIM = 'overclaim';

    /** Signals that look like evidence but carry no real proof of behavior. @var list<string> */
    private const PROXY_ONLY_SIGNALS = ['queue_count', 'task_count', 'green_self_report', 'novelty'];

    /** Evidence kinds that genuinely prove a claim, in priority order. @var list<string> */
    private const STRONG_EVIDENCE_KINDS = [
        'runnable_end_to_end_replay',
        'fresh_outcome_learning',
        'server_side_test_receipt',
        'live_metric_snapshot',
    ];

    private const STALE_THRESHOLD_SECONDS = 604800; // 7 days

    // AC2/AC3: absolute/total autonomy phrasing that demands more than the generic 2-strong-
    // evidence bar — literal phrases only, to avoid catching ordinary claims that merely mention
    // "autonomous" (e.g. "24/7 autonomous operation" is a normal claim, not an absolute one).
    private const STRONG_CLAIM_PHRASES = [
        '100 percent autonomous',
        '100% autonomous',
        'no human dependency',
        'zero human dependency',
        'final brain readiness',
        'final readiness',
        'fully autonomous',
        'complete autonomy',
    ];

    private const MIN_SOAK_DURATION_SECONDS = 86400; // 24h soak, per the loop's own 24h-soak-proof standard

    /**
     * @param  array<string,mixed>  $claim
     * @return array{schema:string, claim:string, status:string, evidence_gaps:list<string>, proxy_signals:list<string>, next_evidence_task:?string}
     */
    public function audit(array $claim): array
    {
        $claimText = trim((string) ($claim['claim'] ?? ''));
        $evidenceRefs = array_values(array_unique(array_map('strval', (array) ($claim['evidence_refs'] ?? []))));
        $proxySignals = array_values(array_unique(array_map('strval', (array) ($claim['proxy_signals'] ?? []))));
        $evidenceAge = isset($claim['evidence_age_seconds']) ? max(0, (int) $claim['evidence_age_seconds']) : null;

        $strongEvidence = array_values(array_intersect($evidenceRefs, self::STRONG_EVIDENCE_KINDS));
        $proxyOnly = $evidenceRefs === []
            && $proxySignals !== []
            && array_diff($proxySignals, self::PROXY_ONLY_SIGNALS) === [];

        $evidenceGaps = [];

        if ($evidenceRefs === [] && $proxySignals === []) {
            $status = self::STATUS_UNSUPPORTED;
            $evidenceGaps[] = 'no_evidence_refs_provided';
        } elseif ($proxyOnly) {
            $status = self::STATUS_PROXY;
            $evidenceGaps[] = 'only_proxy_signals_present:'.implode(',', $proxySignals);
        } elseif ($evidenceAge !== null && $evidenceAge > self::STALE_THRESHOLD_SECONDS) {
            $status = self::STATUS_STALE;
            $evidenceGaps[] = sprintf('evidence_age_seconds=%d_exceeds_threshold=%d', $evidenceAge, self::STALE_THRESHOLD_SECONDS);
        } elseif (count($strongEvidence) >= 2) {
            $status = self::STATUS_PROVEN;
        } elseif (count($strongEvidence) === 1) {
            $status = self::STATUS_PARTIAL;
            $evidenceGaps[] = 'only_one_strong_evidence_kind_present:'.$strongEvidence[0];
        } else {
            $status = self::STATUS_UNSUPPORTED;
            $evidenceGaps[] = 'no_strong_evidence_kind_present';
        }

        $rejectedProxyKinds = array_values(array_unique(array_intersect(
            array_merge($evidenceRefs, $proxySignals),
            self::PROXY_ONLY_SIGNALS,
        )));

        $evidenceFreshness = match (true) {
            $evidenceAge === null            => 'unknown',
            $evidenceAge > self::STALE_THRESHOLD_SECONDS => 'stale',
            default                          => 'fresh',
        };

        // AC2/AC3: an absolute autonomy claim ("100 percent autonomous", "no human dependency",
        // "final brain readiness") is never accepted on the generic 2-strong-evidence bar alone —
        // it additionally requires confirmed-fresh current runtime evidence, queue health
        // evidence, and a soak duration proof. Failing any of those downgrades proven/partial to
        // overclaim rather than silently accepting a weaker bar for stronger language (AC4).
        $soakDurationSeconds = max(0, (int) ($claim['soak_duration_seconds'] ?? 0));
        $queueHealthEvidence = (bool) ($claim['queue_health_evidence'] ?? false);
        $isStrongClaim = $this->matchesStrongClaimPhrase($claimText);
        $strongClaimGaps = [];

        if ($isStrongClaim && in_array($status, [self::STATUS_PROVEN, self::STATUS_PARTIAL], true)) {
            if ($evidenceFreshness !== 'fresh') {
                $strongClaimGaps[] = 'current_runtime_evidence_not_confirmed_fresh';
            }
            if (! $queueHealthEvidence) {
                $strongClaimGaps[] = 'queue_health_evidence_missing';
            }
            if ($soakDurationSeconds < self::MIN_SOAK_DURATION_SECONDS) {
                $strongClaimGaps[] = sprintf('soak_duration_seconds=%d_below_minimum=%d', $soakDurationSeconds, self::MIN_SOAK_DURATION_SECONDS);
            }
            if ($strongClaimGaps !== []) {
                $status = self::STATUS_OVERCLAIM;
                $evidenceGaps = array_merge($evidenceGaps, $strongClaimGaps);
            }
        }

        $confidence = match ($status) {
            self::STATUS_PROVEN  => 1.0,
            self::STATUS_PARTIAL => 0.5,
            default              => 0.0,
        };

        $missingStrongEvidenceKinds = array_values(array_diff(self::STRONG_EVIDENCE_KINDS, $strongEvidence));

        $proxyOnlyReasons = array_map(
            static fn (string $signal): string => "proxy_only_signal:{$signal}",
            $rejectedProxyKinds,
        );

        if ($status === self::STATUS_OVERCLAIM) {
            $nextEvidenceTask = sprintf('Produce %s to support claim: "%s".', $strongClaimGaps[0], $claimText);
            $nextProofChain = array_map(
                static fn (string $gap): string => sprintf('Produce %s to support claim: "%s".', $gap, $claimText),
                $strongClaimGaps,
            );
            $proofPriority = $strongClaimGaps[0];
        } else {
            $nextEvidenceTask = $this->nextEvidenceTask($status, $strongEvidence, $claimText);
            $nextProofChain = $this->nextProofChain($status, $missingStrongEvidenceKinds, $claimText);
            $proofPriority = $status === self::STATUS_PROVEN ? null : ($missingStrongEvidenceKinds[0] ?? null);
        }

        return [
            'schema' => self::SCHEMA,
            'claim' => $claimText,
            'status' => $status,
            'confidence' => $confidence,
            'evidence_gaps' => $evidenceGaps,
            'proxy_signals' => $proxySignals,
            'accepted_evidence_kinds' => $strongEvidence,
            'rejected_proxy_kinds' => $rejectedProxyKinds,
            'evidence_freshness' => $evidenceFreshness,
            'next_evidence_task' => $nextEvidenceTask,
            'next_proof_chain' => $nextProofChain,
            'missing_strong_evidence_kinds' => $missingStrongEvidenceKinds,
            'proxy_only_reasons' => $proxyOnlyReasons,
            'proof_priority' => $proofPriority,
            'is_strong_claim' => $isStrongClaim,
        ];
    }

    private function matchesStrongClaimPhrase(string $claimText): bool
    {
        $haystack = strtolower($claimText);
        foreach (self::STRONG_CLAIM_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Full ordered chain of remaining evidence tasks — not just the single next step —
     * so a partial claim shows every gap standing between it and proven, in priority order.
     *
     * @param  list<string>  $missingKinds
     * @return list<string>
     */
    private function nextProofChain(string $status, array $missingKinds, string $claimText): array
    {
        if ($status === self::STATUS_PROVEN) {
            return [];
        }

        return array_map(
            static fn (string $kind): string => sprintf('Produce %s evidence to support claim: "%s".', $kind, $claimText),
            $missingKinds,
        );
    }

    /** @param  list<string>  $strongEvidence */
    private function nextEvidenceTask(string $status, array $strongEvidence, string $claimText): ?string
    {
        if ($status === self::STATUS_PROVEN) {
            return null;
        }

        $missingKind = array_values(array_diff(self::STRONG_EVIDENCE_KINDS, $strongEvidence))[0] ?? null;
        if ($missingKind === null) {
            return null;
        }

        return sprintf('Produce %s evidence to support claim: "%s".', $missingKind, $claimText);
    }
}
