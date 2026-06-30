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
        // Allow-decision kinds carry no rules by design (they are safe scalars).
        $allowKinds = ['task_id', 'evidence_hash'];
        foreach (AtlasAaelEvidenceRedactionPolicyRegistry::FROZEN_DEFAULT_KINDS as $kind) {
            $policy = $registry->resolve($kind);
            $this->assertFalse($policy->isFailClosedDefault, $kind.' must be a configured policy, not the sealed default');
            if (! in_array($kind, $allowKinds, true)) {
                $this->assertNotEmpty($policy->rules, $kind.' must carry at least the frozen secret-family rules');
            }
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

    // ---------- Self-Construction receipt kinds ----------

    public function test_classify_task_id_and_evidence_hash_return_allow_decision(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();

        foreach (['task_id', 'evidence_hash', 'file_path'] as $kind) {
            $result = $registry->classify($kind);
            $this->assertSame(AtlasAaelEvidenceRedactionPolicyRegistry::DECISION_ALLOW, $result['decision'], $kind.' must be allowed');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['policy_id'], $kind.' policy_id must be sha256 hex');
        }
    }

    public function test_classify_agent_prompt_returns_redact_decision(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $result   = $registry->classify('agent_prompt');

        $this->assertSame(AtlasAaelEvidenceRedactionPolicyRegistry::DECISION_REDACT, $result['decision']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['policy_id']);
    }

    public function test_classify_trace_returns_block_decision(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $result   = $registry->classify('trace');

        $this->assertSame(AtlasAaelEvidenceRedactionPolicyRegistry::DECISION_BLOCK, $result['decision']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['policy_id']);
    }

    public function test_classify_unknown_kind_fails_closed_to_block(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $result   = $registry->classify('completely_unknown_channel_xyz');

        $this->assertSame(AtlasAaelEvidenceRedactionPolicyRegistry::DECISION_BLOCK, $result['decision'], 'unknown kind must fail-closed to block');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['policy_id']);
    }

    public function test_classify_policy_id_is_deterministic_across_calls(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();

        foreach (['task_id', 'trace', 'agent_prompt', 'objective'] as $kind) {
            $a = $registry->classify($kind);
            $b = $registry->classify($kind);
            $this->assertSame($a['policy_id'], $b['policy_id'], "policy_id must be deterministic for $kind");
        }
    }

    public function test_classify_objective_and_provider_label_return_hash_only(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();

        foreach (['objective', 'provider_label'] as $kind) {
            $result = $registry->classify($kind);
            $this->assertSame(AtlasAaelEvidenceRedactionPolicyRegistry::DECISION_HASH_ONLY, $result['decision'], $kind.' must be hash_only');
        }
    }

    public function test_resolve_trace_carries_block_sentinel_rule(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $policy   = $registry->resolve('trace');

        $this->assertNotEmpty($policy->rules);
        $replacements = array_map(static fn (AtlasAaelEvidenceRedactionRule $r): string => $r->replacement, $policy->rules);
        $this->assertContains('[BLOCKED:trace]', $replacements, 'trace must carry the block sentinel rule');
    }

    public function test_resolve_task_id_carries_no_rules_since_it_is_allowed(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $policy   = $registry->resolve('task_id');

        $this->assertFalse($policy->isFailClosedDefault);
        $this->assertEmpty($policy->rules, 'task_id is allow — no redaction rules needed');
    }
}
