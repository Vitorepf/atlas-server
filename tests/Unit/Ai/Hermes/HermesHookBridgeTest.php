<?php

namespace Tests\Unit\Ai\Hermes;

use App\Models\AiJob;
use App\Services\Ai\Hermes\HermesHookBridge;
use Illuminate\Support\Str;
use Tests\TestCase;

class HermesHookBridgeTest extends TestCase
{
    private string $hermesHome;

    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hermesHome = sys_get_temp_dir().'/atlas-hermes-home-'.Str::random(12);
        $this->configPath = $this->hermesHome.'/config.yaml';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->hermesHome)) {
            @array_map('unlink', glob($this->hermesHome.'/*') ?: []);
            @rmdir($this->hermesHome);
        }

        parent::tearDown();
    }

    private function bridge(): HermesHookBridge
    {
        return app(HermesHookBridge::class);
    }

    /**
     * @return array<string,mixed>
     */
    private function manifest(?array $events = null): array
    {
        return [
            'hooks' => [
                'events' => $events ?? ['pre_tool_call', 'post_tool_call', 'agent:start', 'agent:step', 'agent:end', 'subagent_stop'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionContext(array $overrides = []): array
    {
        return array_merge([
            'trace_id' => 'trace-hook-001',
            'hermes_home' => $this->hermesHome,
            'consent' => ['accept_hooks' => true],
            'sink' => ['host' => '127.0.0.1', 'port' => 8421],
        ], $overrides);
    }

    private function job(string $traceId = 'trace-hook-001'): AiJob
    {
        return new AiJob([
            'trace_id' => $traceId,
            'payload' => ['hermes' => []],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mission(): array
    {
        return ['mission_id' => 'mission-1', 'mission_hash' => str_repeat('a', 64)];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $sessionOverrides
     * @return array<string,mixed>
     */
    private function register(string $hookPolicy, string $permissionMode, array $manifest, array $sessionOverrides = []): array
    {
        return $this->bridge()->register(
            $this->job(),
            $this->mission(),
            ['command' => ['hermes']],
            $hookPolicy,
            $permissionMode,
            $manifest,
            $this->sessionContext($sessionOverrides),
        );
    }

    public function test_off_by_default_writes_nothing_and_is_disabled(): void
    {
        $receipt = $this->register('off', 'write', $this->manifest());

        $this->assertSame('disabled', $receipt['status']);
        $this->assertFalse((bool) $receipt['hooks_registered']);
        $this->assertFalse((bool) $receipt['registration_allowed_now']);
        $this->assertFalse((bool) $receipt['interception_enabled']);
        $this->assertSame('atlas', $receipt['hook_authority']);
        $this->assertNotEmpty($receipt['receipt_hash']);
        $this->assertFileDoesNotExist($this->configPath);
    }

    public function test_permission_below_write_is_skipped_permission(): void
    {
        $receipt = $this->register('atlas_adapter', 'read', $this->manifest());

        $this->assertSame('skipped_permission', $receipt['status']);
        $this->assertFalse((bool) $receipt['registration_allowed_now']);
        $this->assertNotEmpty($receipt['receipt_hash']);
        $this->assertFileDoesNotExist($this->configPath);
    }

    public function test_missing_consent_is_skipped_no_consent(): void
    {
        $receipt = $this->register('atlas_adapter', 'write', $this->manifest(), ['consent' => []]);

        $this->assertSame('skipped_no_consent', $receipt['status']);
        $this->assertFalse((bool) $receipt['registration_allowed_now']);
        $this->assertNull($receipt['consent_basis']);
        $this->assertNotEmpty($receipt['receipt_hash']);
        $this->assertFileDoesNotExist($this->configPath);
    }

    public function test_enabled_registers_hooks_without_leaking_token(): void
    {
        $receipt = $this->register('atlas_adapter', 'write', $this->manifest());

        $this->assertSame('registered', $receipt['status']);
        $this->assertTrue((bool) $receipt['hooks_registered']);
        $this->assertTrue((bool) $receipt['registration_allowed_now']);
        $this->assertTrue((bool) $receipt['interception_enabled']);
        $this->assertSame('provider_accept_hooks', $receipt['consent_basis']);
        $this->assertNotNull($receipt['hermes_home_hash']);
        $this->assertNotSame($this->hermesHome, $receipt['hermes_home_hash']);

        $registeredEvents = collect($receipt['events'])
            ->filter(fn (array $e): bool => $e['registered'] === true)
            ->pluck('event')
            ->all();
        $this->assertContains('pre_tool_call', $registeredEvents);
        $this->assertContains('subagent_stop', $registeredEvents);

        $this->assertTrue((bool) $receipt['evidence_sink']['token_present']);
        $this->assertSame('atlas_local_loopback', $receipt['evidence_sink']['kind']);

        // The token value must never appear anywhere in the receipt.
        $expectedToken = substr(hash('sha256', 'hermes_hook_token|trace-hook-001'), 0, 48);
        $json = json_encode($receipt);
        $this->assertIsString($json);
        $this->assertStringNotContainsString($expectedToken, $json);
        // Nor the raw HERMES_HOME path.
        $this->assertStringNotContainsString($this->hermesHome, $json);

        // Config written with the managed markers and the loopback sink.
        $this->assertFileExists($this->configPath);
        $contents = (string) file_get_contents($this->configPath);
        $this->assertStringContainsString('# >>> atlas-hermes-hook-bridge >>>', $contents);
        $this->assertStringContainsString('# <<< atlas-hermes-hook-bridge <<<', $contents);
        $this->assertStringContainsString('pre_tool_call', $contents);
        $this->assertStringContainsString('X-Atlas-Hook-Token', $contents);
        $this->assertStringNotContainsString('hooks_auto_accept', $contents);
    }

    public function test_manifest_gating_skips_events_absent_from_manifest(): void
    {
        $receipt = $this->register('atlas_adapter', 'write', $this->manifest(['pre_tool_call', 'post_tool_call']));

        $this->assertSame('registered', $receipt['status']);

        $skipped = collect($receipt['skipped_events'])->keyBy('event');
        $this->assertTrue($skipped->has('agent:start'));
        $this->assertSame('not_in_manifest', $skipped->get('agent:start')['reason']);
        $this->assertTrue($skipped->has('subagent_stop'));

        $registeredEvents = collect($receipt['events'])
            ->filter(fn (array $e): bool => $e['registered'] === true)
            ->pluck('event')
            ->all();
        $this->assertSame(['pre_tool_call', 'post_tool_call'], $registeredEvents);
    }

    public function test_manifest_with_no_supported_events_is_manifest_unsupported(): void
    {
        $receipt = $this->register('atlas_adapter', 'write', $this->manifest([]));

        $this->assertSame('manifest_unsupported', $receipt['status']);
        $this->assertFalse((bool) $receipt['hooks_registered']);
        $this->assertFalse((bool) $receipt['registration_allowed_now']);
        $this->assertFileDoesNotExist($this->configPath);
        $this->assertNotEmpty($receipt['receipt_hash']);
    }

    public function test_revoke_strips_only_the_managed_block_and_preserves_operator_hooks(): void
    {
        // Operator authored their own hooks OUTSIDE the Atlas markers first.
        @mkdir($this->hermesHome, 0700, true);
        $operatorConfig = "hooks:\n  agent:end:\n    - matcher: '*'\n      command: \"echo operator-owned\"\n      timeout: 3\n";
        file_put_contents($this->configPath, $operatorConfig);

        $registered = $this->register('atlas_adapter', 'write', $this->manifest());
        $this->assertSame('registered', $registered['status']);

        $afterRegister = (string) file_get_contents($this->configPath);
        $this->assertStringContainsString('echo operator-owned', $afterRegister);
        $this->assertStringContainsString('# >>> atlas-hermes-hook-bridge >>>', $afterRegister);

        $revoked = $this->bridge()->revoke($this->job(), $this->sessionContext());

        $this->assertSame('revoked', $revoked['status']);
        $this->assertTrue((bool) $revoked['reversible']);
        $this->assertNotEmpty($revoked['receipt_hash']);

        $afterRevoke = (string) file_get_contents($this->configPath);
        $this->assertStringContainsString('echo operator-owned', $afterRevoke);
        $this->assertStringNotContainsString('# >>> atlas-hermes-hook-bridge >>>', $afterRevoke);
        $this->assertStringNotContainsString('# <<< atlas-hermes-hook-bridge <<<', $afterRevoke);
        $this->assertStringNotContainsString('X-Atlas-Hook-Token', $afterRevoke);
    }

    public function test_receipt_hash_is_sealed_and_deterministic_on_every_path(): void
    {
        foreach (['off', 'atlas_canonical'] as $policy) {
            $first = $this->register($policy, 'write', $this->manifest());
            $second = $this->register($policy, 'write', $this->manifest());
            $this->assertNotEmpty($first['receipt_hash']);
            $this->assertSame($first['receipt_hash'], $second['receipt_hash']);
            $this->assertSame('atlas.hermes.hook_bridge_receipt.v1', $first['schema_version']);
        }

        $skip = $this->register('atlas_adapter', 'read', $this->manifest());
        $this->assertNotEmpty($skip['receipt_hash']);
    }
}
