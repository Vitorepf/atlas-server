<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Console\Commands\AtlasProgrammingRivalsEvidencePackCommand;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackVerifierService;
use App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseEvaluationService;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Tests\TestCase;

class AtlasRivalsEvidencePackTest extends TestCase
{
    public function test_evidence_pack_command_returns_json_and_no_provider_call(): void
    {
        $this->assertTrue(class_exists(AtlasProgrammingRivalsEvidencePackCommand::class));

        $exitCode = $this->artisan('atlas:programming:rivals-evidence-pack', ['--json' => true])
            ->run();

        $this->assertSame(0, $exitCode);

        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $this->assertFalse($pack['external_provider_call']);
        $this->assertFalse($pack['provider_dispatched']);
        $this->assertFalse($pack['provider_tokens_spent']);
        $this->assertFalse($pack['claim_ready']);
        $this->assertFalse($pack['promotes_external_rivals_claim']);
        $this->assertFalse($pack['synthetic_scores_allowed']);
    }

    public function test_evidence_pack_reports_missing_evidence_when_not_running_tests_or_quality(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);

        $this->assertContains('missing_test_run_log', $pack['missing_evidence']);
        $this->assertContains('missing_quality_scan_log', $pack['missing_evidence']);
        $this->assertFalse((bool) data_get($pack, 'tests.present'));
        $this->assertFalse((bool) data_get($pack, 'quality_scan.present'));
        $this->assertSame('not_run', data_get($pack, 'tests.source'));
        $this->assertSame('not_run', data_get($pack, 'quality_scan.source'));
    }

    public function test_strict_command_fails_when_required_evidence_missing(): void
    {
        $exitCode = $this->artisan('atlas:programming:rivals-evidence-pack', [
            '--json' => true,
            '--strict' => true,
        ])->run();

        $this->assertSame(1, $exitCode);
    }

    public function test_evidence_pack_includes_replay_manifest_hash(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);

        $this->assertTrue((bool) data_get($pack, 'replay_manifest.present'));
        $hash = (string) data_get($pack, 'replay_manifest.hash', '');
        $this->assertSame(64, strlen($hash));
        $this->assertSame(AtlasRivalsEvidencePackService::REPLAY_MANIFEST_SCHEMA, data_get($pack, 'replay_manifest.schema_version'));
    }

    public function test_evidence_pack_includes_business_rule_from_case_manifest(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);

        $this->assertTrue((bool) data_get($pack, 'business_rule.present'));
        $this->assertSame('case_manifest', data_get($pack, 'business_rule.source'));
        $hash = (string) data_get($pack, 'business_rule.hash', '');
        $this->assertSame(64, strlen($hash));
        $this->assertNotEmpty(data_get($pack, 'business_rule.objective'));
    }

    public function test_evidence_pack_detects_git_dirty_state_honestly(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $workspace = (array) ($pack['workspace'] ?? []);

        if (($workspace['is_git'] ?? false) === true) {
            // workspace is git: clean/dirty must be coherent
            $dirty = (int) ($workspace['dirty_count'] ?? 0);
            $this->assertSame($dirty === 0, (bool) ($workspace['clean'] ?? false));
        } else {
            // workspace not git: never marked clean
            $this->assertFalse((bool) ($workspace['clean'] ?? false));
        }
    }

    public function test_verifier_passes_honest_pack(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $verification = app(AtlasRivalsEvidencePackVerifierService::class)->verify($pack);

        $this->assertSame(AtlasRivalsEvidencePackVerifierService::SCHEMA_VERSION, $verification['schema_version']);
        $this->assertSame('passed', $verification['status']);
        $this->assertSame([], $verification['blockers']);
        $this->assertTrue($verification['verifier_blocks_fake_evidence']);
        $this->assertTrue($verification['no_provider_call']);
    }

    public function test_verifier_blocks_fake_evidence_present_true_without_hash_or_source(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $pack['tests'] = [
            'present' => true,
            'source' => null,
            'log_hash' => null,
            'exit_code' => 0,
            'passed' => true,
        ];
        $pack['command_exit_codes']['test_command'] = 0;
        if (isset($pack['missing_evidence']) && is_array($pack['missing_evidence'])) {
            $pack['missing_evidence'] = array_values(array_filter($pack['missing_evidence'], static fn (string $m): bool => $m !== 'missing_test_run_log'));
        }

        $verification = app(AtlasRivalsEvidencePackVerifierService::class)->verify($pack);

        $this->assertSame('blocked', $verification['status']);
        $this->assertContains('tests_present_but_source_missing', $verification['blockers']);
        $this->assertContains('tests_present_but_hash_missing', $verification['blockers']);
    }

    public function test_verifier_blocks_provider_call_flag_true(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $pack['external_provider_call'] = true;

        $verification = app(AtlasRivalsEvidencePackVerifierService::class)->verify($pack);

        $this->assertSame('blocked', $verification['status']);
        $this->assertContains('provider_call_flag_not_false', $verification['blockers']);
    }

    public function test_verifier_blocks_workspace_clean_inconsistency(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $pack['workspace']['clean'] = true;
        $pack['workspace']['dirty_count'] = 5;

        $verification = app(AtlasRivalsEvidencePackVerifierService::class)->verify($pack);

        $this->assertSame('blocked', $verification['status']);
        $this->assertContains('workspace_dirty_count_inconsistent_with_clean_flag', $verification['blockers']);
    }

    public function test_evaluator_with_evidence_pack_changes_dimension_inputs_without_promoting_claim(): void
    {
        $service = app(AtlasRivalsEvidencePackService::class);
        $pack = $service->generate([]);
        $evidenceInput = $service->toEvaluationEvidenceInput($pack);

        $report = app(AtlasRivalsOneShotEnterpriseEvaluationService::class)->evaluate([
            'replay_manifest' => [
                'schema_version' => AtlasRivalsEvidencePackService::REPLAY_MANIFEST_SCHEMA,
                'valid' => true,
                'atlas_arm' => ['runtime' => 'forge'],
                'rival_arm' => ['runtime' => 'claude_code_baseline'],
                'acceptance_gates' => ['forge_runtime_certified'],
            ],
            'case_manifest' => ['case' => ['objective' => 'test objective']],
            'evidence_pack' => $evidenceInput,
            'evaluation_mode' => 'local_manifest_evaluation',
        ]);

        $this->assertFalse($report['promotes_external_rivals_claim']);
        $this->assertFalse($report['claim_ready']);
        $this->assertFalse($report['external_provider_call']);
        $this->assertSame(100, (int) $report['max_score']);
    }

    public function test_completion_audit_exposes_rivals_evidence_pack_certification(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path(), false);

        $this->assertArrayHasKey('rivals_evidence_pack_certification', $report);
        $cert = $report['rivals_evidence_pack_certification'];

        $this->assertSame('atlas.programming.rivals_evidence_pack_certification.v1', $cert['schema_version']);
        $this->assertTrue($cert['evidence_pack_service_available']);
        $this->assertTrue($cert['verifier_service_available']);
        $this->assertTrue($cert['command_available']);
        $this->assertTrue($cert['schema_available']);
        $this->assertTrue($cert['doc_available']);
        $this->assertTrue($cert['integrates_with_one_shot_evaluation']);
        $this->assertTrue($cert['replay_manifest_hash_available']);
        $this->assertTrue($cert['patch_diff_supported']);
        $this->assertTrue($cert['test_log_supported']);
        $this->assertTrue($cert['quality_log_supported']);
        $this->assertTrue($cert['missing_evidence_reported']);
        $this->assertTrue($cert['verifier_blocks_fake_evidence']);
        $this->assertTrue($cert['no_provider_call']);
        $this->assertFalse($cert['promotes_external_rivals_claim']);
        $this->assertTrue($cert['separated_from_external_rivals_certification']);
        $this->assertFalse($cert['synthetic_scores_allowed']);
    }

    public function test_external_rivals_remains_blocked(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path(), false);
        $external = $report['external_rivals_certification'];

        $this->assertContains(
            $external['status'],
            ['blocked', 'blocked_requires_operator_approval'],
            'external_rivals_certification must never be unlocked by Evidence Pack certification.',
        );
        $this->assertFalse((bool) ($external['claim_ready'] ?? true));
    }

    public function test_no_synthetic_score_admitted_as_claim(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);

        $this->assertFalse($pack['synthetic_scores_allowed']);
        $this->assertFalse($pack['claim_ready']);
        $this->assertFalse($pack['promotes_external_rivals_claim']);
    }

    public function test_present_true_carries_source_and_hash_for_business_rule_and_replay_manifest(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);

        $this->assertTrue((bool) data_get($pack, 'business_rule.present'));
        $this->assertNotEmpty(data_get($pack, 'business_rule.source'));
        $this->assertSame(64, strlen((string) data_get($pack, 'business_rule.hash', '')));

        $this->assertTrue((bool) data_get($pack, 'replay_manifest.present'));
        $this->assertNotEmpty(data_get($pack, 'replay_manifest.source'));
        $this->assertSame(64, strlen((string) data_get($pack, 'replay_manifest.hash', '')));
    }
}
