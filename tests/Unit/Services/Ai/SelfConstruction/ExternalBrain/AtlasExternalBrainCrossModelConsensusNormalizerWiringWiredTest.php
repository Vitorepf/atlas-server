<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainCrossModelConsensusNormalizerWiringWiredTest extends TestCase
{
    private function writeInput(array $input): string
    {
        $path = tempnam(sys_get_temp_dir(), 'originator_quality_');
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));

        return $path;
    }

    public function test_missing_cross_model_consensus_section_is_absent_from_payload(): void
    {
        $path = $this->writeInput(['opportunities' => []]);

        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $path]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        unlink($path);

        self::assertArrayNotHasKey('cross_model_consensus', $payload);
    }

    public function test_low_leverage_proposal_is_rejected_and_high_evidence_proposal_selected(): void
    {
        $path = $this->writeInput([
            'opportunities' => [],
            'cross_model_consensus' => [
                'proposals' => [
                    ['proposal_id' => 'a', 'leverage_score' => 0.9, 'evidence_strength' => 0.8],
                    ['proposal_id' => 'b', 'leverage_score' => 0.1, 'evidence_strength' => 0.9],
                ],
            ],
        ]);

        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $path]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        unlink($path);

        self::assertArrayHasKey('cross_model_consensus', $payload);
        self::assertSame(
            'atlas.external_brain.cross_model_consensus_normalizer.v1',
            $payload['cross_model_consensus']['schema'],
        );
        self::assertSame('a', $payload['cross_model_consensus']['selected_proposals'][0]['proposal_id']);
        self::assertSame('b', $payload['cross_model_consensus']['rejected_proposals'][0]['proposal_id']);
        self::assertSame('low_leverage', $payload['cross_model_consensus']['rejected_proposals'][0]['rejection_reason']);
    }
}
