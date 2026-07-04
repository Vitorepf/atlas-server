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

    public const FIXTURE_TEST_ONLY_MISSING_IMPLEMENTATION_SUSPECT = 'test_only_missing_implementation_suspect';

    public const FIXTURE_SCHEMA_MISMATCH_SUSPECT = 'schema_mismatch_suspect';

    public const ACTION_GIVE_BACK_OR_RESPEC = 'give_back_or_respec';

    public const ACTION_RESUBMIT_WITH_RECOVERED_FIELDS = 'resubmit_with_recovered_fields';

    public const ACTION_RETIRE_AS_DUPLICATE = 'retire_as_duplicate';

    /**
     * @return array<string, array{source_packet:array<string,mixed>, expected_likely_family:string, expected_recoverable_fields:list<string>, expected_can_submit:bool, expected_safe_next_action:string, anti_regression_reason:string}>
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
                'anti_regression_reason' => 'a totally empty packet must never be allowed to resubmit — a respec planner that fabricates fields for it is hallucinating, not recovering.',
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
                'anti_regression_reason' => 'a packet whose objective already names both the class and test path IS genuinely recoverable — a planner that stops recovering this case regresses real throughput, not just safety.',
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
                'anti_regression_reason' => 'a packet targeting a forbidden/property-gated file must never resubmit as-is — a regression here means the planner would let a worker touch a file it is not allowed to touch.',
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
                'anti_regression_reason' => 'mutually exclusive acceptance bullets can never both be satisfied — a regression here means the planner would resubmit a spec that is impossible to complete honestly.',
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
                'anti_regression_reason' => 'a packet already marked as a duplicate of completed work must never resubmit — a regression here means the planner would spend a worker re-doing finished work.',
            ],
            self::FIXTURE_TEST_ONLY_MISSING_IMPLEMENTATION_SUSPECT => [
                'source_packet' => [
                    'objective' => 'Add tested behavior to app/Services/Ai/Foo/AtlasFoo.php.',
                    'allowed_files' => ['tests/Unit/Ai/Foo/AtlasFooTest.php'],
                    'acceptance_criteria' => ['AtlasFooTest passes'],
                ],
                'expected_likely_family' => 'test_only_missing_implementation',
                'expected_recoverable_fields' => [],
                'expected_can_submit' => false,
                'expected_safe_next_action' => self::ACTION_GIVE_BACK_OR_RESPEC,
                'anti_regression_reason' => 'a packet whose allowed_files contains only a test file, with no implementation file to make it pass, can never be served as-is — a regression here means the planner would resubmit a scope no worker can actually complete.',
            ],
            self::FIXTURE_SCHEMA_MISMATCH_SUSPECT => [
                'source_packet' => [
                    'objective' => 'Refactor app/Services/Ai/Foo/AtlasFoo.php so it validates input faster.',
                    'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
                    'acceptance_criteria' => ['AtlasFooTest passes'],
                    'metadata' => ['schema_version_drift' => 'current=v2 expected=v3'],
                ],
                'expected_likely_family' => 'schema_mismatch',
                'expected_recoverable_fields' => ['acceptance_criteria', 'required_evidence'],
                'expected_can_submit' => false,
                'expected_safe_next_action' => self::ACTION_GIVE_BACK_OR_RESPEC,
                'anti_regression_reason' => 'a packet whose schema version drifts from the expected version must not be served — a regression here means the planner would serve a task whose contract shape does not match what the runtime expects.',
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

    public const FIXTURE_LIVE_SHAPE_ALL_UNKNOWN_BLOCKED = 'live_shape_all_unknown_blocked';

    /**
     * Names this regression case: the current live failure shape where a whole blocked
     * plan collapses to family=unknown and every replacement draft comes back
     * review_recommended / can_submit=false because allowed_files, acceptance_criteria
     * and required_evidence were never recoverable from the source packet.
     *
     * @return array{blocked_count:int, blocked_counts:array<string,int>, replacement_drafts:list<array<string,mixed>>, can_submit:bool}
     */
    public function liveShapeBlockedPlan(): array
    {
        $missingFields = ['allowed_files', 'acceptance_criteria', 'required_evidence'];
        $drafts = [];
        for ($i = 0; $i < 5; $i++) {
            $drafts[] = [
                'source_packet_id' => 'blocked-'.$i,
                'family' => 'unknown',
                'recommendation' => 'review_recommended',
                'can_submit' => false,
                'missing_fields' => $missingFields,
                'recovered_fields' => [],
            ];
        }

        return [
            'blocked_count' => count($drafts),
            'blocked_counts' => ['unknown' => count($drafts)],
            'replacement_drafts' => $drafts,
            'can_submit' => false,
        ];
    }

    /**
     * The repaired counterpart: classifier + field recovery + completer together produce
     * at least one submit-ready replacement draft, with recovered_field_count tracking how
     * many previously-missing fields were actually filled in.
     *
     * @return array{blocked_count:int, blocked_counts:array<string,int>, replacement_drafts:list<array<string,mixed>>, can_submit:bool, recovered_field_count:int}
     */
    public function repairedLiveShapeBlockedPlan(): array
    {
        $plan = $this->liveShapeBlockedPlan();

        $plan['replacement_drafts'][0] = [
            'source_packet_id' => 'blocked-0',
            'family' => 'recoverable_implementation_gap',
            'recommendation' => 'resubmit_with_recovered_fields',
            'can_submit' => true,
            'missing_fields' => [],
            'recovered_fields' => ['allowed_files', 'acceptance_criteria', 'required_evidence'],
        ];
        $plan['blocked_counts'] = [
            'unknown' => count($plan['replacement_drafts']) - 1,
            'recoverable_implementation_gap' => 1,
        ];
        $plan['can_submit'] = true;

        return array_merge($plan, [
            'recovered_field_count' => $this->evaluatePlan($plan)['recovered_field_count'],
        ]);
    }

    /**
     * Evaluates ANY blocked plan shape (live, fixture, or future) against the harness's
     * pass condition: at least one submit-ready replacement draft.
     *
     * @param  array{replacement_drafts?: list<array<string,mixed>>}  $plan
     * @return array{passes:bool, submit_ready_count:int, recovered_field_count:int}
     */
    public function evaluatePlan(array $plan): array
    {
        $drafts = is_array($plan['replacement_drafts'] ?? null) ? $plan['replacement_drafts'] : [];

        $submitReadyCount = 0;
        $recoveredFieldCount = 0;
        foreach ($drafts as $draft) {
            if (! is_array($draft)) {
                continue;
            }
            if (($draft['can_submit'] ?? false) === true) {
                $submitReadyCount++;
            }
            $recoveredFieldCount += count((array) ($draft['recovered_fields'] ?? []));
        }

        return [
            'passes' => $submitReadyCount >= 1,
            'submit_ready_count' => $submitReadyCount,
            'recovered_field_count' => $recoveredFieldCount,
        ];
    }

    /**
     * AC3: reports pass/fail per known poison fixture — did the observed respec-planner output
     * for that fixture's source_packet actually match what the fixture demands?
     *
     * @param  array<string, array{likely_family?:string, can_submit?:bool, safe_next_action?:string}>  $observedByFixtureName
     * @return array<string, array{passed:bool, reason:string, repair_action:string, anti_regression_reason:string}>
     */
    public function evaluateAgainstObservedResults(array $observedByFixtureName): array
    {
        $results = [];
        foreach ($this->fixtures() as $name => $fixture) {
            $observed = is_array($observedByFixtureName[$name] ?? null) ? $observedByFixtureName[$name] : null;

            if ($observed === null) {
                $results[$name] = [
                    'passed' => false,
                    'reason' => 'no_observed_result_supplied',
                    'repair_action' => $fixture['expected_safe_next_action'],
                    'anti_regression_reason' => $fixture['anti_regression_reason'],
                ];

                continue;
            }

            $familyMatches = (string) ($observed['likely_family'] ?? '') === $fixture['expected_likely_family'];
            $submitMatches = (bool) ($observed['can_submit'] ?? false) === $fixture['expected_can_submit'];
            $actionMatches = (string) ($observed['safe_next_action'] ?? '') === $fixture['expected_safe_next_action'];
            $passed = $familyMatches && $submitMatches && $actionMatches;

            $results[$name] = [
                'passed' => $passed,
                'reason' => $passed ? 'matches_expected_fixture' : 'observed_result_diverges_from_expected_fixture',
                'repair_action' => $fixture['expected_safe_next_action'],
                'anti_regression_reason' => $fixture['anti_regression_reason'],
            ];
        }

        return $results;
    }

    /**
     * AC4: refuses to certify a respec plan as safe when ANY known poison fixture regresses —
     * a respec planner is only trusted once every frozen poison case still behaves as expected.
     *
     * @param  array<string, array{likely_family?:string, can_submit?:bool, safe_next_action?:string}>  $observedByFixtureName
     * @return array{schema:string, safe:bool, regressed_fixtures:list<string>, evaluation:array<string,array<string,mixed>>}
     */
    public function certifyRespecPlanSafe(array $observedByFixtureName): array
    {
        $evaluation = $this->evaluateAgainstObservedResults($observedByFixtureName);
        $regressed = array_values(array_keys(array_filter(
            $evaluation,
            static fn (array $r): bool => ! $r['passed'],
        )));

        return [
            'schema' => self::SCHEMA,
            'safe' => $regressed === [],
            'regressed_fixtures' => $regressed,
            'evaluation' => $evaluation,
            'replayed_cases' => count($evaluation),
            'failed_cases' => count($regressed),
            'enqueue_allowed' => $regressed === [],
        ];
    }
}
