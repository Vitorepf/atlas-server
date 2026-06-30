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
            'weight'       => 0.25,
        ],
        [
            'dimension_id' => 'dedup_honesty',
            'description'  => 'Did the model accurately cross-reference queued/done targets and remove duplicates?',
            'weight'       => 0.20,
        ],
        [
            'dimension_id' => 'critique_quality',
            'description'  => 'Did the anti-Goodhart critique eliminate proxy, cleanup, or fake-value candidates?',
            'weight'       => 0.25,
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
    ];

    // Tags that make a case provider-unsafe.
    private const UNSAFE_TRIGGERS = [
        'requires_live_llm', 'needs_frontier_model', 'live_api_call',
        'raw_provider_trace', 'internal_prompt', 'provider_session_id',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function load(array $input = []): array
    {
        $filterIds = is_array($input['case_ids'] ?? null) ? $input['case_ids'] : [];

        $cases = self::CHALLENGE_CASES;
        if ($filterIds !== []) {
            $cases = array_values(array_filter($cases, static fn (array $c): bool => in_array($c['case_id'], $filterIds, true)));
        }

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
            'challenge_cases'      => $cases,
            'scoring_dimensions'   => self::SCORING_DIMENSIONS,
            'trap_checks'          => self::TRAP_CHECKS,
            'provider_safe_status' => [
                'is_safe'    => $isSafe,
                'violations' => $violations,
            ],
        ];
    }
}
