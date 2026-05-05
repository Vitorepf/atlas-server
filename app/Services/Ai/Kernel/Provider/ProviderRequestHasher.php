<?php

namespace App\Services\Ai\Kernel\Provider;

class ProviderRequestHasher
{
    public const HASH_ALGORITHM = 'sha256';

    public const PREPARED_REQUEST_CANONICALIZATION = 'provider_prepared_request.v1';

    /**
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $payload
     */
    public function hash(string $providerId, array $request, array $payload = []): string
    {
        return hash(self::HASH_ALGORITHM, json_encode($this->canonicalize([
            'provider_id' => $providerId,
            'request' => $request,
            'payload' => $payload,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $request
     */
    public function hashPreparedRequest(string $providerId, array $request): string
    {
        return hash(self::HASH_ALGORITHM, json_encode($this->canonicalize([
            'provider_id' => $providerId,
            'prepared_request' => [
                'schema_version' => $request['schema_version'] ?? null,
                'provider_driver' => $request['provider_driver'] ?? null,
                'provider_id' => $request['provider_id'] ?? null,
                'status' => $request['status'] ?? null,
                'execution_policy' => $request['execution_policy'] ?? null,
                'model' => $request['model'] ?? null,
                'supported_models' => $request['supported_models'] ?? null,
                'payload' => $request['payload'] ?? null,
                'prompt' => $request['prompt'] ?? null,
                'audit' => [
                    'identity_fragment_id' => data_get($request, 'audit.identity_fragment_id'),
                    'identity_fragment_hash' => data_get($request, 'audit.identity_fragment_hash'),
                    'request_hash_algorithm' => data_get($request, 'audit.request_hash_algorithm'),
                    'request_hash_canonicalization' => data_get($request, 'audit.request_hash_canonicalization'),
                ],
            ],
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function canonicalize(array $value): array
    {
        if ($this->isList($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->canonicalize($item) : $item,
                $value,
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
