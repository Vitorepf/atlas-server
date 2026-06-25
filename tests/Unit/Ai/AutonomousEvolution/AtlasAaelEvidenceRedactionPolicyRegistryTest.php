<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction\AtlasAaelEvidenceRedactionPolicyRegistry;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction\AtlasAaelEvidenceRedactionRule;
use Tests\TestCase;

final class AtlasAaelEvidenceRedactionPolicyRegistryTest extends TestCase
{
    public function test_resolve_stdout_carries_minimum_required_secret_family_rules(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $policy = $registry->resolve('stdout');

        $this->assertSame('stdout', $policy->evidenceKind);
        $this->assertFalse($policy->isFailClosedDefault);
        $this->assertNotEmpty($policy->rules);

        $patternBlob = implode("\n", array_map(static fn (AtlasAaelEvidenceRedactionRule $r): string => $r->pattern, $policy->rules));

        $this->assertMatchesRegularExpression('/sk-ant-/', $patternBlob, 'anthropic api key prefix rule expected');
        $this->assertMatchesRegularExpression('/sk-\[A-Za-z0-9_/', $patternBlob, 'sk- prefixed key rule expected');
        $this->assertMatchesRegularExpression('/Bearer/', $patternBlob, 'OAuth bearer rule expected');
        $this->assertStringContainsString('/Users/', $patternBlob, '/Users/ home prefix rule expected');
        $this->assertStringContainsString('=', $patternBlob, '.env=value pattern expected');
    }

    public function test_resolve_unknown_kind_returns_sealed_fail_closed_default(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $policy = $registry->resolve('UNKNOWN_KIND_AT_RUNTIME');

        $this->assertTrue($policy->isFailClosedDefault, 'unknown kind MUST be the sealed default');
        $this->assertNotEmpty($policy->rules, 'unknown kind must NEVER receive an empty policy');
        $this->assertCount(1, $policy->rules);
        $this->assertSame(AtlasAaelEvidenceRedactionRule::SCOPE_FULL, $policy->rules[0]->scope);
        $this->assertSame(AtlasAaelEvidenceRedactionPolicyRegistry::UNKNOWN_KIND_SENTINEL, $policy->rules[0]->replacement);
    }

    public function test_resolve_is_byte_deterministic_across_consecutive_calls(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $a = $registry->resolve('command_args')->toArray();
        $b = $registry->resolve('command_args')->toArray();

        $this->assertSame(
            hash('sha256', (string) json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            hash('sha256', (string) json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'two consecutive resolve() calls MUST hash byte-identically',
        );
    }

    public function test_frozen_default_kinds_are_all_covered(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        foreach (AtlasAaelEvidenceRedactionPolicyRegistry::FROZEN_DEFAULT_KINDS as $kind) {
            $policy = $registry->resolve($kind);
            $this->assertFalse($policy->isFailClosedDefault, $kind.' must be a configured policy, not the sealed default');
            $this->assertNotEmpty($policy->rules, $kind.' must carry at least the frozen secret-family rules');
        }
    }

    public function test_operator_override_extends_but_frozen_rules_lead(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry([
            'stdout' => [
                ['kind' => 'regex', 'pattern' => '/CUSTOM_TOKEN_\d+/', 'replacement' => '[REDACTED:custom]'],
            ],
        ]);
        $policy = $registry->resolve('stdout');

        $this->assertGreaterThan(
            1,
            count($policy->rules),
            'operator extension must be ADDED to the frozen baseline, not replace it',
        );
        $patterns = array_map(static fn (AtlasAaelEvidenceRedactionRule $r): string => $r->pattern, $policy->rules);
        $this->assertContains('/CUSTOM_TOKEN_\d+/', $patterns);
        // Frozen sk-ant rule must still be present.
        $this->assertNotEmpty(array_filter($patterns, static fn (string $p): bool => str_contains($p, 'sk-ant-')));
    }

    public function test_env_snapshot_adds_key_name_redaction_on_top_of_secret_families(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $policy = $registry->resolve('env_snapshot');

        $kinds = array_map(static fn (AtlasAaelEvidenceRedactionRule $r): string => $r->kind, $policy->rules);
        $this->assertContains(AtlasAaelEvidenceRedactionRule::KIND_KEY_NAME, $kinds, 'env_snapshot must redact by key_name');
    }
}
