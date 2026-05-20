<?php

namespace App\Services\Ai\Provider\Drivers;

use App\Services\Ai\Kernel\Provider\ProviderDriver;
use App\Services\Ai\Kernel\Provider\ProviderPreparedRequestValidator;
use InvalidArgumentException;

class ProviderDriverRegistry
{
    /**
     * @var array<int,class-string<ProviderDriver>>
     */
    private const DRIVER_CLASSES = [
        ClaudeCliProviderDriver::class,
        CodexCliProviderDriver::class,
        GeminiCliProviderDriver::class,
        ClaudeCodexCouncilProviderDriver::class,
        JarvisMlxProviderDriver::class,
    ];

    public function __construct(
        private readonly ProviderPreparedRequestValidator $validator,
    ) {}

    /**
     * @return array<int,ProviderDriver>
     */
    public function all(): array
    {
        return array_map(
            fn (string $class): ProviderDriver => app($class),
            self::DRIVER_CLASSES,
        );
    }

    /**
     * @return array<int,class-string<ProviderDriver>>
     */
    public function driverClasses(): array
    {
        return self::DRIVER_CLASSES;
    }

    public function get(string $providerId): ProviderDriver
    {
        foreach ($this->all() as $driver) {
            if ($driver->providerId() === $providerId) {
                return $driver;
            }
        }

        throw new InvalidArgumentException("Unsupported provider driver [{$providerId}].");
    }

    /**
     * @return array<int,string>
     */
    public function providerIds(): array
    {
        return array_map(
            fn (ProviderDriver $driver): string => $driver->providerId(),
            $this->all(),
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function manifest(): array
    {
        return array_map(function (ProviderDriver $driver): array {
            $identity = $driver->identityFragment();
            $prepared = $driver->prepareRequest([
                'input' => 'provider driver registry manifest probe',
            ], [
                'dry_run' => true,
            ]);
            $validation = $this->validator->validate($driver, $prepared);

            return [
                'provider_id' => $driver->providerId(),
                'driver_class' => $driver::class,
                'supported_models' => $driver->supportedModels(),
                'identity_fragment_id' => $identity->identityId,
                'identity_fragment_hash' => $identity->contentHash,
                'legacy_provider' => $driver->legacyProviderClass(),
                'execution_policy_mode' => data_get($prepared, 'execution_policy.mode'),
                'request_hash_algorithm' => data_get($prepared, 'audit.request_hash_algorithm'),
                'request_hash_canonicalization' => data_get($prepared, 'audit.request_hash_canonicalization'),
                'dry_run_supported' => data_get($prepared, 'execution_policy.dry_run') === true,
                'real_execution_enabled' => data_get($prepared, 'execution_policy.provider_real_execution_allowed') === true,
                'validation' => $validation,
            ];
        }, $this->all());
    }

    /**
     * @return array{ok:bool,count:int,providers:array<int,string>,errors:array<int,string>,warnings:array<int,string>}
     */
    public function complianceReport(): array
    {
        $errors = [];
        $warnings = [];
        $providers = [];
        $seen = [];

        foreach (self::DRIVER_CLASSES as $class) {
            if (! is_subclass_of($class, ProviderDriver::class)) {
                $errors[] = "{$class} must implement ProviderDriver";

                continue;
            }

            $driver = app($class);
            $providers[] = $driver->providerId();

            if (isset($seen[$driver->providerId()])) {
                $errors[] = "{$driver->providerId()} providerId is duplicated";
            }
            $seen[$driver->providerId()] = true;

            if ($driver->supportedModels() === []) {
                $errors[] = "{$driver->providerId()} supportedModels is empty";
            }

            $identity = $driver->identityFragment();
            if (trim($identity->contentHash) === '') {
                $errors[] = "{$driver->providerId()} identityFragment contentHash is empty";
            }
            if (! in_array($identity->metadata['source'] ?? null, ['atlas_ai_master_prompt_projection', 'atlas_ai_master_prompt_fallback'], true)) {
                $errors[] = "{$driver->providerId()} identityFragment source is not canonical";
            }
            if (! str_contains($identity->text, 'provider is an execution engine')) {
                $errors[] = "{$driver->providerId()} identityFragment must state provider engine invariant";
            }
            if (($identity->metadata['fallback'] ?? false) === true) {
                $warnings[] = "{$driver->providerId()} identityFragment is using fallback master identity projection";
            }

            $prepared = $driver->prepareRequest(['input' => 'provider driver compliance probe']);
            $validation = $this->validator->validate($driver, $prepared);
            if (! $validation['ok']) {
                foreach ($validation['errors'] as $error) {
                    $errors[] = "{$driver->providerId()} {$error}";
                }
            }

            $execution = $driver->execute($prepared);
            if (($execution['executed'] ?? null) !== false || ($execution['provider_real_execution_called'] ?? null) !== false) {
                $errors[] = "{$driver->providerId()} execute must not call real provider during wrapper stage";
            }
        }

        return [
            'ok' => $errors === [],
            'count' => count($providers),
            'providers' => $providers,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
