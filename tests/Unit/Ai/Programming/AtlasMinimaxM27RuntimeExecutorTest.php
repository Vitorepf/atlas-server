<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeMinimaxM27InvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService;
use App\Services\Ai\Programming\AtlasMinimaxM27RuntimeExecutor;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Focused contract tests for AtlasMinimaxM27RuntimeExecutor (HTTP runtime executor).
 *
 * All HTTP interactions are intercepted via the setHttpFactory() seam or
 * Http::fake(); no real network calls are ever made.
 */
final class AtlasMinimaxM27RuntimeExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset to safe fail-closed defaults before every test.
        config()->set('atlas.ai.providers.minimax_m27', [
            'enabled'          => false,
            'auth_mode'        => 'token_plan_key',
            'token_plan_key'   => null,
            'paygo_enabled'    => false,
            'paygo_api_key'    => null,
            'model'            => 'MiniMax-M3',
            'allow_highspeed'  => false,
            'base_url'         => 'https://api.minimax.io',
            'timeout_seconds'  => 120,
            'max_output_tokens' => 8192,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an Illuminate\Http\Client\Response wrapping a real PSR-7 response.
     *
     * Http::response() returns a PromiseInterface, not a Response — it is only
     * useful inside Http::fake(). This helper produces a real Response object
     * that can be returned directly from a setHttpFactory() closure.
     *
     * @param  array<string,mixed>|string  $body
     */
    private function fakeResponse(array|string $body, int $status = 200): Response
    {
        if (is_array($body)) {
            $body = (string) json_encode($body);
            $headers = ['Content-Type' => ['application/json']];
        } else {
            $headers = [];
        }

        return new Response(new Psr7Response($status, $headers, $body));
    }

    // -------------------------------------------------------------------------
    // configured() — flag / key / paygo / model scenarios
    // -------------------------------------------------------------------------

    public function test_configured_returns_disabled_when_enabled_false(): void
    {
        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $status = $executor->configured();

        $this->assertSame('atlas.provider.minimax_m27.status.v1', $status['schema_version']);
        $this->assertSame(AtlasForgeMinimaxM27InvocationDriver::PROVIDER, $status['provider']);
        $this->assertFalse($status['configured']);
        $this->assertContains(AtlasMinimaxM27RuntimeExecutor::BLOCKER_DISABLED, $status['blockers']);
    }

    public function test_configured_returns_missing_token_plan_key_when_enabled_without_key(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', null);

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $status = $executor->configured();

        $this->assertFalse($status['configured']);
        $this->assertContains(AtlasMinimaxM27RuntimeExecutor::BLOCKER_MISSING_TOKEN_PLAN_KEY, $status['blockers']);
        $this->assertNotContains(AtlasMinimaxM27RuntimeExecutor::BLOCKER_DISABLED, $status['blockers']);
    }

    public function test_configured_returns_configured_when_token_plan_key_present(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-test-12345678');

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $status = $executor->configured();

        $this->assertTrue($status['configured']);
        $this->assertSame([], $status['blockers']);
        $this->assertTrue($status['external_provider_call_possible']);
        $this->assertTrue($status['provider_tokens_may_be_spent']);
    }

    public function test_configured_token_plan_key_not_exposed_in_output(): void
    {
        $rawKey = 'tpk-super-secret-key-9999';
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', $rawKey);

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $status = $executor->configured();

        // Serialize the whole status array to string and confirm raw key is absent.
        $serialized = json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($rawKey, (string) $serialized);
    }

    public function test_configured_paygo_disabled_by_default(): void
    {
        // Default setUp has paygo_enabled = false.
        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $status = $executor->configured();

        $this->assertFalse($status['paygo_enabled']);
    }

    public function test_configured_paygo_enabled_without_key_adds_blocker(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.auth_mode', 'paygo');
        config()->set('atlas.ai.providers.minimax_m27.paygo_enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.paygo_api_key', null);

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $status = $executor->configured();

        $this->assertFalse($status['configured']);
        $this->assertContains('missing_paygo_api_key', $status['blockers']);
    }

    public function test_configured_rejects_legacy_m27_model(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-test-12345678');
        config()->set('atlas.ai.providers.minimax_m27.model', 'MiniMax-M2.7');

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $status = $executor->configured();

        $this->assertFalse($status['configured']);
        $this->assertContains(AtlasMinimaxM27RuntimeExecutor::BLOCKER_MODEL_NOT_M3, $status['blockers']);
    }

    public function test_configured_highspeed_variant_blocked_by_default(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-test-12345678');
        config()->set('atlas.ai.providers.minimax_m27.model', 'MiniMax-M3-highspeed');
        config()->set('atlas.ai.providers.minimax_m27.allow_highspeed', false);

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $status = $executor->configured();

        $this->assertFalse($status['configured']);
        $this->assertContains(AtlasMinimaxM27RuntimeExecutor::BLOCKER_MODEL_NOT_M3, $status['blockers']);
        $this->assertContains(AtlasMinimaxM27RuntimeExecutor::BLOCKER_HIGHSPEED_NOT_AUTHORIZED, $status['blockers']);
    }

    public function test_configured_highspeed_variant_still_rejected_with_explicit_config(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-test-12345678');
        config()->set('atlas.ai.providers.minimax_m27.model', 'MiniMax-M3-highspeed');
        config()->set('atlas.ai.providers.minimax_m27.allow_highspeed', true);

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $status = $executor->configured();

        $this->assertFalse($status['configured']);
        $this->assertContains(AtlasMinimaxM27RuntimeExecutor::BLOCKER_MODEL_NOT_M3, $status['blockers']);
        $this->assertNotContains(AtlasMinimaxM27RuntimeExecutor::BLOCKER_HIGHSPEED_NOT_AUTHORIZED, $status['blockers']);
    }

    // -------------------------------------------------------------------------
    // plan() — never contacts network, returns correct schema
    // -------------------------------------------------------------------------

    public function test_plan_never_calls_network(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-test-12345678');

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);

        // Install a factory that fails the test if invoked.
        $executor->setHttpFactory(function () {
            $this->fail('plan() must not invoke the HTTP factory.');
        });

        $plan = $executor->plan([
            'model' => 'MiniMax-M3',
            'workspace' => ['path' => base_path()],
        ]);

        // If we reach here the factory was never called — assertion passes.
        $this->assertFalse($plan['provider_called']);
    }

    public function test_plan_returns_correct_schema_and_provider_called_false(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-test-12345678');

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $plan = $executor->plan([
            'model' => 'MiniMax-M3',
            'workspace' => ['path' => base_path()],
        ]);

        $this->assertSame('atlas.provider.minimax_m27.invocation_request.v1', $plan['schema_version']);
        $this->assertSame(AtlasForgeMinimaxM27InvocationDriver::PROVIDER, $plan['provider']);
        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
    }

    // -------------------------------------------------------------------------
    // invoke() — blocked / HTTP error / success scenarios
    // -------------------------------------------------------------------------

    public function test_invoke_stays_blocked_when_not_configured(): void
    {
        // Default setUp: enabled=false => no key => blocked.
        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $result = $executor->invoke([
            'model' => 'MiniMax-M3',
            'workspace' => ['path' => base_path()],
        ]);

        $this->assertSame('atlas.provider.minimax_m27.invocation_result.v1', $result['schema_version']);
        $this->assertFalse($result['provider_called']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertSame(AtlasMinimaxM27RuntimeExecutor::STATUS_BLOCKED, $result['process_status']);
        $this->assertNotEmpty($result['blockers']);
    }

    public function test_invoke_calls_http_factory_with_correct_headers_when_configured(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-header-test-abc');

        $capturedEndpoint = null;
        $capturedApiKey   = null;

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $executor->setHttpFactory(function (string $endpoint, string $apiKey, array $payload, int $timeout) use (&$capturedEndpoint, &$capturedApiKey): Response {
            $capturedEndpoint = $endpoint;
            $capturedApiKey   = $apiKey;

            // Return a fake successful Anthropic-compatible response.
            return $this->fakeResponse([
                'id'          => 'msg_test_001',
                'model'       => 'MiniMax-M3',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'Hello from MiniMax.']],
                'usage'       => ['input_tokens' => 10, 'output_tokens' => 6],
            ], 200);
        });

        $executor->invoke([
            'model'     => 'MiniMax-M3',
            'workspace' => ['path' => base_path()],
        ]);

        $this->assertStringContainsString('minimax.io', (string) $capturedEndpoint);
        $this->assertStringContainsString('/anthropic/v1/messages', (string) $capturedEndpoint);
        $this->assertSame('tpk-header-test-abc', $capturedApiKey);
    }

    public function test_invoke_maps_401_to_auth_failed(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-auth-fail-test');

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $executor->setHttpFactory(function (): Response {
            return $this->fakeResponse(['error' => 'Unauthorized'], 401);
        });

        $result = $executor->invoke([
            'model'     => 'MiniMax-M3',
            'workspace' => ['path' => base_path()],
        ]);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_AUTH_FAILED, $result['failure_type']);
        $this->assertSame(AtlasMinimaxM27RuntimeExecutor::STATUS_FAILED, $result['process_status']);
        $this->assertTrue($result['external_provider_call']);
    }

    public function test_invoke_maps_429_to_rate_limit(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-rate-limit-test');

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $executor->setHttpFactory(function (): Response {
            return $this->fakeResponse(['error' => 'Too Many Requests'], 429);
        });

        $result = $executor->invoke([
            'model'     => 'MiniMax-M3',
            'workspace' => ['path' => base_path()],
        ]);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT, $result['failure_type']);
        $this->assertContains('rate_limit', $result['blockers']);
    }

    public function test_invoke_maps_429_with_quota_in_body_to_quota_exhausted(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-quota-test');

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $executor->setHttpFactory(function (): Response {
            return $this->fakeResponse(['error' => 'quota exceeded: plan limit reached'], 429);
        });

        $result = $executor->invoke([
            'model'     => 'MiniMax-M3',
            'workspace' => ['path' => base_path()],
        ]);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_QUOTA_EXHAUSTED, $result['failure_type']);
        $this->assertContains('quota_exhausted', $result['blockers']);
    }

    public function test_invoke_maps_503_to_model_unavailable(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-503-test');

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $executor->setHttpFactory(function (): Response {
            return $this->fakeResponse(['error' => 'Service Unavailable'], 503);
        });

        $result = $executor->invoke([
            'model'     => 'MiniMax-M3',
            'workspace' => ['path' => base_path()],
        ]);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_MODEL_UNAVAILABLE, $result['failure_type']);
        $this->assertContains('model_unavailable', $result['blockers']);
    }

    public function test_invoke_result_never_promotes_completion_claim(): void
    {
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', 'tpk-no-claim-test');

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);
        $executor->setHttpFactory(function (): Response {
            return $this->fakeResponse([
                'id'          => 'msg_noclaim_001',
                'model'       => 'MiniMax-M3',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'Task output.']],
                'usage'       => ['input_tokens' => 5, 'output_tokens' => 3],
            ], 200);
        });

        $result = $executor->invoke([
            'model'     => 'MiniMax-M3',
            'workspace' => ['path' => base_path()],
        ]);

        // completion_claim must be absent or explicitly false — never promoted.
        if (array_key_exists('completion_claim', $result)) {
            $this->assertFalse($result['completion_claim'], 'completion_claim must not be true');
        } else {
            $this->assertArrayNotHasKey('completion_claim', $result);
        }
    }

    public function test_invoke_redacts_key_in_error_output(): void
    {
        // The executor's sanitizeMsg() redacts strings matching sk-[a-zA-Z0-9_\-]{8,}.
        // Use a key that matches this pattern so the test verifies the actual
        // redaction logic rather than an unrelated key prefix.
        $rawKey = 'sk-minimax-redact-me-xyz789';
        config()->set('atlas.ai.providers.minimax_m27.enabled', true);
        config()->set('atlas.ai.providers.minimax_m27.token_plan_key', $rawKey);

        $executor = app(AtlasMinimaxM27RuntimeExecutor::class);

        // Simulate a provider error whose body echoes back the raw key.
        $executor->setHttpFactory(function () use ($rawKey): Response {
            return $this->fakeResponse(['error' => "Invalid key: {$rawKey}"], 401);
        });

        $result = $executor->invoke([
            'model'     => 'MiniMax-M3',
            'workspace' => ['path' => base_path()],
        ]);

        $serialized = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // The raw key matched the sk-* redaction pattern and must not appear.
        $this->assertStringNotContainsString(
            $rawKey,
            (string) $serialized,
            'Raw sk-* API key must be redacted from invoke() output.'
        );
    }
}
