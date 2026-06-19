<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSpec;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — fail-closed structural validation of the pattern spec.
 *
 * The spec is the type-level guarantee that "no gate / no terminal / no sandbox" can never exist. Each
 * test pins one fail-closed condition so a future careless relaxation breaks here, not in production.
 */
final class AtlasLoopPatternSpecTest extends TestCase
{
    /** @return array<string,mixed> a minimally valid spec definition. */
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'id' => 'unit_pattern',
            'version' => '1.0.0',
            'name' => 'Unit pattern',
            'description' => 'for tests',
            'intent' => 'refactor',
            'trigger_schema' => ['objective_kinds' => ['refactor']],
            'params_schema' => [],
            'output_schema' => [],
            'success_gates' => ['an independent RED→GREEN proof'],
            'terminal_states' => ['success', 'blocked'],
            'durability_mode' => 'single_cycle',
            'sandbox_profile' => ['allowed' => ['read_only', 'worktree_write']],
            'agent_lane_policy' => ['self_approval' => false, 'verifier_independent' => true],
            'risk_level' => 'medium',
            'source' => 'atlas_native',
            'source_snapshot' => [],
            'status' => 'ready',
        ], $overrides);
    }

    public function test_a_valid_spec_is_built_and_round_trips(): void
    {
        $spec = AtlasLoopPatternSpec::fromArray($this->valid());

        $this->assertSame('unit_pattern', $spec->id);
        $this->assertTrue($spec->isSelectable());
        $this->assertSame(['refactor'], $spec->objectiveKinds());
        $this->assertTrue($spec->forbidsSelfApproval());
        $this->assertSame('1.0.0', $spec->toArray()['version']);
    }

    public function test_missing_success_gate_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasLoopPatternSpec::fromArray($this->valid(['success_gates' => []]));
    }

    public function test_terminal_states_without_success_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasLoopPatternSpec::fromArray($this->valid(['terminal_states' => ['blocked', 'exhausted']]));
    }

    public function test_missing_sandbox_profile_fails_closed(): void
    {
        $def = $this->valid();
        unset($def['sandbox_profile']);

        $this->expectException(InvalidArgumentException::class);
        AtlasLoopPatternSpec::fromArray($def);
    }

    public function test_unknown_vocabulary_fails_closed(): void
    {
        $errors = AtlasLoopPatternSpec::validationErrors($this->valid([
            'durability_mode' => 'warp_drive',
            'source' => 'mystery',
            'status' => 'live',
        ]));

        $this->assertNotEmpty($errors);
        $this->assertTrue((bool) array_filter($errors, static fn ($e) => str_contains($e, 'durability_mode')));
        $this->assertTrue((bool) array_filter($errors, static fn ($e) => str_contains($e, 'source')));
        $this->assertTrue((bool) array_filter($errors, static fn ($e) => str_contains($e, 'status')));
    }

    public function test_external_source_can_never_be_born_selectable(): void
    {
        // Even when authored as default, external provenance is quarantined to candidate at construction.
        $spec = AtlasLoopPatternSpec::fromArray($this->valid([
            'source' => 'external_catalog',
            'status' => 'default',
        ]));

        $this->assertSame('candidate', $spec->status);
        $this->assertFalse($spec->isSelectable());
        $this->assertTrue($spec->isExternalSource());
    }

    public function test_try_from_array_is_null_safe_on_invalid(): void
    {
        $this->assertNull(AtlasLoopPatternSpec::tryFromArray(['id' => 'x']));
        $this->assertInstanceOf(AtlasLoopPatternSpec::class, AtlasLoopPatternSpec::tryFromArray($this->valid()));
    }

    public function test_sandbox_is_deny_by_default(): void
    {
        $spec = AtlasLoopPatternSpec::fromArray($this->valid(['sandbox_profile' => ['allowed' => []]]));

        // empty allow-list normalizes to read_only (never "everything"), deny flag always present.
        $this->assertSame(['read_only'], $spec->allowedCapabilities());
        $this->assertTrue($spec->sandboxProfile['deny_by_default']);
    }
}
