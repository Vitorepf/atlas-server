<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskSpecColdStartVerifier;
use Tests\TestCase;

final class AtlasExternalBrainTaskSpecColdStartVerifierTest extends TestCase
{
    private function verifier(): AtlasExternalBrainTaskSpecColdStartVerifier
    {
        return new AtlasExternalBrainTaskSpecColdStartVerifier;
    }

    private function completeSpec(): array
    {
        return [
            'objective' => 'Implement the Foo service with bar method',
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['php artisan test tests/Unit/FooTest.php'],
            'required_evidence' => ['test_result'],
        ];
    }

    // ── AC: complete cold-start specs pass ──

    public function test_complete_spec_passes(): void
    {
        $result = $this->verifier()->verify($this->completeSpec());

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['failures']);
    }

    // ── AC: missing context fails ──

    public function test_missing_objective_fails(): void
    {
        $spec = $this->completeSpec();
        $spec['objective'] = '';

        $result = $this->verifier()->verify($spec);

        $this->assertFalse($result['passed']);
        $this->assertContains('missing_objective', $result['failures']);
    }

    public function test_short_objective_fails(): void
    {
        $spec = $this->completeSpec();
        $spec['objective'] = 'short';

        $result = $this->verifier()->verify($spec);

        $this->assertFalse($result['passed']);
        $this->assertContains('objective_too_short', $result['failures']);
    }

    public function test_missing_required_evidence_fails(): void
    {
        $spec = $this->completeSpec();
        $spec['required_evidence'] = [];

        $result = $this->verifier()->verify($spec);

        $this->assertFalse($result['passed']);
        $this->assertContains('missing_required_evidence', $result['failures']);
    }

    // ── AC: non-runnable acceptance fails ──

    public function test_non_runnable_acceptance_fails(): void
    {
        $spec = $this->completeSpec();
        $spec['acceptance_criteria'] = ['code review passes'];

        $result = $this->verifier()->verify($spec);

        $this->assertFalse($result['passed']);
        $this->assertContains('no_runnable_acceptance_proof', $result['failures']);
    }

    // ── AC: unbound allowed_files fail ──

    public function test_wildcard_allowed_files_fails(): void
    {
        $spec = $this->completeSpec();
        $spec['allowed_files'] = ['app/Services/*.php'];

        $result = $this->verifier()->verify($spec);

        $this->assertFalse($result['passed']);
        $this->assertNotEmpty($result['failures']);
    }

    public function test_empty_allowed_files_fails(): void
    {
        $spec = $this->completeSpec();
        $spec['allowed_files'] = [];

        $result = $this->verifier()->verify($spec);

        $this->assertFalse($result['passed']);
        $this->assertContains('missing_allowed_files', $result['failures']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->verifier()->verify($this->completeSpec());

        $this->assertSame(AtlasExternalBrainTaskSpecColdStartVerifier::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('failures', $result);
        $this->assertArrayHasKey('has_runnable_proof', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $spec = $this->completeSpec();
        $a = $this->verifier()->verify($spec);
        $b = $this->verifier()->verify($spec);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
