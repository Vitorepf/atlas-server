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

        return [
            'schema' => self::SCHEMA,
            'claim' => $claimText,
            'status' => $status,
            'evidence_gaps' => $evidenceGaps,
            'proxy_signals' => $proxySignals,
            'next_evidence_task' => $this->nextEvidenceTask($status, $strongEvidence, $claimText),
        ];
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
