<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAntigravityCliGovernedTerminalExecutorService as Svc;
use Tests\TestCase;

/**
 * Pins the Antigravity CLI (`agy`) governance contract from the doc.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
 */
class AtlasAntigravityCliGovernedTerminalExecutorTest extends TestCase
{
    private function service(): Svc
    {
        return new Svc;
    }

    public function test_dangerous_skip_permissions_is_forbidden_token(): void
    {
        $c = $this->service()->classifyToken('--dangerously-skip-permissions');

        $this->assertSame(Svc::VERDICT_FORBIDDEN, $c['verdict']);
        $this->assertSame('dangerous_skip_permissions_forbidden', $c['reason']);
        $this->assertFalse($c['implementation_allowed']);
    }

    public function test_sandbox_is_allowed_baseline_and_add_dir_requires_review(): void
    {
        $svc = $this->service();

        $sandbox = $svc->classifyToken('--sandbox');
        $this->assertSame(Svc::VERDICT_ALLOWED, $sandbox['verdict']);

        // --add-dir widens scope -> review/work-packet required, never silent.
        $addDir = $svc->classifyToken('--add-dir');
        $this->assertSame(Svc::VERDICT_REVIEW_REQUIRED, $addDir['verdict']);

        // /model is observation only, not a sovereign routing token.
        $this->assertSame(Svc::VERDICT_REFERENCE_ONLY, $svc->classifyToken('/model')['verdict']);
    }

    public function test_invocation_with_dangerous_flag_is_blocked_even_with_sandbox(): void
    {
        $d = $this->service()->decideInvocation([
            'tokens' => ['--sandbox', '--dangerously-skip-permissions'],
            'atlas_workspace' => true,
        ]);

        $this->assertFalse($d['permitted']);
        $this->assertSame('blocked', $d['mode']);
        $this->assertContains('dangerous_skip_permissions_forbidden', $d['blockers']);
        // Sovereign authority never transfers to the CLI.
        $this->assertSame('atlas_decide', $d['routing_authority']);
    }

    public function test_runtime_driver_is_blocked_while_sdk_first_active(): void
    {
        $d = $this->service()->decideInvocation([
            'tokens' => ['--sandbox', '--print'],
            'as_runtime_driver' => true,
            'sdk_path_active' => true,
        ]);

        $this->assertFalse($d['permitted']);
        $this->assertContains('cli_runtime_driver_forbidden_while_sdk_first_active', $d['blockers']);
    }

    public function test_add_dir_without_work_packet_is_blocked_but_with_one_is_permitted(): void
    {
        $svc = $this->service();

        $without = $svc->decideInvocation([
            'tokens' => ['--sandbox', '--add-dir'],
            'work_packet_id' => null,
        ]);
        $this->assertFalse($without['permitted']);
        $this->assertContains('add_dir_without_work_packet_scope_lock', $without['blockers']);

        $with = $svc->decideInvocation([
            'tokens' => ['--sandbox', '--add-dir'],
            'work_packet_id' => 'WP-7',
        ]);
        $this->assertTrue($with['permitted']);
        $this->assertSame('governed_discovery_reference', $with['mode']);
    }

    public function test_missing_sandbox_on_atlas_workspace_warns_but_does_not_block(): void
    {
        $d = $this->service()->decideInvocation([
            'tokens' => ['--prompt-interactive'],
            'atlas_workspace' => true,
        ]);

        $this->assertTrue($d['permitted']);
        $this->assertFalse($d['sandbox_default_applied']);
        $this->assertContains('sandbox_default_missing_treat_as_risk_event', $d['warnings']);
    }

    public function test_secrets_in_prompt_block_unconditionally(): void
    {
        $d = $this->service()->decideInvocation([
            'tokens' => ['--sandbox'],
            'secrets_in_prompt' => true,
        ]);

        $this->assertFalse($d['permitted']);
        $this->assertContains('secrets_or_canonical_memory_sent_to_cli', $d['blockers']);
    }

    public function test_observed_model_is_never_atlas_routing_policy(): void
    {
        $m = $this->service()->classifyObservedModel('Gemini');

        $this->assertSame('Gemini', $m['model_observed']);
        $this->assertFalse($m['is_atlas_routing_policy']);
        $this->assertSame('atlas_decide', $m['routing_authority']);
        $this->assertSame('model_observed', $m['classification']);
    }

    public function test_reference_envelope_pins_required_fields_and_no_implementation(): void
    {
        $env = $this->service()->referenceEnvelope([
            'binary_path' => '/Users/vitorepf/.local/bin/agy',
            'present' => true,
            'help_hash' => 'abc123',
            'flags_seen' => ['--sandbox', '--dangerously-skip-permissions', '--print'],
            'models_observed' => ['Gemini', 'Claude'],
        ]);

        $this->assertSame(Svc::REFERENCE_SCHEMA, $env['schema']);
        // Required fields present.
        foreach (['binary_path', 'present', 'help_hash', 'flags_seen', 'sandbox_supported', 'dangerous_flags', 'models_observed', 'implementation_allowed'] as $field) {
            $this->assertArrayHasKey($field, $env);
        }
        // sandbox flag seen -> supported true; dangerous flag isolated.
        $this->assertTrue($env['sandbox_supported']);
        $this->assertSame(['--dangerously-skip-permissions'], $env['dangerous_flags']);
        // Hard invariant: a new field can never open a CLI runtime.
        $this->assertFalse($env['implementation_allowed']);
    }

    public function test_manifest_state_is_reference_only_and_flow_excludes_driver(): void
    {
        $m = $this->service()->manifest();

        $this->assertSame('reference_only_sdk_first_no_cli_driver', $m['implementation_state']);
        $this->assertFalse($m['implementation_allowed']);
        $this->assertContains('--dangerously-skip-permissions', $m['forbidden_tokens']);
        $this->assertContains('dangerous_skip_permissions_forbidden', $m['invariants']);
        // The reference flow implements via the SDK, never via an agy driver.
        $this->assertSame([
            'observe_cli_docs_flags_models_permissions',
            'record_risks_and_capabilities_affecting_sdk_first_strategy',
            'implement_and_measure_via_governed_sdk_not_agy',
        ], $m['reference_flow']);
    }
}
