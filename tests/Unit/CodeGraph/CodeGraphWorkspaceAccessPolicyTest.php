<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceAccessPolicy;
use Tests\TestCase;

/**
 * AP-815 · G-7 — per-workspace sovereignty access policy.
 *
 * Pure unit test (NO database). Proves the fail-closed default-deny contract:
 * sovereign material (secret/cyber) never reaches an untrusted provider, and any
 * malformed / unknown input can only ever make the decision more restrictive.
 */
class CodeGraphWorkspaceAccessPolicyTest extends TestCase
{
    private function policy(): CodeGraphWorkspaceAccessPolicy
    {
        return new CodeGraphWorkspaceAccessPolicy();
    }

    // --- Happy path -------------------------------------------------------

    public function test_public_class_allows_anyone(): void
    {
        $result = $this->policy()->allows('openai', 'public');

        $this->assertTrue($result['allowed']);
        $this->assertIsString($result['reason']);
    }

    public function test_internal_class_allows_anyone(): void
    {
        $this->assertTrue($this->policy()->allows('some-random-agent', 'internal')['allowed']);
    }

    public function test_sensitive_allows_trusted_kernel_but_denies_external_provider(): void
    {
        $policy = $this->policy();

        $this->assertTrue(
            $policy->allows('atlas-kernel', 'sensitive')['allowed'],
            'Trusted kernel must read sensitive graphs.'
        );
        $this->assertTrue(
            $policy->allows('operator', 'sensitive')['allowed'],
            'Operator is in the default trusted set.'
        );
        $this->assertFalse(
            $policy->allows('openai', 'sensitive')['allowed'],
            'An external provider must NOT read sensitive graphs.'
        );
    }

    public function test_secret_denies_external_provider_and_allows_sovereign(): void
    {
        $policy = $this->policy();

        $this->assertFalse(
            $policy->allows('openai', 'secret')['allowed'],
            'Secret material must never reach an external provider.'
        );
        $this->assertTrue(
            $policy->allows('atlas-kernel', 'secret')['allowed'],
            'Kernel is sovereign and may read secret graphs.'
        );
        $this->assertTrue($policy->allows('local', 'secret')['allowed']);
    }

    public function test_cyber_is_sovereign_only(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->allows('local', 'cyber')['allowed']);
        // 'operator' is trusted but NOT sovereign → denied for cyber/secret.
        $this->assertFalse(
            $policy->allows('operator', 'cyber')['allowed'],
            'Trusted-but-not-sovereign actor must not read cyber graphs.'
        );
        $this->assertFalse($policy->allows('anthropic', 'cyber')['allowed']);
    }

    // --- Edge cases -------------------------------------------------------

    public function test_unknown_class_is_denied_fail_closed(): void
    {
        $result = $this->policy()->allows('atlas-kernel', 'top-double-secret');

        $this->assertFalse($result['allowed'], 'Unknown privacy class must fail closed.');
        $this->assertStringContainsString('unknown_privacy_class', $result['reason']);
    }

    public function test_empty_privacy_class_is_denied(): void
    {
        $result = $this->policy()->allows('atlas-kernel', '   ');

        $this->assertFalse($result['allowed']);
        $this->assertSame('empty_privacy_class', $result['reason']);
    }

    public function test_empty_actor_is_denied_even_for_public(): void
    {
        $result = $this->policy()->allows('', 'public');

        $this->assertFalse($result['allowed'], 'Empty actor must be denied regardless of class.');
        $this->assertSame('empty_actor', $result['reason']);
    }

    public function test_actor_and_class_matching_is_case_and_whitespace_insensitive(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->allows('  Atlas-Kernel  ', '  SECRET  ')['allowed']);
        $this->assertTrue($policy->allows('OPENAI', 'Public')['allowed']);
        $this->assertFalse($policy->allows('OpenAI', 'SENSITIVE')['allowed']);
    }

    public function test_opts_trusted_may_only_narrow_not_append_identity(): void
    {
        $policy = $this->policy();

        // 'openai' is not trusted by default and cannot be appended by caller opts (P1b.1).
        $this->assertFalse($policy->allows('openai', 'sensitive')['allowed']);
        $this->assertFalse(
            $policy->allows('openai', 'sensitive', ['trusted' => ['openai']])['allowed'],
            'Per-call trusted opts must not append sovereign/trusted identity beyond config.'
        );

        // Narrowing to operator still allows operator (intersection with configured trusted).
        $this->assertTrue(
            $policy->allows('operator', 'sensitive', ['trusted' => ['operator']])['allowed']
        );
        // Narrowing away from operator denies operator for this call.
        $this->assertFalse(
            $policy->allows('operator', 'sensitive', ['trusted' => ['atlas-kernel']])['allowed']
        );
    }

    public function test_opts_sovereign_may_only_narrow_not_append_identity(): void
    {
        $policy = $this->policy();

        $this->assertFalse($policy->allows('vault-agent', 'secret')['allowed']);
        $this->assertFalse(
            $policy->allows('vault-agent', 'secret', ['sovereign' => ['vault-agent']])['allowed'],
            'Caller cannot append sovereign identity beyond config (P1b.1).'
        );
        $this->assertTrue(
            $policy->allows('atlas-kernel', 'secret', ['sovereign' => ['atlas-kernel']])['allowed']
        );
    }

    public function test_sovereign_actor_is_implicitly_trusted_for_sensitive(): void
    {
        // Even if config trusted list excludes the kernel, sovereign actors are
        // folded into the trusted pool → kernel can still read sensitive graphs.
        config(['atlas.code_graph.trusted_actors' => ['operator']]);

        $this->assertTrue(
            $this->policy()->allows('atlas-kernel', 'sensitive')['allowed'],
            'Sovereign kernel must never lock itself out of sensitive graphs.'
        );
    }

    public function test_malformed_config_falls_back_to_safe_defaults(): void
    {
        // A non-array, non-string config value must not blow up nor open access.
        config(['atlas.code_graph.sovereign_actors' => 12345]);
        config(['atlas.code_graph.trusted_actors' => null]);

        $policy = $this->policy();

        $this->assertTrue(
            $policy->allows('atlas-kernel', 'secret')['allowed'],
            'Malformed sovereign config must fall back to the safe default set.'
        );
        $this->assertFalse(
            $policy->allows('openai', 'secret')['allowed'],
            'Malformed config must never open sovereign access to externals.'
        );
        $this->assertFalse($policy->allows('openai', 'sensitive')['allowed']);
    }

    public function test_result_shape_is_stable(): void
    {
        $result = $this->policy()->allows('atlas-kernel', 'public');

        $this->assertArrayHasKey('allowed', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertIsBool($result['allowed']);
        $this->assertIsString($result['reason']);
    }
}
