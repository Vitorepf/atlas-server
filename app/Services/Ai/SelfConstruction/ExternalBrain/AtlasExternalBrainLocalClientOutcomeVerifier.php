<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure verifier that checks local client outcomes against required evidence
 * before they influence originator learning.
 *
 * Verification rules:
 *   - Success without test evidence → REJECTED (unverified success claim)
 *   - Give_back with reason → RETAINED (honest signal)
 *   - Malformed reports → QUARANTINED (cannot trust)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainLocalClientOutcomeVerifier
{
    public const SCHEMA = 'atlas.external_brain.local_client_outcome_verifier.v1';

    public const VERDICT_ACCEPTED = 'accepted';
    public const VERDICT_REJECTED = 'rejected';
    public const VERDICT_RETAINED = 'retained';
    public const VERDICT_QUARANTINED = 'quarantined';

    private const RUNNABLE_PROOF_MARKERS = ['phpunit', 'artisan test', 'pytest', 'jest', 'rspec'];

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function verify(array $report): array
    {
        $outcome = strtolower(trim((string) ($report['outcome'] ?? '')));
        $reason = trim((string) ($report['reason'] ?? ''));
        $evidence = (array) ($report['evidence'] ?? []);
        $clientId = (string) ($report['client_id'] ?? '');
        $taskId = (string) ($report['task_id'] ?? '');

        // Check for malformed report.
        if ($outcome === '') {
            return [
                'schema_version' => self::SCHEMA,
                'task_id' => $taskId,
                'client_id' => $clientId,
                'verdict' => self::VERDICT_QUARANTINED,
                'reasons' => ['malformed_report_missing_outcome'],
            ];
        }

        // Check for runnable proof in evidence.
        $hasRunnableProof = false;
        foreach ($evidence as $item) {
            $text = strtolower(trim((string) $item));
            foreach (self::RUNNABLE_PROOF_MARKERS as $marker) {
                if (str_contains($text, $marker)) {
                    $hasRunnableProof = true;
                    break 2;
                }
            }
        }

        $verdict = match ($outcome) {
            'success', 'completed', 'delivered' => $hasRunnableProof ? self::VERDICT_ACCEPTED : self::VERDICT_REJECTED,
            'give_back' => self::VERDICT_RETAINED,
            'blocked', 'quarantined' => self::VERDICT_RETAINED,
            default => self::VERDICT_QUARANTINED,
        };

        $reasons = [];
        if ($verdict === self::VERDICT_REJECTED) {
            $reasons[] = 'success_without_test_evidence';
        }
        if ($verdict === self::VERDICT_RETAINED) {
            $reasons[] = $reason !== '' ? 'give_back_with_reason:'.$reason : 'give_back_no_reason';
        }
        if ($verdict === self::VERDICT_ACCEPTED) {
            $reasons[] = 'success_with_runnable_proof';
        }
        if ($verdict === self::VERDICT_QUARANTINED) {
            $reasons[] = 'unknown_outcome:'.$outcome;
        }

        return [
            'schema_version' => self::SCHEMA,
            'task_id' => $taskId,
            'client_id' => $clientId,
            'verdict' => $verdict,
            'reasons' => $reasons,
            'outcome' => $outcome,
            'has_runnable_proof' => $hasRunnableProof,
        ];
    }
}
