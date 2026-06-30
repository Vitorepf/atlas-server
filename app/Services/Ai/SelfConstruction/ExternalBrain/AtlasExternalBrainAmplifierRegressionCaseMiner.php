<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure miner: converts failed or weak amplified proposals into concrete,
 * provider-free regression cases for the spec regression harness.
 *
 * A failure record is rejected when any of the following hold:
 *   A. has_provider_trace = true (provider internals not allowed in harness)
 *   B. expected_verdict not in VALID_VERDICTS ('rejection','repair','acceptance')
 *   C. observed_behavior shorter than MIN_DESCRIPTION_LENGTH chars (too vague)
 *
 * Accepted failures produce regression_cases with: case_id, case_type,
 * input_pattern (first 100 chars of proposal_text), expected_verdict, and
 * an optional repair_action. harness_tags are unique failure types from
 * promoted cases. expected_verdicts maps failure_id → expected_verdict.
 */
final class AtlasExternalBrainAmplifierRegressionCaseMiner
{
    public const SCHEMA = 'atlas.external_brain.amplifier_regression_case_miner.v1';

    public const MIN_DESCRIPTION_LENGTH = 30;

    public const VALID_VERDICTS = ['rejection', 'repair', 'acceptance'];

    /**
     * @param  array<string,mixed>  $input  failure_records list
     * @return array<string,mixed>
     */
    public function mine(array $input): array
    {
        $failures = is_array($input['failure_records'] ?? null) ? $input['failure_records'] : [];

        $regressionCases = [];
        $rejectedFailures = [];
        $expectedVerdicts = [];
        $harnessTagSet = [];

        foreach ($failures as $failure) {
            $id = (string) ($failure['failure_id'] ?? 'unknown');
            $failureType = (string) ($failure['failure_type'] ?? 'unknown');
            $proposalText = (string) ($failure['proposal_text'] ?? '');
            $observedBehavior = (string) ($failure['observed_behavior'] ?? '');
            $expectedVerdict = (string) ($failure['expected_verdict'] ?? '');
            $repairAction = trim((string) ($failure['repair_action'] ?? ''));
            $hasProviderTrace = (bool) ($failure['has_provider_trace'] ?? false);

            $rejectionReason = null;
            if ($hasProviderTrace) {
                $rejectionReason = 'contains_provider_trace';
            } elseif (! in_array($expectedVerdict, self::VALID_VERDICTS, true)) {
                $rejectionReason = 'invalid_or_missing_verdict';
            } elseif (mb_strlen($observedBehavior) < self::MIN_DESCRIPTION_LENGTH) {
                $rejectionReason = 'observed_behavior_too_vague';
            }

            if ($rejectionReason !== null) {
                $rejectedFailures[] = ['failure_id' => $id, 'reason' => $rejectionReason];

                continue;
            }

            $regressionCases[] = array_filter([
                'case_id' => $id,
                'case_type' => $failureType,
                'input_pattern' => mb_substr($proposalText, 0, 100),
                'expected_verdict' => $expectedVerdict,
                'repair_action' => $repairAction !== '' ? $repairAction : null,
            ]);

            $expectedVerdicts[$id] = $expectedVerdict;
            $harnessTagSet[$failureType] = true;
        }

        return [
            'schema_version' => self::SCHEMA,
            'regression_cases' => $regressionCases,
            'rejected_failures' => $rejectedFailures,
            'expected_verdicts' => $expectedVerdicts,
            'harness_tags' => array_keys($harnessTagSet),
        ];
    }
}
