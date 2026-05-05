<?php

namespace Tests\Unit\Ai\Provider;

use App\Services\Ai\Kernel\Provider\ProviderRequestHasher;
use App\Services\Ai\Provider\Drivers\CodexCliProviderDriver;
use Tests\TestCase;

class ProviderRequestHasherTest extends TestCase
{
    public function test_hash_is_stable_for_reordered_associative_arrays(): void
    {
        $hasher = app(ProviderRequestHasher::class);

        $first = $hasher->hash('codex_cli', [
            'input' => 'hello',
            'metadata' => [
                'b' => 2,
                'a' => 1,
            ],
        ], [
            'trace_id' => 'trace-1',
            'identity_fragment' => [
                'content_hash' => 'hash',
                'identity_id' => 'identity',
            ],
        ]);

        $second = $hasher->hash('codex_cli', [
            'metadata' => [
                'a' => 1,
                'b' => 2,
            ],
            'input' => 'hello',
        ], [
            'identity_fragment' => [
                'identity_id' => 'identity',
                'content_hash' => 'hash',
            ],
            'trace_id' => 'trace-1',
        ]);

        $this->assertSame($first, $second);
    }

    public function test_hash_preserves_list_order(): void
    {
        $hasher = app(ProviderRequestHasher::class);

        $first = $hasher->hash('codex_cli', ['messages' => ['first', 'second']]);
        $second = $hasher->hash('codex_cli', ['messages' => ['second', 'first']]);

        $this->assertNotSame($first, $second);
    }

    public function test_prepared_request_hash_ignores_volatile_audit_fields(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $hasher = app(ProviderRequestHasher::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $mutated = $request;
        $mutated['audit']['identity_injected_at'] = now()->addMinute()->toJSON();
        $mutated['audit']['request_hash'] = str_repeat('b', 64);

        $this->assertSame(
            $hasher->hashPreparedRequest('codex_cli', $request),
            $hasher->hashPreparedRequest('codex_cli', $mutated),
        );
    }

    public function test_prepared_request_hash_covers_canonicalization_metadata(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $hasher = app(ProviderRequestHasher::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $mutated = $request;
        $mutated['audit']['request_hash_canonicalization'] = 'provider_prepared_request.v0';

        $this->assertNotSame(
            $hasher->hashPreparedRequest('codex_cli', $request),
            $hasher->hashPreparedRequest('codex_cli', $mutated),
        );
    }

    public function test_prepared_request_hash_covers_execution_policy(): void
    {
        $driver = app(CodexCliProviderDriver::class);
        $hasher = app(ProviderRequestHasher::class);
        $request = $driver->prepareRequest(['input' => 'hello']);
        $mutated = $request;
        $mutated['execution_policy']['mode'] = 'mutated';

        $this->assertNotSame(
            $hasher->hashPreparedRequest('codex_cli', $request),
            $hasher->hashPreparedRequest('codex_cli', $mutated),
        );
    }
}
