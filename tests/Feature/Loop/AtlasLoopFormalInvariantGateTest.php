<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopFormalInvariantGateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasLoopFormalInvariantGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'atlas.loop.formal_invariant_gate.enabled' => true,
            'atlas.loop.formal_invariant_gate.schedule_enabled' => true,
            'atlas.loop.formal_invariant_gate.schedule_time' => '06:50',
        ]);
    }

    public function test_command_certifies_sensitive_kernel_formal_light_invariants(): void
    {
        $exit = Artisan::call('atlas:loop:formal-invariant-gate', [
            '--fixture' => 'safe',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('atlas.loop.formal_invariant_gate.v1', $payload['schema_version']);
        $this->assertSame('formal_invariants_verified', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertTrue($payload['completion_claim_allowed']);
        $this->assertSame(4, data_get($payload, 'counts.invariants'));
        $this->assertSame(4, data_get($payload, 'counts.checks_passed'));
        $this->assertSame(4, data_get($payload, 'counts.proofs_verified'));
        $this->assertSame('not_verified', data_get($payload, 'artifact.proof_status_boundary'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.formal_verification_claimed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.never_merge_changed'));
        $this->assertSame([], $payload['blockers']);
    }

    public function test_missing_proof_coverage_fails_strict_even_when_local_checks_pass(): void
    {
        $exit = Artisan::call('atlas:loop:formal-invariant-gate', [
            '--fixture' => 'missing-coverage',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('formal_invariant_unverified', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertSame(4, data_get($payload, 'counts.checks_passed'));
        $this->assertContains('invariant_coverage_incomplete', $payload['blockers']);
    }

    public function test_tampered_never_merge_door_source_fails_before_proof_claim(): void
    {
        $payload = app(AtlasLoopFormalInvariantGateService::class)->evaluate([
            'fixture' => 'tampered-never-merge',
        ]);

        $this->assertSame('formal_invariant_failed', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertContains('governed_merge_door_checks_merged_to_main_true_missing', $payload['blockers']);
        $this->assertContains('governed_merge_door_requires_governed_session_setting_missing', $payload['blockers']);
    }

    public function test_command_writes_receipt_when_requested(): void
    {
        $receipt = storage_path('framework/testing/formal-invariant-gate.json');
        File::delete($receipt);

        $exit = Artisan::call('atlas:loop:formal-invariant-gate', [
            '--fixture' => 'safe',
            '--receipt' => $receipt,
            '--write-receipt' => true,
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertFileExists($receipt);
        $this->assertSame($receipt, $payload['receipt_path']);
        $written = json_decode((string) file_get_contents($receipt), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('formal_invariants_verified', $written['status']);
    }

    public function test_schedule_contains_daily_formal_invariant_gate_fixture_proof(): void
    {
        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('atlas:loop:formal-invariant-gate --fixture=safe --write-receipt --json', $output);
    }
}
