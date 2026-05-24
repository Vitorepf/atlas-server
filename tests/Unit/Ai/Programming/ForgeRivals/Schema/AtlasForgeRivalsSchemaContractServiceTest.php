<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals\Schema;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use App\Services\Ai\Programming\ForgeRivals\Schema\AtlasForgeRivalsSchemaContractService;
use PHPUnit\Framework\TestCase;

/**
 * Atlas Forge Rivals · Schema Contract — unit tests.
 *
 * Cobre os 5 schemas canon (corpus_case, run_result, evidence_pack,
 * adjudication, report) + a canonical difficulty block L1..L5 + a fórmula
 * multiplicador difficulty_score/3.0 + integração com o CorpusService real.
 *
 * Nada chama provider; nada destrava external_rivals.
 */
final class AtlasForgeRivalsSchemaContractServiceTest extends TestCase
{
    private AtlasForgeRivalsSchemaContractService $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contract = new AtlasForgeRivalsSchemaContractService;
    }

    /* ---------- difficulty multiplier formula ---------- */

    public function test_difficulty_multiplier_uses_score_divided_by_three_with_l3_neutral(): void
    {
        $this->assertEqualsWithDelta(0.3333, $this->contract->difficultyMultiplier(1.0), 0.001);
        $this->assertEqualsWithDelta(0.6667, $this->contract->difficultyMultiplier(2.0), 0.001);
        $this->assertEqualsWithDelta(1.0, $this->contract->difficultyMultiplier(3.0), 0.001, 'L3 (score=3.0) é neutro: multiplier == 1.0');
        $this->assertEqualsWithDelta(1.3333, $this->contract->difficultyMultiplier(4.0), 0.001);
        $this->assertEqualsWithDelta(1.6667, $this->contract->difficultyMultiplier(5.0), 0.001);
    }

    public function test_difficulty_weighted_score_is_raw_times_multiplier(): void
    {
        $this->assertEqualsWithDelta(80.0, $this->contract->difficultyWeightedScore(80.0, 3.0), 0.01);
        $this->assertEqualsWithDelta(133.336, $this->contract->difficultyWeightedScore(80.0, 5.0), 0.01);
        $this->assertEqualsWithDelta(26.664, $this->contract->difficultyWeightedScore(80.0, 1.0), 0.01);
    }

    /* ---------- schema 1: corpus_case ---------- */

    public function test_corpus_case_schema_validates_every_real_release_case(): void
    {
        $corpus = new AtlasForgeRivalsProviderArenaCorpusService;
        foreach ($corpus->cases() as $case) {
            $violations = $this->contract->validateCorpusCase($case);
            $this->assertSame(
                [],
                $violations,
                "Caso real {$case['case_id']} viola o schema corpus_case: ".implode(', ', $violations),
            );
        }
    }

    public function test_corpus_case_schema_fails_closed_when_difficulty_block_is_missing(): void
    {
        $corpus = new AtlasForgeRivalsProviderArenaCorpusService;
        $case = $corpus->case('backend-pagination-off-by-one');
        unset($case['difficulty_level']);
        unset($case['difficulty_score']);
        $violations = $this->contract->validateCorpusCase($case);
        $this->assertNotEmpty($violations);
        $this->assertTrue((bool) array_filter(
            $violations,
            static fn (string $v): bool => str_contains($v, 'difficulty_level_not_in_canon')
                || str_contains($v, 'difficulty_score_not_numeric')
                || str_contains($v, 'missing_field:difficulty_level'),
        ));
    }

    public function test_corpus_case_schema_rejects_score_that_does_not_match_level(): void
    {
        $corpus = new AtlasForgeRivalsProviderArenaCorpusService;
        $case = $corpus->case('backend-pagination-off-by-one');
        $case['difficulty_score'] = 5.0; // case is L1, score must be 1.0
        $violations = $this->contract->validateCorpusCase($case);
        $this->assertNotEmpty(array_filter(
            $violations,
            static fn (string $v): bool => str_contains($v, 'difficulty_score_does_not_match_level'),
        ));
    }

    public function test_corpus_case_schema_rejects_planning_execution_weights_that_do_not_sum_to_one(): void
    {
        $corpus = new AtlasForgeRivalsProviderArenaCorpusService;
        $case = $corpus->case('backend-pagination-off-by-one');
        $case['planning_weight'] = 0.3;
        $case['execution_weight'] = 0.3; // sum=0.6
        $violations = $this->contract->validateCorpusCase($case);
        $this->assertNotEmpty(array_filter(
            $violations,
            static fn (string $v): bool => str_contains($v, 'planning_execution_weights_do_not_sum_to_one'),
        ));
    }

    public function test_corpus_case_schema_rejects_ambiguity_level_outside_canon(): void
    {
        $corpus = new AtlasForgeRivalsProviderArenaCorpusService;
        $case = $corpus->case('backend-pagination-off-by-one');
        $case['ambiguity_level'] = 'extreme';
        $violations = $this->contract->validateCorpusCase($case);
        $this->assertContains('corpus_case.ambiguity_level_not_in_canon:extreme', $violations);
    }

    public function test_corpus_case_schema_rejects_risk_level_outside_canon(): void
    {
        $corpus = new AtlasForgeRivalsProviderArenaCorpusService;
        $case = $corpus->case('backend-pagination-off-by-one');
        $case['risk_level'] = 'catastrophic';
        $violations = $this->contract->validateCorpusCase($case);
        $this->assertContains('corpus_case.risk_level_not_in_canon:catastrophic', $violations);
    }

    public function test_corpus_case_schema_rejects_difficulty_reason_too_short(): void
    {
        $corpus = new AtlasForgeRivalsProviderArenaCorpusService;
        $case = $corpus->case('backend-pagination-off-by-one');
        $case['difficulty_reason'] = 'short';
        $violations = $this->contract->validateCorpusCase($case);
        $this->assertContains('corpus_case.difficulty_reason_too_short_or_missing', $violations);
    }

    public function test_corpus_case_schema_rejects_claim_level_other_than_case_result_only(): void
    {
        $corpus = new AtlasForgeRivalsProviderArenaCorpusService;
        $case = $corpus->case('backend-pagination-off-by-one');
        $case['claim_level'] = 'global_claim';
        $violations = $this->contract->validateCorpusCase($case);
        $this->assertContains('corpus_case.claim_level_must_be_case_result_only:global_claim', $violations);
    }

    public function test_corpus_case_schema_requires_human_prompt_context_profile_and_measurement_tags(): void
    {
        $corpus = new AtlasForgeRivalsProviderArenaCorpusService;
        $case = $corpus->case('backend-pagination-off-by-one');
        unset($case['human_prompt']);
        $case['context_profile'] = ['schema_version' => 'wrong'];
        $case['measurement_tags'] = ['long_context'];
        $case['human_prompt_probe'] = [
            'schema_version' => 'wrong',
            'requires_sections' => ['facts_observed'],
        ];

        $violations = $this->contract->validateCorpusCase($case);

        $this->assertContains('corpus_case.missing_field:human_prompt', $violations);
        $this->assertContains('corpus_case.context_profile_schema_version_invalid', $violations);
        $this->assertContains('corpus_case.context_profile_requires_assumption_log_missing', $violations);
        $this->assertContains('corpus_case.context_profile_complexity_profile_missing', $violations);
        $this->assertContains('corpus_case.measurement_tags_missing_human_prompt', $violations);
        $this->assertContains('corpus_case.human_prompt_probe_schema_version_invalid', $violations);
        $this->assertContains('corpus_case.human_prompt_probe_missing_section:replay_matrix', $violations);
        $this->assertContains('corpus_case.human_prompt_probe_missing_section:honest_blockers', $violations);
        $this->assertContains('corpus_case.human_prompt_probe_complexity_profile_missing', $violations);
    }

    /* ---------- schema 2: run_result ---------- */

    public function test_run_result_schema_accepts_canonical_payload(): void
    {
        $payload = [
            'action' => 'run-battery',
            'run_battery_schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_RUN_RESULT,
            'status' => 'ok',
            'mode' => 'local_fake',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
        $this->assertSame([], $this->contract->validateRunResult($payload));
    }

    public function test_run_result_schema_rejects_missing_field(): void
    {
        $payload = [
            'action' => 'run-battery',
            'run_battery_schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_RUN_RESULT,
            'mode' => 'local_fake',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
        $violations = $this->contract->validateRunResult($payload);
        $this->assertContains('run_result.missing_field:status', $violations);
    }

    public function test_run_result_schema_blocks_local_fake_that_claims_provider_call(): void
    {
        $payload = [
            'action' => 'run-battery',
            'run_battery_schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_RUN_RESULT,
            'status' => 'ok',
            'mode' => 'local_fake',
            'external_provider_call' => true,
            'provider_tokens_spent' => false,
        ];
        $violations = $this->contract->validateRunResult($payload);
        $this->assertContains('run_result.local_fake_must_not_call_provider', $violations);
    }

    /* ---------- schema 3: evidence_pack ---------- */

    public function test_evidence_pack_schema_accepts_canonical_payload(): void
    {
        $payload = [
            'schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_EVIDENCE_PACK,
            'run_id' => 'rivals-test-001',
            'manifest_path' => '/tmp/manifest.json',
            'atlas_paths' => ['receipt' => '/tmp/atlas-receipt.json'],
            'rival_paths' => ['receipt' => '/tmp/rival-receipt.json'],
        ];
        $this->assertSame([], $this->contract->validateEvidencePack($payload));
    }

    public function test_evidence_pack_schema_accepts_v1_legacy_version(): void
    {
        $payload = [
            'schema_version' => 'atlas.forge.rivals.evidence_pack.v1',
            'run_id' => 'rivals-test-001',
            'manifest_path' => '/tmp/manifest.json',
            'atlas_paths' => [],
            'rival_paths' => [],
        ];
        $violations = $this->contract->validateEvidencePack($payload);
        $this->assertSame([], $violations);
    }

    public function test_evidence_pack_schema_rejects_unknown_version(): void
    {
        $payload = [
            'schema_version' => 'atlas.forge.rivals.evidence_pack.vX',
            'run_id' => 'rivals-test-001',
            'manifest_path' => '/tmp/manifest.json',
            'atlas_paths' => [],
            'rival_paths' => [],
        ];
        $violations = $this->contract->validateEvidencePack($payload);
        $this->assertContains('evidence_pack.schema_version_not_recognized:atlas.forge.rivals.evidence_pack.vX', $violations);
    }

    /* ---------- schema 4: adjudication ---------- */

    public function test_adjudication_schema_accepts_canonical_payload(): void
    {
        $payload = [
            'schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_ADJUDICATION,
            'run_id' => 'rivals-test-001',
            'winner' => 'atlas',
            'hard_failures' => [],
            'atlas_score' => 80.0,
            'rival_score' => 60.0,
        ];
        $this->assertSame([], $this->contract->validateAdjudication($payload));
    }

    public function test_adjudication_schema_validates_difficulty_block_when_present(): void
    {
        $payload = [
            'schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_ADJUDICATION,
            'run_id' => 'rivals-test-001',
            'winner' => 'atlas',
            'hard_failures' => [],
            'atlas_score' => 80.0,
            'rival_score' => 60.0,
            'difficulty_block' => [
                'difficulty_level' => 'L9', // invalid level
                'difficulty_score' => 9.0,
                'difficulty_reason' => 'reason long enough to pass',
                'planning_weight' => 0.5,
                'execution_weight' => 0.5,
                'ambiguity_level' => 'low',
                'risk_level' => 'low',
            ],
        ];
        $violations = $this->contract->validateAdjudication($payload);
        $this->assertNotEmpty($violations);
        $this->assertTrue((bool) array_filter(
            $violations,
            static fn (string $v): bool => str_contains($v, 'adjudication.difficulty_block.difficulty_level_not_in_canon'),
        ));
    }

    /* ---------- schema 5: report ---------- */

    public function test_report_schema_accepts_canonical_payload_with_raw_score_and_multiplier(): void
    {
        $payload = [
            'schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_REPORT,
            'run_id' => 'rivals-test-001',
            'winner' => null,
            'atlas_score' => 80.0,
            'rival_score' => 60.0,
            'case_results' => [
                [
                    'case_id' => 'backend-pagination-off-by-one',
                    'task_category' => 'realistic_bugfix',
                    'atlas_score' => 80.0,
                    'rival_score' => 60.0,
                    'raw_score' => ['atlas' => 80.0, 'rival' => 60.0],
                    'difficulty_multiplier' => 0.3333,
                    'difficulty_weighted_score' => ['atlas' => 26.66, 'rival' => 20.0],
                ],
            ],
            'claim_status' => [
                'external_rivals_status' => 'blocked_requires_operator_approval',
                'can_feed_ledger' => false,
                'can_feed_decide_signal' => false,
                'ledger_blockers' => ['meta_provider_stress_floor_not_met'],
            ],
            'provider_performance_signal' => $this->providerPerformanceSignal([
                'ledger_blockers' => ['meta_provider_stress_floor_not_met'],
                'do_not_use_when' => [
                    ['condition' => 'meta_provider_stress_floor_not_met'],
                ],
            ]),
        ];
        $this->assertSame([], $this->contract->validateReport($payload));
    }

    public function test_report_schema_rejects_case_results_missing_raw_score(): void
    {
        $payload = [
            'schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_REPORT,
            'run_id' => 'rivals-test-001',
            'winner' => null,
            'atlas_score' => null,
            'rival_score' => null,
            'case_results' => [
                [
                    'case_id' => 'backend-pagination-off-by-one',
                    'task_category' => 'realistic_bugfix',
                    'atlas_score' => null,
                    'rival_score' => null,
                    // raw_score absent
                    'difficulty_multiplier' => 0.3333,
                    'difficulty_weighted_score' => ['atlas' => null, 'rival' => null],
                ],
            ],
            'claim_status' => [
                'can_feed_ledger' => false,
                'can_feed_decide_signal' => false,
                'ledger_blockers' => [],
            ],
            'provider_performance_signal' => $this->providerPerformanceSignal(),
        ];
        $violations = $this->contract->validateReport($payload);
        $this->assertContains('report.case_results[0].missing_field:raw_score', $violations);
    }

    public function test_report_schema_requires_advisory_provider_signal_and_ledger_blockers(): void
    {
        $payload = [
            'schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_REPORT,
            'run_id' => 'rivals-test-001',
            'winner' => null,
            'atlas_score' => null,
            'rival_score' => null,
            'case_results' => [],
            'claim_status' => [
                'can_feed_ledger' => false,
                'can_feed_decide_signal' => false,
                // ledger_blockers absent
            ],
            'provider_performance_signal' => array_replace(
                $this->providerPerformanceSignal(),
                [
                    'advisory_only' => false,
                    'should_update_provider_topology' => true,
                    'never_changes_atlas_decide_topology' => false,
                    'owner_of_model_routing' => 'rivals',
                    'routing_effect' => 'update',
                ],
            ),
        ];
        unset($payload['provider_performance_signal']['ledger_blockers']);

        $violations = $this->contract->validateReport($payload);

        $this->assertContains('report.claim_status.missing_field:ledger_blockers', $violations);
        $this->assertContains('report.provider_performance_signal.missing_field:ledger_blockers', $violations);
        $this->assertContains('report.provider_performance_signal.advisory_only_must_be_true', $violations);
        $this->assertContains('report.provider_performance_signal.should_update_provider_topology_must_be_false', $violations);
        $this->assertContains('report.provider_performance_signal.never_changes_atlas_decide_topology_must_be_true', $violations);
        $this->assertContains('report.provider_performance_signal.owner_of_model_routing_must_be_atlas_decide', $violations);
        $this->assertContains('report.provider_performance_signal.routing_effect_must_be_none', $violations);
    }

    public function test_report_schema_requires_human_prompt_and_complexity_coverage_blocks(): void
    {
        $payload = [
            'schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_REPORT,
            'run_id' => 'rivals-test-001',
            'winner' => null,
            'atlas_score' => null,
            'rival_score' => null,
            'case_results' => [],
            'claim_status' => [
                'can_feed_ledger' => false,
                'can_feed_decide_signal' => false,
                'ledger_blockers' => [],
            ],
            'provider_performance_signal' => $this->providerPerformanceSignal(),
        ];
        unset(
            $payload['provider_performance_signal']['human_prompt_contract_coverage'],
            $payload['provider_performance_signal']['complexity_profile_coverage'],
        );

        $violations = $this->contract->validateReport($payload);

        $this->assertContains(
            'report.provider_performance_signal.missing_field:human_prompt_contract_coverage',
            $violations,
        );
        $this->assertContains(
            'report.provider_performance_signal.human_prompt_contract_coverage.not_an_object',
            $violations,
        );
        $this->assertContains(
            'report.provider_performance_signal.missing_field:complexity_profile_coverage',
            $violations,
        );
        $this->assertContains(
            'report.provider_performance_signal.complexity_profile_coverage.not_an_object',
            $violations,
        );
    }

    public function test_report_schema_rejects_non_advisory_nested_measurement_coverage(): void
    {
        $payload = [
            'schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_REPORT,
            'run_id' => 'rivals-test-001',
            'winner' => null,
            'atlas_score' => null,
            'rival_score' => null,
            'case_results' => [],
            'claim_status' => [
                'can_feed_ledger' => false,
                'can_feed_decide_signal' => false,
                'ledger_blockers' => [],
            ],
            'provider_performance_signal' => $this->providerPerformanceSignal([
                'human_prompt_contract_coverage' => array_replace(
                    $this->humanPromptCoverage(),
                    ['advisory_only' => false, 'routing_effect' => 'update'],
                ),
                'complexity_profile_coverage' => array_replace(
                    $this->complexityCoverage(),
                    ['schema_version' => 'wrong', 'advisory_only' => false, 'routing_effect' => 'update'],
                ),
            ]),
        ];

        $violations = $this->contract->validateReport($payload);

        $this->assertContains(
            'report.provider_performance_signal.human_prompt_contract_coverage.advisory_only_must_be_true',
            $violations,
        );
        $this->assertContains(
            'report.provider_performance_signal.human_prompt_contract_coverage.routing_effect_must_be_none',
            $violations,
        );
        $this->assertContains(
            'report.provider_performance_signal.complexity_profile_coverage.schema_version_invalid',
            $violations,
        );
        $this->assertContains(
            'report.provider_performance_signal.complexity_profile_coverage.advisory_only_must_be_true',
            $violations,
        );
        $this->assertContains(
            'report.provider_performance_signal.complexity_profile_coverage.routing_effect_must_be_none',
            $violations,
        );
    }

    public function test_report_schema_rejects_ledger_feed_when_measurement_has_blockers_or_do_not_use_conditions(): void
    {
        $payload = [
            'schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_REPORT,
            'run_id' => 'rivals-test-001',
            'winner' => null,
            'atlas_score' => null,
            'rival_score' => null,
            'case_results' => [],
            'claim_status' => [
                'can_feed_ledger' => true,
                'can_feed_decide_signal' => false,
                'ledger_blockers' => ['meta_provider_stress_floor_not_met'],
            ],
            'provider_performance_signal' => $this->providerPerformanceSignal([
                'can_feed_ledger' => true,
                'ledger_blockers' => ['long_context_not_measured'],
                'do_not_use_when' => [
                    ['condition' => 'long_context_not_measured'],
                ],
            ]),
        ];

        $violations = $this->contract->validateReport($payload);

        $this->assertContains('report.claim_status.can_feed_ledger_true_with_ledger_blockers', $violations);
        $this->assertContains('report.provider_performance_signal.can_feed_ledger_true_with_ledger_blockers', $violations);
        $this->assertContains('report.provider_performance_signal.can_feed_ledger_true_with_do_not_use_when', $violations);
    }

    public function test_report_schema_rejects_claim_status_ledger_feed_when_provider_signal_blocks_it(): void
    {
        $payload = [
            'schema_version' => AtlasForgeRivalsSchemaContractService::SCHEMA_REPORT,
            'run_id' => 'rivals-test-001',
            'winner' => null,
            'atlas_score' => null,
            'rival_score' => null,
            'case_results' => [],
            'claim_status' => [
                'can_feed_ledger' => true,
                'can_feed_decide_signal' => false,
                'ledger_blockers' => [],
            ],
            'provider_performance_signal' => $this->providerPerformanceSignal([
                'ledger_blockers' => ['complexity_profile_coverage_incomplete'],
                'do_not_use_when' => [
                    ['condition' => 'complexity_profile_coverage_incomplete'],
                ],
            ]),
        ];

        $violations = $this->contract->validateReport($payload);

        $this->assertContains(
            'report.claim_status.can_feed_ledger_true_while_provider_signal_blocks_ledger',
            $violations,
        );
    }

    public function test_report_schema_rejects_wrong_version(): void
    {
        $payload = [
            'schema_version' => 'atlas.forge.rivals.report.vX',
            'run_id' => 'r',
            'winner' => null,
            'atlas_score' => null,
            'rival_score' => null,
            'case_results' => [],
            'claim_status' => [
                'can_feed_ledger' => false,
                'can_feed_decide_signal' => false,
                'ledger_blockers' => [],
            ],
            'provider_performance_signal' => $this->providerPerformanceSignal(),
        ];
        $violations = $this->contract->validateReport($payload);
        $this->assertContains('report.schema_version_mismatch:atlas.forge.rivals.report.vX', $violations);
    }

    /* ---------- multi-schema helpers ---------- */

    public function test_validate_routes_to_correct_schema_validator(): void
    {
        $violations = $this->contract->validate(
            AtlasForgeRivalsSchemaContractService::SCHEMA_RUN_RESULT,
            ['mode' => 'fair'],
        );
        $this->assertNotEmpty($violations);
        // unknown schema returns single violation
        $unknown = $this->contract->validate('atlas.forge.rivals.not_a_schema.v1', []);
        $this->assertSame(['schema_unknown:atlas.forge.rivals.not_a_schema.v1'], $unknown);
    }

    public function test_difficulty_block_of_returns_normalized_block_with_multiplier(): void
    {
        $corpus = new AtlasForgeRivalsProviderArenaCorpusService;
        $case = $corpus->case('architecture-schema-versioned-receipt');
        $extract = $this->contract->difficultyBlockOf($case);
        $this->assertTrue($extract['ok']);
        $this->assertSame('L5', $extract['block']['difficulty_level']);
        $this->assertEqualsWithDelta(5.0, $extract['block']['difficulty_score'], 0.001);
        $this->assertEqualsWithDelta(1.6667, $extract['block']['difficulty_multiplier'], 0.001);
    }

    public function test_difficulty_block_of_fails_closed_when_absent_and_required(): void
    {
        $extract = $this->contract->difficultyBlockOf([], required: true);
        $this->assertFalse($extract['ok']);
        $this->assertContains('difficulty_block_missing', $extract['violations']);
    }

    public function test_snapshot_lists_all_five_schemas_and_difficulty_canon(): void
    {
        $snap = $this->contract->snapshot();
        $this->assertSame('atlas.forge.rivals.schema_contract.v1', $snap['contract_version']);
        $this->assertCount(5, $snap['schemas']);
        $this->assertSame(['L1', 'L2', 'L3', 'L4', 'L5'], $snap['difficulty_canon']['levels']);
        $this->assertFalse($snap['external_provider_call']);
        $this->assertTrue($snap['separated_from_external_rivals_certification']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function providerPerformanceSignal(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => 'atlas.forge.rivals.provider_performance_signal.v1',
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'can_feed_ledger' => false,
            'ledger_blockers' => [],
            'do_not_use_when' => [],
            'human_prompt_contract_coverage' => $this->humanPromptCoverage(),
            'complexity_profile_coverage' => $this->complexityCoverage(),
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function humanPromptCoverage(): array
    {
        return [
            'schema_version' => 'atlas.forge.rivals.human_prompt_contract_coverage.v1',
            'advisory_only' => true,
            'routing_effect' => 'none',
            'case_count' => 1,
            'cases_with_contract' => 1,
            'complete_contract_cases' => 1,
            'coverage_ratio' => 1.0,
            'complete_ratio' => 1.0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function complexityCoverage(): array
    {
        return [
            'schema_version' => 'atlas.forge.rivals.complexity_profile_coverage.v1',
            'advisory_only' => true,
            'routing_effect' => 'none',
            'case_count' => 1,
            'cases_with_complexity_profile' => 1,
            'coverage_ratio' => 1.0,
            'long_context_required_cases' => 1,
            'evidence_matrix_required_cases' => 1,
            'multi_step_plan_required_cases' => 1,
            'meta_provider_claim_floor_met' => false,
        ];
    }
}
