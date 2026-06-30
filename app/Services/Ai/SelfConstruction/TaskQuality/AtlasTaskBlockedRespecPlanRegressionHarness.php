<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure fixture generator that freezes the CURRENT failure mode where a large blocked backlog
 * collapses into family=unknown and replacement drafts come back can_submit=false because of
 * missing required fields — so future changes to the unknown-reduction pipeline can be regression
 * tested against compact fixtures without needing a live queue.
 *
 * Each fixture pairs a minimal source_packet with the expected_likely_family, the fields a
 * recovery pass should be able to fill, whether a resubmitted draft should be allowed to submit,
 * and the safe next action a governor should take.
 *
 * Pure in-memory PHP data generation — no queue, DB, filesystem, provider, process, or git side
 * effects.
 */
final class AtlasTaskBlockedRespecPlanRegressionHarness
{
    public const SCHEMA = 'atlas.self_construction.task_quality.blocked_respec_plan_regression_harness.v1';

    public const FIXTURE_EMPTY_FIELD_BLOCKED = 'empty_field_blocked';

    public const FIXTURE_OBJECTIVE_WITH_CLASS_AND_TEST_PATH = 'objective_with_class_and_test_path';

    public const FIXTURE_FORBIDDEN_TARGET_SUSPECT = 'forbidden_target_suspect';

    public const FIXTURE_CONTRADICTORY_ACCEPTANCE_SUSPECT = 'contradictory_acceptance_suspect';

    public const FIXTURE_DUPLICATE_ALREADY_DONE_SUSPECT = 'duplicate_already_done_suspect';

    public const ACTION_GIVE_BACK_OR_RESPEC = 'give_back_or_respec';

    public const ACTION_RESUBMIT_WITH_RECOVERED_FIELDS = 'resubmit_with_recovered_fields';

    public const ACTION_RETIRE_AS_DUPLICATE = 'retire_as_duplicate';

    /**
     * @return array<string, array{source_packet:array<string,mixed>, expected_likely_family:string, expected_recoverable_fields:list<string>, expected_can_submit:bool, expected_safe_next_action:string}>
     */
    public function fixtures(): array
    {
        return [
            // THE failure mode this harness exists to freeze: nothing to recover from, family
            // collapses to 'unknown', and the resubmitted draft can never reach can_submit=true.
            self::FIXTURE_EMPTY_FIELD_BLOCKED => [
                'source_packet' => [
                    'objective' => '',
                    'allowed_files' => [],
                    'acceptance_criteria' => [],
                    'required_evidence' => [],
                ],
                'expected_likely_family' => 'unknown',
                'expected_recoverable_fields' => [],
                'expected_can_submit' => false,
                'expected_safe_next_action' => self::ACTION_GIVE_BACK_OR_RESPEC,
            ],
            self::FIXTURE_OBJECTIVE_WITH_CLASS_AND_TEST_PATH => [
                'source_packet' => [
                    'objective' => 'Implement app/Services/Ai/Foo/AtlasFoo.php and tests/Unit/Ai/Foo/AtlasFooTest.php so it validates input.',
                    'allowed_files' => [],
                    'acceptance_criteria' => [],
                    'required_evidence' => [],
                ],
                'expected_likely_family' => 'recoverable_implementation_gap',
                'expected_recoverable_fields' => ['allowed_files', 'acceptance_criteria', 'required_evidence'],
                'expected_can_submit' => true,
                'expected_safe_next_action' => self::ACTION_RESUBMIT_WITH_RECOVERED_FIELDS,
            ],
            self::FIXTURE_FORBIDDEN_TARGET_SUSPECT => [
                'source_packet' => [
                    'objective' => 'Implement app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php so it validates input.',
                    'allowed_files' => [],
                    'metadata' => [
                        'forbidden_or_property_gated' => ['app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php'],
                    ],
                ],
                'expected_likely_family' => 'forbidden_target',
                'expected_recoverable_fields' => [],
                'expected_can_submit' => false,
                'expected_safe_next_action' => self::ACTION_GIVE_BACK_OR_RESPEC,
            ],
            self::FIXTURE_CONTRADICTORY_ACCEPTANCE_SUSPECT => [
                'source_packet' => [
                    'objective' => 'Implement app/Services/Ai/Foo/AtlasFoo.php so it validates input.',
                    'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php'],
                    'acceptance_criteria' => ['the response must always be cached', 'the response must never be cached'],
                ],
                'expected_likely_family' => 'contradictory_acceptance',
                'expected_recoverable_fields' => [],
                'expected_can_submit' => false,
                'expected_safe_next_action' => self::ACTION_GIVE_BACK_OR_RESPEC,
            ],
            self::FIXTURE_DUPLICATE_ALREADY_DONE_SUSPECT => [
                'source_packet' => [
                    'objective' => 'Implement app/Services/Ai/Foo/AtlasFoo.php so it validates input.',
                    'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php'],
                    'metadata' => [
                        'duplicate_of' => 'already-done-packet-id',
                    ],
                ],
                'expected_likely_family' => 'duplicate_already_done',
                'expected_recoverable_fields' => [],
                'expected_can_submit' => false,
                'expected_safe_next_action' => self::ACTION_RETIRE_AS_DUPLICATE,
            ],
        ];
    }

    /**
     * @return array{source_packet:array<string,mixed>, expected_likely_family:string, expected_recoverable_fields:list<string>, expected_can_submit:bool, expected_safe_next_action:string}|null
     */
    public function fixture(string $name): ?array
    {
        return $this->fixtures()[$name] ?? null;
    }
}
