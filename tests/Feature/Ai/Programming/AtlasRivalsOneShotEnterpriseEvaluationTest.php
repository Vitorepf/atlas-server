<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Console\Commands\AtlasProgrammingRivalsOneShotEvaluateCommand;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsCaseManifestService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsDryRunService;
use App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseEvaluationService;
use App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseRubricService;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Tests\TestCase;

class AtlasRivalsOneShotEnterpriseEvaluationTest extends TestCase
{
    public function test_rubric_weights_sum_to_one_hundred(): void
    {
        $rubric = app(AtlasRivalsOneShotEnterpriseRubricService::class)->rubric();

        $this->assertSame('atlas.programming.rivals_one_shot_enterprise_rubric.v1', $rubric['schema_version']);
        $this->assertSame(100, (int) $rubric['score_weights_total']);
        $this->assertSame(13, (int) $rubric['score_dimensions_count']);
        $this->assertSame(
            array_sum(array_column($rubric['scoring_dimensions'], 'weight')),
            $rubric['score_weights_total'],
        );
    }

    public function test_rubric_declares_speed_secondary_and_quality_primary(): void
    {
        $rubric = app(AtlasRivalsOneShotEnterpriseRubricService::class)->rubric();

        $this->assertSame('one_shot_enterprise_quality', $rubric['primary_objective']);
        $this->assertTrue($rubric['speed_is_secondary']);
        $this->assertTrue($rubric['quality_can_compensate_time']);
        $this->assertTrue($rubric['time_cannot_compensate_quality']);
        $this->assertFalse($rubric['synthetic_scores_allowed']);
        $this->assertFalse($rubric['promotes_external_rivals_claim']);
    }

    public function test_rubric_includes_business_rule_alignment(): void
    {
        $ids = $this->dimensionIds();

        $this->assertContains('business_rule_alignment', $ids);
    }

    public function test_rubric_includes_canonical_documentation_adherence(): void
    {
        $ids = $this->dimensionIds();

        $this->assertContains('canonical_documentation_adherence', $ids);
    }

    public function test_evaluation_hard_fails_when_replay_manifest_is_missing(): void
    {
        $report = app(AtlasRivalsOneShotEnterpriseEvaluationService::class)->evaluate([
            'case_manifest' => $this->caseManifestPacket(),
            'evidence_pack' => ['canonical_docs_required' => true, 'canonical_docs_consulted' => ['doc.md']],
        ]);

        $this->assertSame('atlas.programming.rivals_one_shot_enterprise_evaluation.v1', $report['schema_version']);
        $this->assertSame('invalid', $report['status']);
        $this->assertSame(AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_INVALID, $report['grade']);
        $this->assertContains('missing_replay_manifest', $report['hard_fails']);
        $this->assertFalse($report['external_provider_call']);
        $this->assertFalse($report['promotes_external_rivals_claim']);
        $this->assertFalse($report['claim_ready']);
    }

    public function test_evaluation_hard_fails_when_atlas_arm_is_not_forge(): void
    {
        $report = app(AtlasRivalsOneShotEnterpriseEvaluationService::class)->evaluate([
            'replay_manifest' => [
                'schema_version' => 'atlas.programming.forge_native_rivals_replay_manifest.v1',
                'state' => 'planned',
                'valid' => true,
                'atlas_arm' => ['runtime' => 'manual_chat'],
                'rival_arm' => ['runtime' => 'claude_code_baseline'],
                'acceptance_gates' => ['forge_runtime_certified'],
            ],
            'case_manifest' => $this->caseManifestPacket(),
            'evidence_pack' => ['canonical_docs_required' => true, 'canonical_docs_consulted' => ['doc.md']],
        ]);

        $this->assertSame(AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_INVALID, $report['grade']);
        $this->assertContains('atlas_arm_not_forge', $report['hard_fails']);
        $this->assertFalse($report['claim_ready']);
        $this->assertFalse($report['promotes_external_rivals_claim']);
    }

    public function test_evaluation_never_promotes_external_rivals_claim_even_when_score_is_high(): void
    {
        $report = app(AtlasRivalsOneShotEnterpriseEvaluationService::class)->evaluate([
            'replay_manifest' => $this->validForgeReplayManifest(),
            'case_manifest' => $this->caseManifestPacket(),
            'evidence_pack' => $this->richEvidencePack(),
            'review_packet' => [
                'architecture_review' => 'passed',
                'review_status' => 'approved',
                'intervention_count' => 0,
                'review_complexity' => 'low',
            ],
            'completion_claim' => ['human_approved' => true, 'auto_completed' => false],
        ]);

        $this->assertGreaterThanOrEqual(75, (int) $report['total_score']);
        $this->assertFalse($report['promotes_external_rivals_claim']);
        $this->assertFalse($report['claim_ready']);
        $this->assertSame(100, (int) $report['max_score']);
        $this->assertFalse($report['external_provider_call']);
    }

    public function test_local_fixture_evaluation_returns_diagnostic_score_and_claim_ready_false(): void
    {
        $caseManifest = app(AtlasForgeNativeRivalsCaseManifestService::class)->manifest(null);
        $dryRun = app(AtlasForgeNativeRivalsDryRunService::class)->dryRun([]);
        $report = app(AtlasRivalsOneShotEnterpriseEvaluationService::class)->evaluate([
            'replay_manifest' => $dryRun['replay_manifest'] ?? data_get($dryRun, 'planned.replay_manifest'),
            'case_manifest' => $caseManifest,
            'evidence_pack' => ['canonical_docs_required' => true, 'canonical_docs_consulted' => ['protocol.md']],
            'evaluation_mode' => 'local_manifest_evaluation',
        ]);

        $this->assertSame('local_manifest_evaluation', $report['evaluation_mode']);
        $this->assertSame('evaluated_local_manifest', $report['status']);
        $this->assertFalse($report['claim_ready']);
        $this->assertFalse($report['promotes_external_rivals_claim']);
        $this->assertNull($report['claim_score']);
        $this->assertNotNull($report['diagnostic_score']);
        $this->assertContains('score_is_diagnostic_not_claim', $report['limitations']);
        $this->assertContains('no_real_provider_baseline', $report['limitations']);
    }

    public function test_command_returns_json_and_does_not_call_provider(): void
    {
        $this->assertTrue(class_exists(AtlasProgrammingRivalsOneShotEvaluateCommand::class));

        $exitCode = $this->artisan('atlas:programming:rivals-one-shot-evaluate', ['--json' => true])
            ->run();
        $this->assertSame(0, $exitCode);

        $report = app(AtlasRivalsOneShotEnterpriseEvaluationService::class)->evaluate([
            'replay_manifest' => $this->validForgeReplayManifest(),
            'case_manifest' => $this->caseManifestPacket(),
            'evidence_pack' => $this->richEvidencePack(),
            'evaluation_mode' => 'local_manifest_evaluation',
        ]);
        $this->assertFalse($report['external_provider_call']);
        $this->assertFalse($report['provider_tokens_spent']);
    }

    public function test_strict_command_fails_for_invalid_case(): void
    {
        $exitCode = $this->artisan('atlas:programming:rivals-one-shot-evaluate', [
            '--case' => 'does-not-exist',
            '--json' => true,
            '--strict' => true,
        ])->run();

        $this->assertSame(1, $exitCode);
    }

    public function test_completion_audit_exposes_one_shot_certification_separated_from_external_rivals(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path(), false);

        $this->assertArrayHasKey('rivals_one_shot_enterprise_evaluation_certification', $report);
        $this->assertArrayHasKey('external_rivals_certification', $report);

        $cert = $report['rivals_one_shot_enterprise_evaluation_certification'];
        $this->assertSame('atlas.programming.rivals_one_shot_enterprise_evaluation_certification.v1', $cert['schema_version']);
        $this->assertTrue($cert['rubric_available']);
        $this->assertTrue($cert['evaluation_service_available']);
        $this->assertTrue($cert['command_available']);
        $this->assertTrue($cert['doc_available']);
        $this->assertSame(100, (int) $cert['score_weights_total']);
        $this->assertSame(13, (int) $cert['score_dimensions_count']);
        $this->assertTrue($cert['speed_is_secondary']);
        $this->assertTrue($cert['time_cannot_compensate_quality']);
        $this->assertTrue($cert['quality_can_compensate_time']);
        $this->assertTrue($cert['evaluates_business_rule_alignment']);
        $this->assertTrue($cert['evaluates_canonical_documentation_adherence']);
        $this->assertTrue($cert['evaluates_one_shot_completeness']);
        $this->assertTrue($cert['evaluates_tests_and_risk_coverage']);
        $this->assertTrue($cert['evaluates_forge_governance']);
        $this->assertTrue($cert['evaluates_human_intervention_load']);
        $this->assertTrue($cert['local_fixture_evaluation_passed']);
        $this->assertSame('evaluated_local_manifest', data_get($cert, 'local_fixture_evaluation.status'));
        $this->assertFalse($cert['promotes_external_rivals_claim']);
        $this->assertTrue($cert['separated_from_external_rivals_certification']);
        $this->assertFalse($cert['external_provider_call']);
        $this->assertFalse($cert['synthetic_scores_allowed']);
    }

    public function test_external_rivals_certification_remains_blocked_after_one_shot_block_available(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path(), false);

        $external = $report['external_rivals_certification'];
        $this->assertContains(
            $external['status'],
            ['blocked', 'blocked_requires_operator_approval'],
            'Rivals One-Shot certification must never unblock external_rivals_certification.',
        );
        $this->assertFalse((bool) ($external['claim_ready'] ?? true));
    }

    public function test_synthetic_score_used_as_real_claim_hard_fails(): void
    {
        $report = app(AtlasRivalsOneShotEnterpriseEvaluationService::class)->evaluate([
            'replay_manifest' => $this->validForgeReplayManifest(),
            'case_manifest' => $this->caseManifestPacket(),
            'evidence_pack' => $this->richEvidencePack() + ['synthetic_score_admitted_as_real_claim' => true],
        ]);

        $this->assertSame(AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_INVALID, $report['grade']);
        $this->assertContains('synthetic_score_used_as_real_claim', $report['hard_fails']);
        $this->assertFalse($report['claim_ready']);
        $this->assertFalse($report['synthetic_scores_allowed']);
    }

    public function test_quality_can_compensate_time_and_time_cannot_compensate_quality(): void
    {
        $service = app(AtlasRivalsOneShotEnterpriseEvaluationService::class);
        $highQualityHighTime = $service->evaluate([
            'replay_manifest' => $this->validForgeReplayManifest(),
            'case_manifest' => $this->caseManifestPacket(),
            'evidence_pack' => $this->richEvidencePack(),
            'review_packet' => ['architecture_review' => 'passed', 'review_status' => 'approved', 'intervention_count' => 0, 'review_complexity' => 'low'],
            'observed_time_metrics' => ['wall_clock_seconds' => 10800],
        ]);
        $lowQualityLowTime = $service->evaluate([
            'replay_manifest' => $this->validForgeReplayManifest(),
            'case_manifest' => $this->caseManifestPacket(),
            'evidence_pack' => ['canonical_docs_required' => true, 'canonical_docs_consulted' => ['doc.md'], 'tests_present' => true],
            'observed_time_metrics' => ['wall_clock_seconds' => 60],
        ]);

        $this->assertGreaterThan(
            $lowQualityLowTime['total_score'],
            $highQualityHighTime['total_score'],
            'Rubric must let extreme quality with high time beat low quality with low time.',
        );
    }

    /**
     * @return list<string>
     */
    private function dimensionIds(): array
    {
        $rubric = app(AtlasRivalsOneShotEnterpriseRubricService::class)->rubric();

        return array_map(static fn (array $d): string => (string) $d['id'], (array) $rubric['scoring_dimensions']);
    }

    /**
     * @return array<string,mixed>
     */
    private function caseManifestPacket(): array
    {
        return [
            'case' => [
                'objective' => 'Aplicar patch fixture governado em uma Obra com gates de qualidade e teste.',
                'atlas_arm' => ['runtime' => 'forge', 'atlas_side_must_use_forge' => true],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validForgeReplayManifest(): array
    {
        return [
            'schema_version' => 'atlas.programming.forge_native_rivals_replay_manifest.v1',
            'state' => 'planned',
            'valid' => true,
            'atlas_arm' => [
                'runtime' => 'forge',
                'command_template' => 'php artisan atlas:code:forge-fast-path --obra=<uuid> --mode=execute_async --json --strict',
            ],
            'rival_arm' => ['runtime' => 'claude_code_baseline'],
            'acceptance_gates' => ['forge_runtime_certified', 'patch_verifier_passed'],
            'timeout_policy' => ['wall_clock_seconds_max' => 1800],
            'evidence_requirements' => ['replay_manifest' => true],
            'invalid_if' => ['atlas_not_forge'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function richEvidencePack(): array
    {
        return [
            'business_rule_check' => 'passed',
            'canonical_docs_required' => true,
            'canonical_docs_consulted' => ['atlas-forge-native-rivals-protocol-v1.md'],
            'todo_count' => 0,
            'test_run_log' => 'tests/Feature passed (59 tests, 784 assertions)',
            'tests_passed' => true,
            'tests_present' => true,
            'command_exit_codes' => ['preflight' => 0, 'dry_run' => 0],
            'assertion_count' => 120,
            'test_files_changed' => ['tests/Feature/Ai/Programming/AtlasForgeNativeRivalsTest.php'],
            'file_count_by_layer' => ['services' => 4, 'commands' => 2, 'tests' => 1, 'docs' => 1],
            'preflight_status' => 'ready_for_dry_run',
            'workspace_state_for_claim' => 'clean',
            'quality_scan_log' => 'passed',
            'command_signature' => '{--case=} {--json} {--strict}',
            'evidence_paths' => ['app/Services/Ai/Programming', 'tests/Feature/Ai/Programming'],
            'human_intervention_log_count' => 0,
            'review_minutes_estimate' => 20,
            'patch_diff' => 'diff stub',
        ];
    }
}
