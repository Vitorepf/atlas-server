<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerClaimEnvelopeAdapter;
use Tests\TestCase;

class AtlasNativeWorkerClaimEnvelopeAdapterTest extends TestCase
{
    private function validClaim(array $packetOverride = []): array
    {
        return [
            'lease_id' => 'lease_abc',
            'task_packet' => array_replace([
                'task_packet_id' => 'pk-1',
                'objective' => 'do work',
                'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
                'scope_in' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
                'acceptance_criteria' => ['noop'],
                'required_evidence' => ['tests_or_gates_result'],
                'simplicity_contract' => [
                    'final_runtime_owner' => 'atlas_native',
                    'steady_state_runtime_owner' => 'atlas_server',
                    'operator_dependency_allowed' => false,
                    'human_dependency_allowed' => false,
                    'external_provider_dependency_allowed' => false,
                ],
            ], $packetOverride),
        ];
    }

    public function test_valid_claim_produces_normalized_envelope(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim());

        self::assertTrue($verdict['ok']);
        self::assertSame('ok', $verdict['reason']);
        $normalized = $verdict['normalized_packet'];
        self::assertSame('pk-1', $normalized['task_packet_id']);
        self::assertSame('lease_abc', $normalized['lease_id']);
        self::assertSame('do work', $normalized['objective']);
        self::assertSame(['app/Foo.php', 'tests/Unit/FooTest.php'], $normalized['allowed_files']);
        self::assertContains('tests_or_gates_result', $normalized['required_evidence']);
        self::assertSame('atlas_native', $normalized['simplicity_contract']);
        self::assertSame('atlas_native', $normalized['final_runtime_owner']);
        self::assertSame('atlas_server', $normalized['steady_state_runtime_owner']);
        self::assertStringStartsWith('adapter_', $verdict['adapter_hash']);
    }

    public function test_missing_lease_fails_closed(): void
    {
        $claim = $this->validClaim();
        unset($claim['lease_id']);

        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($claim);

        self::assertFalse($verdict['ok']);
        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_LEASE_MISSING, $verdict['reason']);
        self::assertNull($verdict['normalized_packet']);
    }

    public function test_empty_allowed_files_fails_closed(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim(['allowed_files' => []]));

        self::assertFalse($verdict['ok']);
        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_ALLOWED_FILES_EMPTY, $verdict['reason']);
    }

    public function test_empty_acceptance_fails_closed(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim(['acceptance_criteria' => []]));

        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_ACCEPTANCE_EMPTY, $verdict['reason']);
    }

    public function test_empty_evidence_fails_closed(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim(['required_evidence' => []]));

        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_EVIDENCE_EMPTY, $verdict['reason']);
    }

    public function test_worker_executable_false_fails_closed(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim(['worker_executable' => false]));

        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_NOT_WORKER_EXECUTABLE, $verdict['reason']);
    }

    public function test_operator_handoff_required_fails_closed(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim(['operator_handoff_required' => true]));

        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_OPERATOR_HANDOFF, $verdict['reason']);
    }

    public function test_non_atlas_native_owner_fails_closed(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim([
            'simplicity_contract' => [
                'final_runtime_owner' => 'external_assistant',
                'steady_state_runtime_owner' => 'atlas_server',
            ],
        ]));

        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_NON_NATIVE_OWNER, $verdict['reason']);
    }

    public function test_operator_dependency_fails_closed(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim([
            'simplicity_contract' => [
                'final_runtime_owner' => 'atlas_native',
                'steady_state_runtime_owner' => 'atlas_server',
                'operator_dependency_allowed' => true,
            ],
        ]));

        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_OPERATOR_DEPENDENCY, $verdict['reason']);
    }

    public function test_human_dependency_fails_closed(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim([
            'simplicity_contract' => [
                'final_runtime_owner' => 'atlas_native',
                'steady_state_runtime_owner' => 'atlas_server',
                'human_dependency_allowed' => true,
            ],
        ]));

        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_HUMAN_DEPENDENCY, $verdict['reason']);
    }

    public function test_external_provider_dependency_fails_closed(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim([
            'simplicity_contract' => [
                'final_runtime_owner' => 'atlas_native',
                'steady_state_runtime_owner' => 'atlas_server',
                'external_provider_dependency_allowed' => true,
            ],
        ]));

        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_EXTERNAL_PROVIDER_DEPENDENCY, $verdict['reason']);
    }

    public function test_serving_envelope_shape_is_supported(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt([
            'task' => [
                'lease_id' => 'l1',
                'task_packet' => [
                    'task_packet_id' => 'pk-2',
                    'objective' => 'noop',
                    'allowed_files' => ['app/X.php', 'tests/XTest.php'],
                    'scope_in' => ['app/X.php', 'tests/XTest.php'],
                    'acceptance_criteria' => ['noop'],
                    'required_evidence' => ['tests_or_gates_result'],
                    'simplicity_contract' => ['final_runtime_owner' => 'atlas_native', 'steady_state_runtime_owner' => 'atlas_server'],
                ],
            ],
        ]);

        self::assertTrue($verdict['ok']);
        self::assertSame('pk-2', $verdict['normalized_packet']['task_packet_id']);
    }

    public function test_adapter_hash_is_deterministic(): void
    {
        $adapter = new AtlasNativeWorkerClaimEnvelopeAdapter();
        $a = $adapter->adapt($this->validClaim());
        $b = $adapter->adapt($this->validClaim());

        self::assertSame($a['adapter_hash'], $b['adapter_hash']);
    }

    public function test_test_only_allowed_files_fails_closed(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim([
            'allowed_files' => ['tests/Unit/FooTest.php', 'tests/Unit/BarTest.php'],
            'scope_in' => ['tests/Unit/FooTest.php', 'tests/Unit/BarTest.php'],
        ]));

        self::assertFalse($verdict['ok']);
        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_TEST_ONLY_SCOPE, $verdict['reason']);
    }

    public function test_impl_only_without_runnable_proof_fails_closed(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim([
            'allowed_files' => ['app/Foo.php', 'app/Bar.php'],
            'scope_in' => ['app/Foo.php', 'app/Bar.php'],
            'acceptance_criteria' => ['do the thing', 'verify manually'],
        ]));

        self::assertFalse($verdict['ok']);
        self::assertSame(AtlasNativeWorkerClaimEnvelopeAdapter::REASON_NO_RUNNABLE_PROOF, $verdict['reason']);
    }

    public function test_impl_only_with_artisan_command_in_acceptance_passes(): void
    {
        $verdict = (new AtlasNativeWorkerClaimEnvelopeAdapter)->adapt($this->validClaim([
            'allowed_files' => ['app/Foo.php', 'app/Bar.php'],
            'scope_in' => ['app/Foo.php', 'app/Bar.php'],
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php passes'],
        ]));

        self::assertTrue($verdict['ok']);
        self::assertSame('ok', $verdict['reason']);
    }

    public function test_adapter_source_does_not_call_providers_or_processes(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimEnvelopeAdapter.php'));
        foreach (['shell_exec', 'exec(', 'system(', 'proc_open', '`git ', 'Http::', 'curl_', 'DB::', 'Storage::', 'file_put_contents'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "adapter must not contain {$forbidden}");
        }
    }
}
