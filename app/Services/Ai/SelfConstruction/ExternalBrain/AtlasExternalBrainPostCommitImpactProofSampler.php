<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure post-commit impact sampler. Compares what a task spec promised against
 * what actually shipped (tests, impl files, queue-health effect) and emits an
 * honest impact label that rankers can use to up/down-weight future tasks from
 * the same pattern family.
 *
 * Impact label hierarchy (first match wins):
 *   high     — impl files + test files + non-empty capability delta + queue improved
 *   medium   — impl files + test files + non-empty capability delta
 *   low      — impl files present but tests missing OR delta empty
 *   none     — test-only commit OR no impl files and no capability delta
 *   negative — cosmetic-only commit OR capability delta was promised but no impl
 *              or test evidence of it is observable
 *
 * Ranking signals (fed back to future rankers):
 *   high     → +0.30
 *   medium   → +0.15
 *   low      → +0.05
 *   none     →  0.00
 *   negative → -0.20
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainPostCommitImpactProofSampler
{
    public const SCHEMA = 'atlas.external_brain.post_commit_impact_proof_sampler.v1';

    public const LABEL_HIGH     = 'high';
    public const LABEL_MEDIUM   = 'medium';
    public const LABEL_LOW      = 'low';
    public const LABEL_NONE     = 'none';
    public const LABEL_NEGATIVE = 'negative';

    private const RANKING_SIGNALS = [
        self::LABEL_HIGH     => +0.30,
        self::LABEL_MEDIUM   => +0.15,
        self::LABEL_LOW      => +0.05,
        self::LABEL_NONE     =>  0.00,
        self::LABEL_NEGATIVE => -0.20,
    ];

    /**
     * @param  array{
     *   commit_sha?: string,
     *   task_packet_id?: string,
     *   promised_capability_delta?: string,
     *   observed_test_files?: list<string>,
     *   observed_impl_files?: list<string>,
     *   queue_health_effect?: string,
     *   cosmetic_only?: bool,
     *   test_only?: bool,
     *   observed_behavior_delta?: string,
     *   learning_delta?: string,
     *   acceptance_delta?: string,
     * }  $input
     * @return array{schema:string, commit_sha:string, task_packet_id:string, impact_label:string, label_reason:string, promised_delta_observed:bool, ranking_signal:float, causal_proof_strength:float}
     */
    public function sample(array $input): array
    {
        $commitSha       = trim((string) ($input['commit_sha']                ?? ''));
        $taskPacketId    = trim((string) ($input['task_packet_id']            ?? ''));
        $promisedDelta   = trim((string) ($input['promised_capability_delta'] ?? ''));
        $queueEffect     = trim((string) ($input['queue_health_effect']       ?? 'neutral'));
        $cosmeticOnly    = (bool) ($input['cosmetic_only'] ?? false);
        $testOnly        = (bool) ($input['test_only']     ?? false);
        $behaviorDelta   = trim((string) ($input['observed_behavior_delta']   ?? ''));
        $learningDelta   = trim((string) ($input['learning_delta']            ?? ''));
        $acceptanceDelta = trim((string) ($input['acceptance_delta']          ?? ''));

        $implFiles = array_values(array_filter(array_map('trim', (array) ($input['observed_impl_files'] ?? [])), fn (string $s): bool => $s !== ''));
        $testFiles = array_values(array_filter(array_map('trim', (array) ($input['observed_test_files'] ?? [])), fn (string $s): bool => $s !== ''));

        $hasImpl    = $implFiles !== [];
        $hasTests   = $testFiles !== [];
        $hasDelta   = $promisedDelta !== '';
        $queueUp    = $queueEffect === 'improved';

        [$label, $reason] = $this->classify($cosmeticOnly, $testOnly, $hasImpl, $hasTests, $hasDelta, $queueUp, $promisedDelta);

        $promisedDeltaObserved = $hasImpl && $hasTests;

        // AC3: causal_proof_strength = satisfied proof signals / 7 total.
        $satisfied = array_sum([
            (int) $hasImpl,
            (int) $hasTests,
            (int) $hasDelta,
            (int) $queueUp,
            (int) ($behaviorDelta !== ''),
            (int) ($learningDelta !== ''),
            (int) ($acceptanceDelta !== ''),
        ]);
        $causalProofStrength = round($satisfied / 7, 4);

        return [
            'schema'                  => self::SCHEMA,
            'commit_sha'              => $commitSha,
            'task_packet_id'          => $taskPacketId,
            'impact_label'            => $label,
            'label_reason'            => $reason,
            'promised_delta_observed' => $promisedDeltaObserved,
            'ranking_signal'          => self::RANKING_SIGNALS[$label],
            'causal_proof_strength'   => $causalProofStrength,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function classify(
        bool $cosmeticOnly,
        bool $testOnly,
        bool $hasImpl,
        bool $hasTests,
        bool $hasDelta,
        bool $queueUp,
        string $promisedDelta,
    ): array {
        if ($cosmeticOnly) {
            return [self::LABEL_NEGATIVE, 'cosmetic_only_commit_no_capability_gain'];
        }

        if ($hasDelta && ! $hasImpl && ! $hasTests) {
            return [self::LABEL_NEGATIVE, 'promised_capability_delta_not_observable_in_evidence'];
        }

        if ($hasImpl && $hasTests && $hasDelta && $queueUp) {
            return [self::LABEL_HIGH, 'impl_tests_delta_and_improved_queue_health'];
        }

        if ($hasImpl && $hasTests && $hasDelta) {
            return [self::LABEL_MEDIUM, 'impl_tests_and_capability_delta_present'];
        }

        if ($hasImpl) {
            return [self::LABEL_LOW, $hasTests ? 'impl_present_but_no_capability_delta' : 'impl_present_but_no_test_coverage'];
        }

        if ($testOnly || (! $hasImpl && ! $hasDelta)) {
            return [self::LABEL_NONE, $testOnly ? 'test_only_commit_no_impl_changes' : 'no_impl_files_and_no_capability_delta'];
        }

        return [self::LABEL_NONE, 'insufficient_evidence_for_positive_label'];
    }
}
