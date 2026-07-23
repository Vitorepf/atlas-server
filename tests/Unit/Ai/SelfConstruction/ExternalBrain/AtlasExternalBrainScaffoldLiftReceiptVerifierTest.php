<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldLiftReceiptVerifier;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldLiftReceiptVerifierTest extends TestCase
{
    private AtlasExternalBrainScaffoldLiftReceiptVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new AtlasExternalBrainScaffoldLiftReceiptVerifier();
    }

    // AC 2: cosmetic formatting improvement without quality delta → no_measured_lift
    public function test_cosmetic_without_quality_delta_rejected(): void
    {
        $result = $this->verifier->verify(
            ['give_back_rate' => 0.2, 'proof_coverage' => 0.8, 'task_quality_score' => 0.7],
            ['give_back_rate' => 0.2, 'proof_coverage' => 0.8, 'task_quality_score' => 0.7],
        );

        $this->assertSame('no_measured_lift', $result['verdict']);
        $this->assertContains('no_quality_delta_detected', $result['reasons']);
    }

    // AC 3: lower give_back rate with equal or better proof coverage → measured_lift
    public function test_lower_give_back_with_equal_proof_accepted(): void
    {
        $result = $this->verifier->verify(
            ['give_back_rate' => 0.3, 'proof_coverage' => 0.8, 'task_quality_score' => 0.7],
            ['give_back_rate' => 0.1, 'proof_coverage' => 0.8, 'task_quality_score' => 0.7],
        );

        $this->assertSame('measured_lift', $result['verdict']);
    }

    public function test_lower_give_back_with_better_proof_accepted(): void
    {
        $result = $this->verifier->verify(
            ['give_back_rate' => 0.3, 'proof_coverage' => 0.6, 'task_quality_score' => 0.5],
            ['give_back_rate' => 0.1, 'proof_coverage' => 0.9, 'task_quality_score' => 0.8],
        );

        $this->assertSame('measured_lift', $result['verdict']);
    }

    // AC 4: lift receipts include baseline, candidate and decision fields
    public function test_output_has_baseline_candidate_decision(): void
    {
        $result = $this->verifier->verify(
            ['give_back_rate' => 0.3, 'proof_coverage' => 0.8],
            ['give_back_rate' => 0.1, 'proof_coverage' => 0.8],
        );

        $this->assertArrayHasKey('baseline', $result);
        $this->assertArrayHasKey('candidate', $result);
        $this->assertArrayHasKey('decision', $result);
    }

    public function test_give_back_improvement_with_proof_degradation_rejected(): void
    {
        $result = $this->verifier->verify(
            ['give_back_rate' => 0.3, 'proof_coverage' => 0.9],
            ['give_back_rate' => 0.1, 'proof_coverage' => 0.5],
        );

        $this->assertSame('no_measured_lift', $result['verdict']);
    }

    public function test_quality_score_improvement_alone_accepted(): void
    {
        $result = $this->verifier->verify(
            ['give_back_rate' => 0.2, 'proof_coverage' => 0.8, 'task_quality_score' => 0.5],
            ['give_back_rate' => 0.2, 'proof_coverage' => 0.8, 'task_quality_score' => 0.9],
        );

        $this->assertSame('measured_lift', $result['verdict']);
    }
}
