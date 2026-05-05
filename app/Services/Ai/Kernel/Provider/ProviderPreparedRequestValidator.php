<?php

namespace App\Services\Ai\Kernel\Provider;

class ProviderPreparedRequestValidator
{
    public function __construct(
        private readonly ProviderRequestHasher $hasher,
    ) {}

    /**
     * @param  array<string,mixed>  $request
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public function validate(ProviderDriver $driver, array $request): array
    {
        $errors = [];
        $warnings = [];
        $identity = $driver->identityFragment();

        if (($request['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version_must_be_1';
        }

        if (($request['provider_driver'] ?? null) !== $driver::class) {
            $errors[] = 'provider_driver_mismatch';
        }

        if (($request['provider_id'] ?? null) !== $driver->providerId()) {
            $errors[] = 'provider_id_mismatch';
        }

        if (($request['status'] ?? null) !== ProviderPreparedRequestStatus::Prepared->value) {
            $errors[] = 'request_status_must_be_prepared';
        }

        if (data_get($request, 'execution_policy.provider_real_execution_allowed') !== false) {
            $errors[] = 'provider_real_execution_must_be_disabled';
        }

        $mode = data_get($request, 'execution_policy.mode');
        if (! is_string($mode) || trim($mode) === '') {
            $errors[] = 'execution_policy_mode_required';
        } elseif ($mode !== 'prepare_only') {
            $errors[] = 'execution_policy_mode_unsupported';
        }

        if (! array_key_exists('dry_run', (array) ($request['execution_policy'] ?? []))) {
            $errors[] = 'execution_policy_dry_run_required';
        } elseif (! is_bool(data_get($request, 'execution_policy.dry_run'))) {
            $errors[] = 'execution_policy_dry_run_must_be_boolean';
        }

        $legacyDelegate = data_get($request, 'execution_policy.delegates_to_legacy_provider');
        if (! is_string($legacyDelegate) || trim($legacyDelegate) === '') {
            $errors[] = 'execution_policy_delegate_required';
        } elseif ($legacyDelegate !== $driver->legacyProviderClass()) {
            $errors[] = 'execution_policy_delegate_mismatch';
        }

        $requestHash = data_get($request, 'audit.request_hash');
        if (! is_string($requestHash) || trim($requestHash) === '') {
            $errors[] = 'audit_request_hash_required';
        } elseif (! preg_match('/\A[a-f0-9]{64}\z/', $requestHash)) {
            $errors[] = 'audit_request_hash_invalid';
        } elseif ($requestHash !== $this->expectedRequestHash($driver, $request)) {
            $errors[] = 'audit_request_hash_mismatch';
        }

        if (data_get($request, 'audit.request_hash_algorithm') !== ProviderRequestHasher::HASH_ALGORITHM) {
            $errors[] = 'audit_request_hash_algorithm_mismatch';
        }

        if (data_get($request, 'audit.request_hash_canonicalization') !== ProviderRequestHasher::PREPARED_REQUEST_CANONICALIZATION) {
            $errors[] = 'audit_request_hash_canonicalization_mismatch';
        }

        if (data_get($request, 'payload.identity_fragment.identity_id') !== $identity->identityId) {
            $errors[] = 'identity_fragment_id_mismatch';
        }

        if (data_get($request, 'payload.identity_fragment.content_hash') !== $identity->contentHash) {
            $errors[] = 'identity_fragment_hash_mismatch';
        }

        $identityText = data_get($request, 'payload.identity_fragment.text');
        if (! is_string($identityText) || hash('sha256', $identityText) !== (string) data_get($request, 'payload.identity_fragment.content_hash')) {
            $errors[] = 'identity_fragment_text_hash_invalid';
        }

        if (data_get($request, 'audit.identity_fragment_hash') !== $identity->contentHash) {
            $errors[] = 'audit_identity_hash_mismatch';
        }

        if (data_get($request, 'audit.identity_fragment_id') !== $identity->identityId) {
            $errors[] = 'audit_identity_id_mismatch';
        }

        $model = $request['model'] ?? null;
        if (! is_string($model) || trim($model) === '') {
            $errors[] = 'model_required';
        } elseif (! in_array($model, $driver->supportedModels(), true)) {
            $warnings[] = 'model_not_declared_in_supported_models';
        }

        if (($request['supported_models'] ?? null) !== $driver->supportedModels()) {
            $errors[] = 'supported_models_mismatch';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     */
    private function expectedRequestHash(ProviderDriver $driver, array $request): string
    {
        return $this->hasher->hashPreparedRequest($driver->providerId(), $request);
    }
}
