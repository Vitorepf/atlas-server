<?php

namespace Tests\Unit\Ai\Provider;

use App\Services\Ai\Kernel\Provider\IdentityFragment;
use App\Services\Ai\Kernel\Provider\ProviderDriver;
use App\Services\Ai\Kernel\Provider\ProviderPreparedRequestValidator;
use App\Services\Ai\Kernel\Provider\ProviderRequestHasher;
use App\Services\Ai\Provider\Drivers\CodexCliProviderDriver;
use Tests\TestCase;

class ProviderPreparedRequestValidatorTest extends TestCase
{
    public function test_it_accepts_prepared_driver_request(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertTrue($result['ok'], implode("\n", $result['errors']));
        $this->assertSame([], $result['errors']);
    }

    public function test_it_rejects_unprepared_request(): void
    {
        $driver = app(CodexCliProviderDriver::class);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, [
            'provider_id' => 'codex_cli',
            'status' => 'raw',
            'model' => 'codex_cli_default',
            'payload' => [],
            'execution_policy' => [
                'provider_real_execution_allowed' => true,
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('request_status_must_be_prepared', $result['errors']);
        $this->assertContains('provider_real_execution_must_be_disabled', $result['errors']);
        $this->assertContains('identity_fragment_hash_mismatch', $result['errors']);
        $this->assertContains('execution_policy_dry_run_required', $result['errors']);
        $this->assertContains('execution_policy_delegate_required', $result['errors']);
        $this->assertContains('audit_request_hash_required', $result['errors']);
    }

    public function test_it_warns_for_undeclared_model_but_keeps_request_valid(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest([
            'model' => 'future-codex-model',
            'input' => 'hello',
        ]);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertTrue($result['ok'], implode("\n", $result['errors']));
        $this->assertContains('model_not_declared_in_supported_models', $result['warnings']);
    }

    public function test_it_rejects_prepared_request_without_audit_hash(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        unset($request['audit']['request_hash']);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('audit_request_hash_required', $result['errors']);
    }

    public function test_it_rejects_prepared_request_when_payload_changes_after_hashing(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest([
            'input' => 'hello',
            'payload' => [
                'trace_id' => 'trace-1',
            ],
        ]);
        $request['payload']['trace_id'] = 'tampered-trace';

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('audit_request_hash_mismatch', $result['errors']);
    }

    public function test_it_rejects_prepared_request_when_execution_policy_changes_after_hashing(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $request['execution_policy']['mode'] = 'mutated';

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('audit_request_hash_mismatch', $result['errors']);
        $this->assertContains('execution_policy_mode_unsupported', $result['errors']);
    }

    public function test_it_rejects_non_boolean_false_real_execution_flag_even_with_recomputed_hash(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $request['execution_policy']['provider_real_execution_allowed'] = '0';
        $request['audit']['request_hash'] = app(ProviderRequestHasher::class)
            ->hashPreparedRequest($driver->providerId(), $request);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('provider_real_execution_must_be_disabled', $result['errors']);
    }

    public function test_it_rejects_semantic_driver_drift_even_with_recomputed_hash(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $request['provider_driver'] = self::class;
        $request['audit']['request_hash'] = app(ProviderRequestHasher::class)
            ->hashPreparedRequest($driver->providerId(), $request);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('provider_driver_mismatch', $result['errors']);
    }

    public function test_it_rejects_supported_model_drift_even_with_recomputed_hash(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $request['supported_models'][] = 'shadow-model';
        $request['audit']['request_hash'] = app(ProviderRequestHasher::class)
            ->hashPreparedRequest($driver->providerId(), $request);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('supported_models_mismatch', $result['errors']);
    }

    public function test_it_rejects_missing_legacy_delegate_even_with_recomputed_hash(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        unset($request['execution_policy']['delegates_to_legacy_provider']);
        $request['audit']['request_hash'] = app(ProviderRequestHasher::class)
            ->hashPreparedRequest($driver->providerId(), $request);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('execution_policy_delegate_required', $result['errors']);
    }

    public function test_it_rejects_legacy_delegate_drift_even_with_recomputed_hash(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $request['execution_policy']['delegates_to_legacy_provider'] = self::class;
        $request['audit']['request_hash'] = app(ProviderRequestHasher::class)
            ->hashPreparedRequest($driver->providerId(), $request);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('execution_policy_delegate_mismatch', $result['errors']);
    }

    public function test_it_rejects_schema_version_drift_even_with_recomputed_hash(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $request['schema_version'] = 2;
        $request['audit']['request_hash'] = app(ProviderRequestHasher::class)
            ->hashPreparedRequest($driver->providerId(), $request);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('schema_version_must_be_1', $result['errors']);
    }

    public function test_it_rejects_audit_identity_id_drift_even_with_recomputed_hash(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $request['audit']['identity_fragment_id'] = 'atlas-ai.provider.shadow.identity.v1';
        $request['audit']['request_hash'] = app(ProviderRequestHasher::class)
            ->hashPreparedRequest($driver->providerId(), $request);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('audit_identity_id_mismatch', $result['errors']);
    }

    public function test_it_rejects_audit_hash_algorithm_drift_even_with_recomputed_hash(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $request['audit']['request_hash_algorithm'] = 'md5';
        $request['audit']['request_hash'] = app(ProviderRequestHasher::class)
            ->hashPreparedRequest($driver->providerId(), $request);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('audit_request_hash_algorithm_mismatch', $result['errors']);
    }

    public function test_it_rejects_audit_hash_canonicalization_drift_even_with_recomputed_hash(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $request['audit']['request_hash_canonicalization'] = 'provider_prepared_request.v0';
        $request['audit']['request_hash'] = app(ProviderRequestHasher::class)
            ->hashPreparedRequest($driver->providerId(), $request);

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, $request);

        $this->assertFalse($result['ok']);
        $this->assertContains('audit_request_hash_canonicalization_mismatch', $result['errors']);
    }

    public function test_it_rejects_driver_without_usable_identity(): void
    {
        $driver = new class implements ProviderDriver
        {
            public function providerId(): string
            {
                return 'codex_cli';
            }

            public function supportedModels(): array
            {
                return ['codex_cli_default'];
            }

            public function legacyProviderClass(): string
            {
                return self::class;
            }

            public function prepareRequest(array $prompt, array $context = []): array
            {
                return [];
            }

            public function execute(array $request, array $context = []): array
            {
                return [];
            }

            public function identityFragment(): IdentityFragment
            {
                return new IdentityFragment('', '', '');
            }
        };

        $result = app(ProviderPreparedRequestValidator::class)->validate($driver, [
            'provider_id' => 'codex_cli',
            'status' => 'prepared',
            'model' => 'codex_cli_default',
            'payload' => [
                'identity_fragment' => [
                    'identity_id' => '',
                    'content_hash' => '',
                    'text' => '',
                ],
            ],
            'execution_policy' => [
                'mode' => 'prepare_only',
                'provider_real_execution_allowed' => false,
                'dry_run' => false,
            ],
            'audit' => [
                'identity_fragment_hash' => '',
                'request_hash' => str_repeat('a', 64),
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('identity_fragment_text_hash_invalid', $result['errors']);
    }
}
