<?php

namespace Tests\Unit\Ai\Provider;

use App\Services\Ai\Kernel\Provider\ProviderDriver;
use App\Services\Ai\Kernel\Provider\ProviderDriverExecutionStatus;
use App\Services\Ai\Kernel\Provider\ProviderPreparedRequestStatus;
use App\Services\Ai\Kernel\Provider\ProviderRequestHasher;
use App\Services\Ai\Provider\Drivers\ClaudeCliProviderDriver;
use App\Services\Ai\Provider\Drivers\ClaudeCodexCouncilProviderDriver;
use App\Services\Ai\Provider\Drivers\CodexCliProviderDriver;
use App\Services\Ai\Provider\Drivers\GeminiCliProviderDriver;
use App\Services\Ai\Provider\Drivers\ProviderDriverRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderDriverWrappersTest extends TestCase
{
    /**
     * @return array<string,array{0:class-string<ProviderDriver>,1:string}>
     */
    public static function drivers(): array
    {
        return [
            'claude cli' => [ClaudeCliProviderDriver::class, 'claude_cli'],
            'codex cli' => [CodexCliProviderDriver::class, 'codex_cli'],
            'gemini cli' => [GeminiCliProviderDriver::class, 'gemini_cli'],
            'claude codex council' => [ClaudeCodexCouncilProviderDriver::class, 'claude_codex'],
        ];
    }

    /**
     * @param  class-string<ProviderDriver>  $driverClass
     */
    #[DataProvider('drivers')]
    public function test_driver_implements_provider_driver_contract(string $driverClass, string $providerId): void
    {
        $driver = app($driverClass);

        $this->assertInstanceOf(ProviderDriver::class, $driver);
        $this->assertSame($providerId, $driver->providerId());
        $this->assertNotEmpty($driver->supportedModels());
        $this->assertNotEmpty($driver->legacyProviderClass());
    }

    /**
     * @param  class-string<ProviderDriver>  $driverClass
     */
    #[DataProvider('drivers')]
    public function test_identity_fragment_is_stable_and_hashable(string $driverClass, string $providerId): void
    {
        $driver = app($driverClass);
        $first = $driver->identityFragment();
        $second = $driver->identityFragment();

        $this->assertSame('atlas-ai.provider.'.$providerId.'.identity.v1', $first->identityId);
        $this->assertSame($first->contentHash, $second->contentHash);
        $this->assertSame(hash('sha256', $first->text), $first->contentHash);
        $this->assertNotSame('', trim($first->text));
        $this->assertContains(data_get($first->metadata, 'source'), [
            'atlas_ai_master_prompt_projection',
            'atlas_ai_master_prompt_fallback',
        ]);
        $this->assertSame($providerId, data_get($first->metadata, 'provider_id'));
        $this->assertStringContainsString('Provider: '.$providerId, $first->text);
        $this->assertStringContainsString('provider is an execution engine', $first->text);
        $this->assertStringContainsString('Atlas AI Agent Behavior Contract v1', $first->text);
        $this->assertStringContainsString('Surgical Diff Discipline', $first->text);
        $this->assertStringContainsString('Verifiable Goal Loop', $first->text);
        $this->assertSame('atlas-ai.agent-behavior.v1', data_get($first->metadata, 'agent_behavior_contract.contract_id'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($first->metadata, 'agent_behavior_contract.content_hash'));
        $this->assertSame($first->contentHash, $first->toArray()['content_hash']);
    }

    /**
     * @param  class-string<ProviderDriver>  $driverClass
     */
    #[DataProvider('drivers')]
    public function test_prepare_request_injects_identity_fragment_into_payload(string $driverClass, string $providerId): void
    {
        $driver = app($driverClass);
        $prepared = $driver->prepareRequest([
            'model' => $driver->supportedModels()[0],
            'input' => 'Summarize the Atlas provider contract.',
            'payload' => [
                'trace_id' => 'trace-123',
            ],
        ], [
            'surface' => 'unit_test',
        ]);

        $identity = $driver->identityFragment();

        $this->assertSame(1, $prepared['schema_version']);
        $this->assertSame($providerId, $prepared['provider_id']);
        $this->assertSame(ProviderPreparedRequestStatus::Prepared->value, $prepared['status']);
        $this->assertSame('trace-123', data_get($prepared, 'payload.trace_id'));
        $this->assertSame($identity->identityId, data_get($prepared, 'payload.identity_fragment.identity_id'));
        $this->assertSame($identity->contentHash, data_get($prepared, 'payload.identity_fragment.content_hash'));
        $this->assertSame($identity->text, data_get($prepared, 'payload.identity_fragment.text'));
        $this->assertSame('atlas-ai.agent-behavior.v1', data_get($prepared, 'payload.identity_fragment.metadata.agent_behavior_contract.contract_id'));
        $this->assertContains(data_get($prepared, 'payload.identity_fragment.metadata.source'), [
            'atlas_ai_master_prompt_projection',
            'atlas_ai_master_prompt_fallback',
        ]);
        $this->assertSame($identity->contentHash, data_get($prepared, 'audit.identity_fragment_hash'));
        $this->assertNotEmpty(data_get($prepared, 'audit.request_hash'));
        $this->assertSame(ProviderRequestHasher::HASH_ALGORITHM, data_get($prepared, 'audit.request_hash_algorithm'));
        $this->assertSame(ProviderRequestHasher::PREPARED_REQUEST_CANONICALIZATION, data_get($prepared, 'audit.request_hash_canonicalization'));
        $this->assertFalse((bool) data_get($prepared, 'execution_policy.provider_real_execution_allowed'));
        $this->assertFalse((bool) data_get($prepared, 'execution_policy.dry_run'));
    }

    /**
     * @param  class-string<ProviderDriver>  $driverClass
     */
    #[DataProvider('drivers')]
    public function test_execute_does_not_call_real_provider(string $driverClass, string $providerId): void
    {
        $driver = app($driverClass);
        $result = $driver->execute($driver->prepareRequest(['input' => 'hello']));

        $this->assertSame($providerId, $result['provider_id']);
        $this->assertSame(ProviderDriverExecutionStatus::DelegatesToLegacyProvider->value, $result['status']);
        $this->assertFalse($result['executed']);
        $this->assertFalse($result['provider_real_execution_called']);
        $this->assertNotEmpty($result['legacy_provider']);
        $this->assertNotEmpty($result['request_hash']);
        $this->assertSame($result['request_hash'], data_get($result, 'audit.request_hash'));
        $this->assertFalse((bool) data_get($result, 'audit.provider_real_execution_called'));
        $this->assertFalse((bool) data_get($result, 'metadata.execution_plan.dry_run'));
    }

    public function test_dry_run_request_never_reaches_legacy_boundary(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $result = $driver->execute($driver->prepareRequest([
            'input' => 'hello',
            'execution_policy' => [
                'dry_run' => true,
            ],
        ]));

        $this->assertSame(ProviderDriverExecutionStatus::DryRun->value, $result['status']);
        $this->assertFalse($result['executed']);
        $this->assertFalse($result['provider_real_execution_called']);
        $this->assertTrue((bool) data_get($result, 'audit.dry_run'));
        $this->assertFalse((bool) data_get($result, 'metadata.execution_plan.can_reach_legacy_boundary'));
        $this->assertSame('prepared_request', data_get($result, 'metadata.execution_plan.dry_run_source'));
    }

    public function test_prepare_request_does_not_coerce_string_dry_run_to_true(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $falseString = $driver->prepareRequest([
            'input' => 'hello',
            'execution_policy' => [
                'dry_run' => 'false',
            ],
        ]);
        $trueString = $driver->prepareRequest([
            'input' => 'hello',
            'execution_policy' => [
                'dry_run' => 'true',
            ],
        ]);

        $this->assertFalse(data_get($falseString, 'execution_policy.dry_run'));
        $this->assertFalse(data_get($trueString, 'execution_policy.dry_run'));
    }

    public function test_context_dry_run_never_reaches_legacy_boundary(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $result = $driver->execute($driver->prepareRequest([
            'input' => 'hello',
        ]), [
            'dry_run' => true,
        ]);

        $this->assertSame(ProviderDriverExecutionStatus::DryRun->value, $result['status']);
        $this->assertFalse($result['executed']);
        $this->assertFalse($result['provider_real_execution_called']);
        $this->assertTrue((bool) data_get($result, 'audit.dry_run'));
        $this->assertSame('context', data_get($result, 'metadata.execution_plan.dry_run_source'));
    }

    public function test_context_cannot_downgrade_prepared_dry_run_request(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $result = $driver->execute($driver->prepareRequest([
            'input' => 'hello',
            'execution_policy' => [
                'dry_run' => true,
            ],
        ]), [
            'dry_run' => false,
        ]);

        $this->assertSame(ProviderDriverExecutionStatus::DryRun->value, $result['status']);
        $this->assertFalse($result['executed']);
        $this->assertFalse($result['provider_real_execution_called']);
        $this->assertTrue((bool) data_get($result, 'metadata.execution_plan.dry_run'));
        $this->assertSame('prepared_request', data_get($result, 'metadata.execution_plan.dry_run_source'));
    }

    public function test_execute_refuses_unprepared_request(): void
    {
        $result = app(CodexCliProviderDriver::class)->execute([
            'provider_id' => 'codex_cli',
            'input' => 'raw request',
        ]);

        $this->assertSame(ProviderDriverExecutionStatus::NotExecuted->value, $result['status']);
        $this->assertFalse($result['executed']);
        $this->assertFalse($result['provider_real_execution_called']);
        $this->assertContains('request_status_must_be_prepared', $result['errors']);
        $this->assertContains('identity_fragment_hash_mismatch', $result['errors']);
        $this->assertContains('audit_request_hash_required', $result['errors']);
    }

    public function test_execute_refuses_tampered_prepared_request_hash(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest([
            'input' => 'hello',
            'payload' => [
                'trace_id' => 'trace-1',
            ],
        ]);
        $request['payload']['trace_id'] = 'tampered-trace';

        $result = $driver->execute($request);

        $this->assertSame(ProviderDriverExecutionStatus::NotExecuted->value, $result['status']);
        $this->assertFalse($result['executed']);
        $this->assertFalse($result['provider_real_execution_called']);
        $this->assertContains('audit_request_hash_mismatch', $result['errors']);
        $this->assertFalse((bool) data_get($result, 'metadata.execution_plan.can_reach_legacy_boundary'));
    }

    public function test_execute_keeps_future_model_as_warning_without_real_execution(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $result = $driver->execute($driver->prepareRequest([
            'model' => 'future-codex-model',
            'input' => 'hello',
        ]));

        $this->assertSame(ProviderDriverExecutionStatus::DelegatesToLegacyProvider->value, $result['status']);
        $this->assertFalse($result['executed']);
        $this->assertContains('model_not_declared_in_supported_models', $result['warnings']);
    }

    public function test_registry_exposes_provider_driver_wrappers_without_global_plugging(): void
    {
        $registry = app(ProviderDriverRegistry::class);

        $this->assertSame(['claude_cli', 'codex_cli', 'gemini_cli', 'claude_codex'], $registry->providerIds());
        $this->assertInstanceOf(ClaudeCliProviderDriver::class, $registry->get('claude_cli'));
        $this->assertInstanceOf(CodexCliProviderDriver::class, $registry->get('codex_cli'));
        $this->assertInstanceOf(GeminiCliProviderDriver::class, $registry->get('gemini_cli'));
        $this->assertInstanceOf(ClaudeCodexCouncilProviderDriver::class, $registry->get('claude_codex'));

        $report = $registry->complianceReport();
        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertSame(4, $report['count']);
        $this->assertIsArray($report['warnings']);
        $this->assertSame([
            ClaudeCliProviderDriver::class,
            CodexCliProviderDriver::class,
            GeminiCliProviderDriver::class,
            ClaudeCodexCouncilProviderDriver::class,
        ], $registry->driverClasses());
    }

    public function test_registry_manifest_is_auditable_without_real_execution(): void
    {
        $manifest = app(ProviderDriverRegistry::class)->manifest();

        $this->assertCount(4, $manifest);
        $this->assertSame(['claude_cli', 'codex_cli', 'gemini_cli', 'claude_codex'], array_column($manifest, 'provider_id'));

        foreach ($manifest as $entry) {
            $this->assertNotEmpty($entry['driver_class']);
            $this->assertNotEmpty($entry['supported_models']);
            $this->assertNotEmpty($entry['identity_fragment_id']);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $entry['identity_fragment_hash']);
            $this->assertNotEmpty($entry['legacy_provider']);
            $this->assertSame('prepare_only', $entry['execution_policy_mode']);
            $this->assertSame(ProviderRequestHasher::HASH_ALGORITHM, $entry['request_hash_algorithm']);
            $this->assertSame(ProviderRequestHasher::PREPARED_REQUEST_CANONICALIZATION, $entry['request_hash_canonicalization']);
            $this->assertTrue($entry['dry_run_supported']);
            $this->assertFalse($entry['real_execution_enabled']);
            $this->assertTrue(data_get($entry, 'validation.ok'));
            $this->assertSame([], data_get($entry, 'validation.errors'));
        }
    }

    public function test_registry_rejects_unknown_provider_driver(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProviderDriverRegistry::class)->get('openai_http');
    }

    public function test_prepare_request_preserves_scalar_payload_auditably(): void
    {
        $prepared = app(CodexCliProviderDriver::class)->prepareRequest([
            'input' => 'hello',
            'payload' => 'legacy-string-payload',
            'execution_policy' => [
                'dry_run' => 'true',
            ],
        ]);

        $this->assertSame('legacy-string-payload', data_get($prepared, 'payload.raw_payload'));
        $this->assertNotEmpty(data_get($prepared, 'payload.identity_fragment.content_hash'));
        $this->assertArrayNotHasKey('payload', $prepared['prompt']);
        $this->assertArrayNotHasKey('execution_policy', $prepared['prompt']);
        $this->assertFalse(data_get($prepared, 'execution_policy.dry_run'));
    }

    public function test_prepare_request_hash_is_canonical_for_equivalent_payloads(): void
    {
        $driver = app(CodexCliProviderDriver::class);

        $first = $driver->prepareRequest([
            'input' => 'hello',
            'payload' => [
                'b' => 2,
                'a' => 1,
            ],
        ]);

        $second = $driver->prepareRequest([
            'payload' => [
                'a' => 1,
                'b' => 2,
            ],
            'input' => 'hello',
        ]);

        $this->assertSame(data_get($first, 'audit.request_hash'), data_get($second, 'audit.request_hash'));
    }

    public function test_council_driver_preserves_identity_for_subproviders(): void
    {
        $prepared = app(ClaudeCodexCouncilProviderDriver::class)->prepareRequest([
            'input' => 'council',
        ]);

        $this->assertSame('claude_cli', data_get($prepared, 'payload.council_subproviders.0.provider_id'));
        $this->assertSame('codex_cli', data_get($prepared, 'payload.council_subproviders.1.provider_id'));
        $this->assertSame(
            app(ClaudeCliProviderDriver::class)->identityFragment()->contentHash,
            data_get($prepared, 'payload.council_subproviders.0.identity_fragment.content_hash'),
        );
        $this->assertSame(
            app(CodexCliProviderDriver::class)->identityFragment()->contentHash,
            data_get($prepared, 'payload.council_subproviders.1.identity_fragment.content_hash'),
        );
        $this->assertNotEmpty(data_get($prepared, 'audit.request_hash'));
    }
}
