<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerExecutionEnvelopeBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the AtlasNativeWorkerExecutionEnvelopeBuilder: a valid normalized packet yields an envelope
 * with runtime_owner=atlas_native, execution_topology=shared_local_main_with_scope_lock,
 * provider_prompt=null; missing scope / missing acceptance / non-Atlas-native simplicity all fail-closed;
 * identical packet ⇒ byte-identical envelope_hash.
 */
final class AtlasNativeWorkerExecutionEnvelopeBuilderTest extends TestCase
{
    private function validPacket(): array
    {
        return [
            'objective' => 'Implement a small thing.',
            'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'scope_in' => ['app/Foo.php'],
            'acceptance_criteria' => ['phpunit green'],
            'required_evidence' => ['test_run_id', 'commit_sha'],
            'gates' => ['phpunit', 'static_analysis'],
            'simplicity_contract' => AtlasNativeWorkerExecutionEnvelopeBuilder::SIMPLICITY_CONTRACT_NATIVE,
        ];
    }

    public function test_valid_packet_emits_atlas_native_envelope_with_provider_prompt_null(): void
    {
        $e = (new AtlasNativeWorkerExecutionEnvelopeBuilder)->build($this->validPacket());

        $this->assertSame(AtlasNativeWorkerExecutionEnvelopeBuilder::RUNTIME_OWNER, $e['runtime_owner']);
        $this->assertSame(AtlasNativeWorkerExecutionEnvelopeBuilder::EXECUTION_TOPOLOGY, $e['execution_topology']);
        $this->assertNull($e['provider_prompt']);
        $this->assertSame(['app/Foo.php', 'tests/Unit/FooTest.php'], $e['allowed_files']);
        $this->assertSame(['phpunit green'], $e['acceptance_criteria']);
        $this->assertSame(['test_run_id', 'commit_sha'], $e['required_evidence']);
    }

    public function test_missing_allowed_files_fails_closed(): void
    {
        $p = $this->validPacket();
        unset($p['allowed_files']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing allowed_files/');
        (new AtlasNativeWorkerExecutionEnvelopeBuilder)->build($p);
    }

    public function test_empty_acceptance_criteria_fails_closed(): void
    {
        $p = $this->validPacket();
        $p['acceptance_criteria'] = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty acceptance_criteria/');
        (new AtlasNativeWorkerExecutionEnvelopeBuilder)->build($p);
    }

    public function test_missing_required_evidence_fails_closed(): void
    {
        $p = $this->validPacket();
        unset($p['required_evidence']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required_evidence/');
        (new AtlasNativeWorkerExecutionEnvelopeBuilder)->build($p);
    }

    public function test_non_atlas_native_simplicity_contract_fails_closed(): void
    {
        $p = $this->validPacket();
        $p['simplicity_contract'] = 'external_provider';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non Atlas-native simplicity_contract/');
        (new AtlasNativeWorkerExecutionEnvelopeBuilder)->build($p);
    }

    public function test_provider_prompt_is_strictly_null_no_prompt_field_leaks(): void
    {
        $e = (new AtlasNativeWorkerExecutionEnvelopeBuilder)->build($this->validPacket());
        $this->assertNull($e['provider_prompt']);
        $json = json_encode($e);
        $this->assertStringNotContainsString('"prompt"', $json, 'no prompt leakage');
    }

    public function test_envelope_hash_is_byte_identical_for_same_input(): void
    {
        $b = new AtlasNativeWorkerExecutionEnvelopeBuilder;
        $a = $b->build($this->validPacket());
        $c = $b->build($this->validPacket());
        $this->assertSame($a['envelope_hash'], $c['envelope_hash']);
        $this->assertSame(64, strlen($a['envelope_hash']));
    }

    public function test_different_objective_yields_different_envelope_hash(): void
    {
        $b = new AtlasNativeWorkerExecutionEnvelopeBuilder;
        $p1 = $this->validPacket();
        $p2 = $this->validPacket();
        $p2['objective'] = 'something else';
        $this->assertNotSame($b->build($p1)['envelope_hash'], $b->build($p2)['envelope_hash']);
    }

    public function test_scope_in_falls_back_to_allowed_files_when_absent(): void
    {
        $p = $this->validPacket();
        unset($p['scope_in']);
        $e = (new AtlasNativeWorkerExecutionEnvelopeBuilder)->build($p);

        $this->assertSame($e['allowed_files'], $e['scope_in']);
    }

    public function test_rollback_plan_defaults_to_revert_commit_when_absent(): void
    {
        $p = $this->validPacket();
        unset($p['rollback_plan']);
        $e = (new AtlasNativeWorkerExecutionEnvelopeBuilder)->build($p);

        $this->assertSame(['mode' => 'revert_commit'], $e['rollback_plan']);
    }

    public function test_evidence_template_defaults_to_null_map_over_required_evidence(): void
    {
        $p = $this->validPacket();
        unset($p['evidence_template']);
        $e = (new AtlasNativeWorkerExecutionEnvelopeBuilder)->build($p);

        $this->assertSame(['test_run_id' => null, 'commit_sha' => null], $e['evidence_template']);
    }
}
