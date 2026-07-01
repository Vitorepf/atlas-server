<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainCritiqueQuorumReducerWiringWiredTest extends TestCase
{
    private function writeInput(array $input): string
    {
        $path = tempnam(sys_get_temp_dir(), 'originator_quality_');
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));

        return $path;
    }

    public function test_missing_critique_quorum_section_is_absent_from_payload(): void
    {
        $path = $this->writeInput(['opportunities' => []]);

        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $path]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        unlink($path);

        self::assertArrayNotHasKey('critique_quorum', $payload);
    }

    public function test_high_severity_finding_with_strong_evidence_and_agreement_rejects(): void
    {
        $path = $this->writeInput([
            'opportunities' => [],
            'critique_quorum' => [
                'critique_outputs' => [
                    ['findings' => [['type' => 'safety', 'severity' => 'high', 'evidence' => 'a serious data-loss risk', 'evidence_strength' => 0.9, 'blocker_class' => 'safety']]],
                    ['findings' => [['type' => 'safety', 'severity' => 'high', 'evidence' => 'confirmed', 'evidence_strength' => 0.8, 'blocker_class' => 'safety']]],
                ],
            ],
        ]);

        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $path]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        unlink($path);

        self::assertArrayHasKey('critique_quorum', $payload);
        self::assertSame('atlas.external_brain.critique_quorum_reducer.v1', $payload['critique_quorum']['schema_version']);
        self::assertSame('reject', $payload['critique_quorum']['decision']);
        self::assertNotEmpty($payload['critique_quorum']['blocking_findings']);
    }
}
