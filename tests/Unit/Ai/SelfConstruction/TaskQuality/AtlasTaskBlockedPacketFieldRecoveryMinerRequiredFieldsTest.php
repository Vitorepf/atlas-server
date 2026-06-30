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
}
