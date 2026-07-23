<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientOutcomeVerifier;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientOutcomeVerifierTest extends TestCase
{
    private function verifier(): AtlasExternalBrainLocalClientOutcomeVerifier
    {
        return new AtlasExternalBrainLocalClientOutcomeVerifier;
    }

    // ── AC: success without tests is rejected ──

    public function test_success_without_tests_rejected(): void
    {
        $result = $this->verifier()->verify([
            'task_id' => 't1',
            'client_id' => 'c1',
            'outcome' => 'success',
            'evidence' => [],
        ]);

        $this->assertSame('rejected', $result['verdict']);
        $this->assertContains('success_without_test_evidence', $result['reasons']);
    }

    public function test_success_with_test_evidence_accepted(): void
    {
        $result = $this->verifier()->verify([
            'task_id' => 't2',
            'client_id' => 'c1',
            'outcome' => 'success',
            'evidence' => ['php artisan test tests/Unit/FooTest.php'],
        ]);

        $this->assertSame('accepted', $result['verdict']);
    }

    // ── AC: give_back with reason is retained ──

    public function test_give_back_with_reason_retained(): void
    {
        $result = $this->verifier()->verify([
            'task_id' => 't3',
            'client_id' => 'c1',
            'outcome' => 'give_back',
            'reason' => 'scope_too_wide',
        ]);

        $this->assertSame('retained', $result['verdict']);
        $this->assertContains('give_back_with_reason:scope_too_wide', $result['reasons']);
    }

    public function test_give_back_without_reason_retained(): void
    {
        $result = $this->verifier()->verify([
            'task_id' => 't4',
            'client_id' => 'c1',
            'outcome' => 'give_back',
        ]);

        $this->assertSame('retained', $result['verdict']);
    }

    // ── AC: malformed reports are quarantined ──

    public function test_malformed_report_quarantined(): void
    {
        $result = $this->verifier()->verify([
            'task_id' => 't5',
            'client_id' => 'c1',
            'outcome' => '',
        ]);

        $this->assertSame('quarantined', $result['verdict']);
        $this->assertContains('malformed_report_missing_outcome', $result['reasons']);
    }

    public function test_unknown_outcome_quarantined(): void
    {
        $result = $this->verifier()->verify([
            'task_id' => 't6',
            'client_id' => 'c1',
            'outcome' => 'something_unknown',
        ]);

        $this->assertSame('quarantined', $result['verdict']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->verifier()->verify([]);

        $this->assertSame(AtlasExternalBrainLocalClientOutcomeVerifier::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('reasons', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $report = ['task_id' => 't', 'outcome' => 'success', 'evidence' => ['php artisan test']];
        $a = $this->verifier()->verify($report);
        $b = $this->verifier()->verify($report);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
