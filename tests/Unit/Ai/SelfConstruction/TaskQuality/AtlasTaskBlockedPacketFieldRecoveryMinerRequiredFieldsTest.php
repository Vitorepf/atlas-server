<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedPacketFieldRecoveryMiner;
use Tests\TestCase;

final class AtlasTaskBlockedPacketFieldRecoveryMinerRequiredFieldsTest extends TestCase
{
    private function svc(): AtlasTaskBlockedPacketFieldRecoveryMiner
    {
        return new AtlasTaskBlockedPacketFieldRecoveryMiner;
    }

    public function test_class_like_target_with_matching_test_path_recovers_allowed_files_and_runnable_acceptance(): void
    {
        $result = $this->svc()->recover([
            'objective' => 'Strengthen AtlasFooBarValidator so it rejects malformed input deterministically.',
            'known_existing_paths' => [
                'tests/Unit/Ai/SelfConstruction/TaskQuality/AtlasFooBarValidatorTest.php',
            ],
        ]);

        $this->assertContains(
            'tests/Unit/Ai/SelfConstruction/TaskQuality/AtlasFooBarValidatorTest.php',
            $result['recovered_fields']['allowed_files'],
        );
        $this->assertNotEmpty($result['recovered_fields']['acceptance_criteria']);
        $this->assertStringContainsString('artisan test', $result['recovered_fields']['acceptance_criteria'][0]);
        $this->assertContains('class_target_matched_test_path', $result['evidence_sources']);
        $this->assertNotContains('allowed_files', $result['missing_fields']);
    }

    public function test_source_packet_id_corroborates_but_never_fabricates_alone(): void
    {
        $result = $this->svc()->recover([
            'objective' => 'Fix the unrelated regression in the dispatcher.',
            'source_packet_id' => 'codex-meta-some-prior-task-123',
        ]);

        $this->assertSame([], $result['recovered_fields']['allowed_files']);
        $this->assertNotContains('source_packet_id_corroboration', $result['evidence_sources']);
        $this->assertContains('allowed_files', $result['missing_fields']);
    }

    public function test_no_recoverable_path_signal_keeps_missing_fields_explicit_and_does_not_fabricate(): void
    {
        $result = $this->svc()->recover([
            'objective' => 'Do something vague with no class or path mentioned at all.',
        ]);

        $this->assertSame([], $result['recovered_fields']['allowed_files']);
        $this->assertContains('allowed_files', $result['missing_fields']);
        $this->assertContains('acceptance_criteria', $result['missing_fields']);
        $this->assertNotEmpty($result['refusal_reasons']);
    }

    // ── codex-meta slug recovery ─────────────────────────────────────────────

    public function test_codex_meta_slug_with_corroborating_impl_and_test_pair_recovers_with_high_confidence(): void
    {
        $result = $this->svc()->recover([
            'task_packet_id' => 'codex-meta-foo-bar-validator-20260630-351',
            'objective' => 'Some blocked draft with no other usable signal.',
            'known_existing_paths' => [
                'app/Services/Ai/SelfConstruction/TaskQuality/FooBarValidator.php',
                'tests/Unit/Ai/SelfConstruction/TaskQuality/FooBarValidatorTest.php',
            ],
        ]);

        $this->assertContains(
            'app/Services/Ai/SelfConstruction/TaskQuality/FooBarValidator.php',
            $result['recovered_fields']['allowed_files'],
        );
        $this->assertContains(
            'tests/Unit/Ai/SelfConstruction/TaskQuality/FooBarValidatorTest.php',
            $result['recovered_fields']['allowed_files'],
        );
        $this->assertContains('codex_meta_slug_target_path_corroboration', $result['evidence_sources']);
        $this->assertGreaterThanOrEqual(0.75, $result['confidence']);
    }

    public function test_ambiguous_slug_with_two_implementation_candidates_remains_refused(): void
    {
        $result = $this->svc()->recover([
            'task_packet_id' => 'codex-meta-foo-bar-validator-20260630-351',
            'objective' => 'Some blocked draft with no other usable signal.',
            'known_existing_paths' => [
                'app/Services/Ai/SelfConstruction/TaskQuality/FooBarValidator.php',
                'app/Services/Ai/Other/FooBarValidator.php',
                'tests/Unit/Ai/SelfConstruction/TaskQuality/FooBarValidatorTest.php',
            ],
        ]);

        $this->assertSame([], $result['recovered_fields']['allowed_files']);
        $this->assertContains('allowed_files', $result['missing_fields']);
    }

    public function test_slug_forbidden_target_without_governance_evidence_remains_refused(): void
    {
        $result = $this->svc()->recover([
            'task_packet_id' => 'codex-meta-foo-bar-validator-20260630-351',
            'objective' => 'Some blocked draft with no other usable signal.',
            'known_existing_paths' => [
                'app/Services/Ai/SelfConstruction/TaskQuality/FooBarValidator.php',
                'tests/Unit/Ai/SelfConstruction/TaskQuality/FooBarValidatorTest.php',
            ],
            'metadata' => [
                'forbidden_or_property_gated' => ['app/Services/Ai/SelfConstruction/TaskQuality/FooBarValidator.php'],
                'governance_evidence_present' => false,
            ],
        ]);

        $this->assertSame([], $result['recovered_fields']['allowed_files']);
        $this->assertContains('target_forbidden_or_property_gated_without_evidence', $result['refusal_reasons']);
    }
}
