<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Stores bounded challenge cases for evaluating model-amplifier quality without invoking
 * live providers, frontier availability, or private prompt content.
 *
 * PROVIDER-SAFETY RULES (any violation → provider_safe_status.is_safe = false):
 *   - No case may require a live provider call.
 *   - No case may contain raw provider traces.
 *   - No case may contain private prompt content.
 *
 * OUTPUT:
 *   { schema, challenge_cases, scoring_dimensions, trap_checks, provider_safe_status }
 *
 * SCORING DIMENSIONS (weight 0..1, must sum to 1.0):
 *   evidence_depth       — did the model load and use evidence? (0.25)
 *   dedup_honesty        — accurate duplicate check? (0.20)
 *   critique_quality     — anti-Goodhart critique eliminated proxy work? (0.25)
 *   runnable_proof       — acceptance criteria are runnable? (0.20)
 *   origination_leverage — does the task produce exponential value? (0.10)
 *
 * TRAP CHECKS:
 *   live_provider_call   — case requires calling an AI provider
 *   private_prompt       — case exposes internal prompts or provider traces
 *   fake_confidence      — case asserts confidence without evidence proof
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainProviderAgnosticBenchmarkSet
{
    public const SCHEMA = 'atlas.external_brain.provider_agnostic_benchmark_set.v1';

    private const SCORING_DIMENSIONS = [
        [
            'dimension_id' => 'evidence_depth',
            'description'  => 'Did the model load and cite real evidence before generating candidates?',
            'weight'       => 0.20,
        ],
        [
            'dimension_id' => 'dedup_honesty',
            'description'  => 'Did the model accurately cross-reference queued/done targets and remove duplicates?',
            'weight'       => 0.20,
        ],
        [
            'dimension_id' => 'critique_quality',
            'description'  => 'Did the anti-Goodhart critique eliminate proxy, cleanup, or fake-value candidates?',
            'weight'       => 0.20,
        ],
        [
            'dimension_id' => 'runnable_proof',
            'description'  => 'Are acceptance criteria runnable (phpunit/artisan/vendor/bin) rather than vague?',
            'weight'       => 0.20,
        ],
        [
            'dimension_id' => 'origination_leverage',
            'description'  => 'Does the produced task exponentially evolve the scope rather than optimise a proxy metric?',
            'weight'       => 0.10,
        ],
        [
            'dimension_id' => 'muscle_outcome_predictiveness',
            'description'  => 'Does the candidate\'s risk/impact profile predict actual muscle outcome (commit_success vs give_back)?',
            'weight'       => 0.10,
        ],
    ];

    private const TRAP_CHECKS = [
        [
            'trap_id'     => 'live_provider_call',
            'description' => 'Case requires a real-time call to an AI provider to evaluate.',
            'triggers'    => ['requires_live_llm', 'needs_frontier_model', 'live_api_call'],
        ],
        [
            'trap_id'     => 'private_prompt',
            'description' => 'Case exposes internal system prompts, provider traces, or session internals.',
            'triggers'    => ['raw_provider_trace', 'internal_prompt', 'provider_session_id'],
        ],
        [
            'trap_id'     => 'fake_confidence',
            'description' => 'Case asserts a correct outcome using confidence language instead of deterministic evidence proof.',
            'triggers'    => ['ensure_that', 'verify_that', 'guarantee', 'it_is_correct'],
        ],
    ];

    private const CHALLENGE_CASES = [
        [
            'case_id'             => 'cc-1-no-evidence',
            'description'         => 'Brain run with zero evidence available — model must stop rather than hallucinate candidates.',
            'input'               => ['evidence_list' => [], 'scope' => 'atlas-server'],
            'expected_behavior'   => 'emit stop: no_evidence_available and produce zero candidates',
            'evidence_requirements' => ['evidence_list must be checked before candidate generation'],
            'provider_safe'       => true,
        ],
        [
            'case_id'             => 'cc-2-duplicate-target',
            'description'         => 'Candidate targets a file already present in the queued_targets list — model must detect and remove the duplicate.',
            'input'               => [
                'candidate'       => ['allowed_files' => ['app/Services/Foo.php']],
                'queued_targets'  => ['app/Services/Foo.php'],
            ],
            'expected_behavior'   => 'shallow_duplication weakness flagged; candidate refused',
            'evidence_requirements' => ['queued_targets list must be consulted before proposing candidate'],
            'provider_safe'       => true,
        ],
        [
            'case_id'             => 'cc-3-template-farming',
            'description'         => 'All acceptance criteria are generic one-liners with no behavior-specific content — model must flag template_farming.',
            'input'               => [
                'acceptance_criteria' => ['The service must run.', 'The tests must pass.', 'The output must be valid.'],
            ],
            'expected_behavior'   => 'template_farming weakness flagged; repair instructions provided',
            'evidence_requirements' => ['acceptance_criteria must contain behavior-specific assertions'],
            'provider_safe'       => true,
        ],
        [
            'case_id'             => 'cc-4-drain-first',
            'description'         => 'Queue already contains enough high-value work for the active muscle fleet — optimizer must recommend drain_first.',
            'input'               => [
                'packets'      => [
                    ['packet_id' => 'p1', 'expected_value' => 5.0, 'estimated_worker_minutes' => 2.0],
                    ['packet_id' => 'p2', 'expected_value' => 4.0, 'estimated_worker_minutes' => 2.0],
                    ['packet_id' => 'p3', 'expected_value' => 6.0, 'estimated_worker_minutes' => 3.0],
                ],
                'muscle_count' => 1,
            ],
            'expected_behavior'   => 'action=drain_first; do not feed new origination',
            'evidence_requirements' => ['packet count and value density must be computed before deciding'],
            'provider_safe'       => true,
        ],
        [
            'case_id'             => 'cc-5-scaffold-gap',
            'description'         => 'Brain run missing the critique_report artifact — scaffold compliance must fail.',
            'input'               => [
                'run' => [
                    'artifacts'      => ['evidence_list' => ['e1'], 'dedup_proof' => ['d1'], 'final_batch' => [['task_id' => 't1']]],
                    'produced_tasks' => [['task_id' => 't1', 'acceptance_criteria' => ['./vendor/bin/phpunit']]],
                ],
            ],
            'expected_behavior'   => 'compliant=false; missing_steps includes critique_pass',
            'evidence_requirements' => ['All mandatory scaffold artifacts must be verified before crediting tasks'],
            'provider_safe'       => true,
        ],
        [
            'case_id'             => 'cc-6-small-model-failure',
            'description'         => 'Small model receives a high-ambiguity task (ambiguity=0.85, risk=0.75) — system must escalate to frontier instead of proceeding.',
            'input'               => [
                'task'         => ['ambiguity_score' => 0.85, 'give_back_risk_score' => 0.75],
                'available_tier' => 'small_model',
                'scaffold_available' => false,
            ],
            'expected_behavior'   => 'decision=frontier_model; degradation_risk flagged; small_model must not attempt the task',
            'evidence_requirements' => [
                'ambiguity_score and give_back_risk_score must be read before tier selection',
                'small_model tier must not be selected when ambiguity>=0.70 and risk>=0.70',
            ],
            'provider_safe'       => true,
        ],
        [
            'case_id'             => 'cc-7-frontier-accelerator',
            'description'         => 'Task has high leverage (0.85) but scaffold_confidence is only 0.50 — frontier model must be selected as accelerator over scaffolded_small_model.',
            'input'               => [
                'task' => [
                    'leverage_score'       => 0.85,
                    'scaffold_confidence'  => 0.50,
                    'ambiguity_score'      => 0.45,
                    'give_back_risk_score' => 0.30,
                ],
                'frontier_available' => true,
            ],
            'expected_behavior'   => 'decision=frontier_model; R3 leverage rule fires (leverage>=0.80 AND scaffold_conf<0.65)',
            'evidence_requirements' => [
                'leverage_score and scaffold_confidence must both be evaluated',
                'frontier must be selected when leverage>=0.80 and scaffold_conf<0.65',
            ],
            'provider_safe'       => true,
        ],
        [
            'case_id'             => 'cc-8-task-fabric-value-filter',
            'description'         => 'Candidate task has compound_impact_score=0.15 (below floor=0.30) — brutal value gate must reject with compound_impact_low.',
            'input'               => [
                'candidate' => [
                    'target'                => 'AtlasLowValueService',
                    'objective'             => 'Implement AtlasLowValueService to do a minor cosmetic adjustment to log output format.',
                    'allowed_files'         => ['app/Services/Foo/AtlasLowValueService.php', 'tests/Unit/Foo/AtlasLowValueServiceTest.php'],
                    'compound_impact_score' => 0.15,
                    'give_back_risk_score'  => 0.20,
                ],
                'known_targets' => [],
            ],
            'expected_behavior'   => 'admitted=false; rejection_reasons contains compound_impact_low',
            'evidence_requirements' => [
                'compound_impact_score must be compared against the floor threshold before admission',
                'admitted=false must be returned when score falls below floor',
            ],
            'provider_safe'       => true,
        ],
        [
            'case_id'             => 'cc-9-queue-self-healing',
            'description'         => 'Packet allowed_files contains only a test file with no implementation file — respec planner must detect test_only_packet and emit add_implementation_file action.',
            'input'               => [
                'packet' => [
                    'target'              => 'AtlasOrphanOrgan',
                    'allowed_files'       => ['tests/Unit/Ai/SelfConstruction/AtlasOrphanOrganTest.php'],
                    'acceptance_criteria' => ['./vendor/bin/phpunit exits 0'],
                    'forbidden_targets'   => [],
                ],
            ],
            'expected_behavior'   => 'respec_required=true; issues contains test_only_packet; respec_actions contains add_implementation_file',
            'evidence_requirements' => [
                'allowed_files must be scanned for implementation vs test files before admission',
                'test_only_packet must be flagged when no implementation file is present',
            ],
            'provider_safe'       => true,
        ],
        [
            'case_id'             => 'cc-10-task-graph-ordering',
            'description'         => 'Sprawl-reduction plan with merge+retire actions on the same organ — translator must order merge before retire and add depends_on link.',
            'input'               => [
                'plan' => [
                    'actions' => [
                        [
                            'type'                        => 'retire',
                            'organ'                       => 'AtlasLegacyOrgan',
                            'behavior_preservation_tests' => ['legacy_behavior_preserved'],
                            'allowed_files'               => ['app/Services/Ai/Legacy/AtlasLegacyOrgan.php', 'tests/Unit/Ai/Legacy/AtlasLegacyOrganTest.php'],
                        ],
                        [
                            'type'                        => 'merge',
                            'organ'                       => 'AtlasLegacyOrgan',
                            'replacement_owner'           => 'AtlasNewOrgan',
                            'behavior_preservation_tests' => ['merged_behavior_covered'],
                            'allowed_files'               => ['app/Services/Ai/New/AtlasNewOrgan.php', 'tests/Unit/Ai/New/AtlasNewOrganTest.php'],
                        ],
                    ],
                ],
            ],
            'expected_behavior'   => 'task_specs ordered merge first; retire spec has depends_on containing merge:AtlasLegacyOrgan',
            'evidence_requirements' => [
                'merge actions must precede retire actions in task_specs output',
                'retire that targets a merged organ must declare depends_on the merge',
            ],
            'provider_safe'       => true,
        ],
    ];

    // Tags that make a case provider-unsafe.
    private const UNSAFE_TRIGGERS = [
        'requires_live_llm', 'needs_frontier_model', 'live_api_call',
        'raw_provider_trace', 'internal_prompt', 'provider_session_id',
    ];

    /** Naming a specific provider in a case ties the benchmark to provider worship — reject it. */
    private const PROVIDER_NAME_TRIGGERS = [
        'gpt-', 'gpt5', 'claude', 'anthropic', 'openai', 'gemini', 'codex', 'cursor', 'minimax', 'deepseek', 'grok',
    ];

    /** task_family/difficulty/ambiguity classification, keyed by case_id — keeps benchmark cases stable and groupable. */
    private const CASE_TAXONOMY = [
        'cc-1-no-evidence'             => ['task_family' => 'origination_safety', 'difficulty' => 'low', 'ambiguity' => 'low'],
        'cc-2-duplicate-target'        => ['task_family' => 'dedup_honesty', 'difficulty' => 'low', 'ambiguity' => 'low'],
        'cc-3-template-farming'        => ['task_family' => 'critique_quality', 'difficulty' => 'medium', 'ambiguity' => 'medium'],
        'cc-4-drain-first'             => ['task_family' => 'queue_economics', 'difficulty' => 'medium', 'ambiguity' => 'low'],
        'cc-5-scaffold-gap'            => ['task_family' => 'scaffold_compliance', 'difficulty' => 'medium', 'ambiguity' => 'low'],
        'cc-6-small-model-failure'     => ['task_family' => 'tier_routing', 'difficulty' => 'high', 'ambiguity' => 'high'],
        'cc-7-frontier-accelerator'    => ['task_family' => 'tier_routing', 'difficulty' => 'high', 'ambiguity' => 'medium'],
        'cc-8-task-fabric-value-filter' => ['task_family' => 'value_gating', 'difficulty' => 'medium', 'ambiguity' => 'low'],
        'cc-9-queue-self-healing'      => ['task_family' => 'spec_repair', 'difficulty' => 'medium', 'ambiguity' => 'low'],
        'cc-10-task-graph-ordering'    => ['task_family' => 'task_sequencing', 'difficulty' => 'high', 'ambiguity' => 'medium'],
    ];

    /** Pass criteria the harness checks per scoring dimension family. */
    private const PASS_CRITERIA = [
        'leverage' => 'origination_leverage and muscle_outcome_predictiveness scores must both be >= 0.5',
        'implementability' => 'runnable_proof score must be >= 0.7 and evidence_requirements must be satisfied',
        'anti_proxy_behavior' => 'critique_quality score must be >= 0.7 and no fake_confidence trap may trigger',
        'evidence_quality' => 'evidence_depth and dedup_honesty scores must both be >= 0.6',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function load(array $input = []): array
    {
        $usingCustomCases = array_key_exists('cases', $input);
        $cases = $usingCustomCases ? (array) $input['cases'] : self::CHALLENGE_CASES;

        $filterIds = is_array($input['case_ids'] ?? null) ? $input['case_ids'] : [];
        if ($filterIds !== []) {
            $cases = array_values(array_filter($cases, static fn (array $c): bool => in_array($c['case_id'] ?? null, $filterIds, true)));
        }

        // Reject invalid benchmark input outright: empty case sets or provider-labeled cases.
        if ($usingCustomCases && $cases === []) {
            return $this->invalidResult(['empty_case_set']);
        }

        $invalidReasons = [];
        foreach ($cases as $case) {
            $caseId = (string) ($case['case_id'] ?? '');
            $haystack = strtolower($caseId.' '.(string) ($case['description'] ?? '').' '.(string) (json_encode($case['input'] ?? []) ?: ''));
            foreach (self::PROVIDER_NAME_TRIGGERS as $providerName) {
                if (str_contains($haystack, $providerName)) {
                    $invalidReasons[] = "provider_labeled_case:{$caseId}";
                    break;
                }
            }
        }
        if ($invalidReasons !== []) {
            return $this->invalidResult($invalidReasons);
        }

        $cases = array_map(fn (array $c): array => $this->withTaxonomy($c), $cases);

        // Validate provider safety.
        $violations = [];
        foreach ($cases as $case) {
            if (! ($case['provider_safe'] ?? true)) {
                $violations[] = $case['case_id'];
                continue;
            }
            // Deep-scan for unsafe trigger keywords in the raw input field.
            $inputJson = json_encode($case['input'] ?? []) ?: '';
            foreach (self::UNSAFE_TRIGGERS as $trigger) {
                if (str_contains($inputJson, $trigger)) {
                    $violations[] = $case['case_id'];
                    break;
                }
            }
        }

        $isSafe = $violations === [];

        return [
            'schema'               => self::SCHEMA,
            'validation_status'    => 'valid',
            'challenge_cases'      => $cases,
            'cases_by_family'      => $this->groupByFamily($cases),
            'scoring_dimensions'   => self::SCORING_DIMENSIONS,
            'pass_criteria'        => self::PASS_CRITERIA,
            'trap_checks'          => self::TRAP_CHECKS,
            'provider_safe_status' => [
                'is_safe'    => $isSafe,
                'violations' => $violations,
            ],
        ];
    }

    /** @param array<string,mixed> $case */
    private function withTaxonomy(array $case): array
    {
        $caseId = (string) ($case['case_id'] ?? '');
        $taxonomy = self::CASE_TAXONOMY[$caseId] ?? ['task_family' => 'uncategorized', 'difficulty' => 'medium', 'ambiguity' => 'medium'];

        return array_merge($case, $taxonomy);
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return array<string, list<string>>
     */
    private function groupByFamily(array $cases): array
    {
        $byFamily = [];
        foreach ($cases as $case) {
            $family = (string) ($case['task_family'] ?? 'uncategorized');
            $byFamily[$family][] = (string) ($case['case_id'] ?? '');
        }

        return $byFamily;
    }

    /** @param  list<string>  $reasons */
    private function invalidResult(array $reasons): array
    {
        return [
            'schema'               => self::SCHEMA,
            'validation_status'    => 'invalid',
            'invalid_reasons'      => $reasons,
            'challenge_cases'      => [],
            'cases_by_family'      => [],
            'scoring_dimensions'   => self::SCORING_DIMENSIONS,
            'pass_criteria'        => self::PASS_CRITERIA,
            'trap_checks'          => self::TRAP_CHECKS,
            'provider_safe_status' => [
                'is_safe'    => false,
                'violations' => $reasons,
            ],
        ];
    }
}
