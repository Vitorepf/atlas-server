<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Gate\DevDiffQualityAmplifierGate;
use PHPUnit\Framework\TestCase;

final class DevDiffQualityAmplifierGateTest extends TestCase
{
    private function gate(): DevDiffQualityAmplifierGate
    {
        return new DevDiffQualityAmplifierGate;
    }

    private function workcell(array $overrides = []): array
    {
        return array_merge([
            'allowed_files' => ['app/Services/Foo/Bar.php', 'tests/Unit/Foo/BarTest.php'],
            'forbidden_files' => [],
            'objective_line_budget' => 10,
        ], $overrides);
    }

    // ── (a) out-of-scope file yields block via the composed scope guard ────

    public function test_out_of_scope_file_yields_block_via_composed_scope_guard(): void
    {
        $result = $this->gate()->evaluate(
            [
                'files' => [
                    ['path' => 'app/Services/Other/NotAllowed.php', 'hunks' => [['changed_lines' => 2]]],
                ],
            ],
            $this->workcell(),
            [],
        );

        $this->assertSame('block', $result['verdict']);
        $findingIds = array_column($result['findings'], 'id');
        $this->assertContains('scope_respect', $findingIds);
        $scopeFinding = $result['findings'][array_search('scope_respect', $findingIds, true)];
        $this->assertContains('app/Services/Other/NotAllowed.php', $scopeFinding['denied_writes']);
    }

    // ── (b) a bloated diff for a one-line objective yields diff_minimality warn ──

    public function test_bloated_diff_for_small_objective_yields_diff_minimality_warn(): void
    {
        $result = $this->gate()->evaluate(
            [
                'files' => [
                    ['path' => 'app/Services/Foo/Bar.php', 'hunks' => [['changed_lines' => 200]]],
                ],
            ],
            $this->workcell(['objective_line_budget' => 1]),
            [],
        );

        $this->assertNotSame('block', $result['verdict']);
        $findingIds = array_column($result['findings'], 'id');
        $this->assertContains('diff_minimality', $findingIds);
    }

    // ── (c) a changed public method with untouched known callers yields caller_coverage warn ──

    public function test_changed_public_method_with_untouched_caller_yields_caller_coverage_warn(): void
    {
        $result = $this->gate()->evaluate(
            [
                'files' => [
                    [
                        'path' => 'app/Services/Foo/Bar.php',
                        'hunks' => [['changed_lines' => 3]],
                        'changed_public_methods' => ['resolveWidget'],
                    ],
                ],
            ],
            $this->workcell(),
            [
                'likely_callers' => [
                    ['ref' => 'app/Services/Consumer/WidgetConsumer::resolveWidget', 'kind' => 'symbol', 'reason' => 'calls resolveWidget'],
                ],
            ],
        );

        $findingIds = array_column($result['findings'], 'id');
        $this->assertContains('caller_coverage', $findingIds);
    }

    // ── (d) a diff overlapping foreign uncommitted work yields wip_protection block ──

    public function test_diff_overlapping_foreign_uncommitted_work_yields_wip_protection_block(): void
    {
        $result = $this->gate()->evaluate(
            [
                'files' => [
                    [
                        'path' => 'app/Services/Foo/Bar.php',
                        'hunks' => [['changed_lines' => 2, 'overlaps_foreign_uncommitted' => true]],
                    ],
                ],
            ],
            $this->workcell(),
            [],
        );

        $this->assertSame('block', $result['verdict']);
        $findingIds = array_column($result['findings'], 'id');
        $this->assertContains('wip_protection', $findingIds);
    }

    // ── (e) a clean minimal diff with a relevant test yields pass ──────────

    public function test_clean_minimal_diff_with_relevant_test_yields_pass(): void
    {
        $result = $this->gate()->evaluate(
            [
                'files' => [
                    [
                        'path' => 'app/Services/Foo/Bar.php',
                        'hunks' => [['changed_lines' => 3]],
                        'changed_public_methods' => ['resolveWidget'],
                    ],
                    [
                        'path' => 'tests/Unit/Foo/BarTest.php',
                        'is_test' => true,
                        'hunks' => [['changed_lines' => 4]],
                        'exercises_symbols' => ['resolveWidget'],
                    ],
                ],
            ],
            $this->workcell(),
            [],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['findings']);
    }

    // ── overengineering_smell ────────────────────────────────────────────

    public function test_new_interface_with_single_implementation_yields_overengineering_smell(): void
    {
        $result = $this->gate()->evaluate(
            [
                'files' => [
                    [
                        'path' => 'app/Services/Foo/Bar.php',
                        'hunks' => [['changed_lines' => 3]],
                        'new_interfaces' => [['name' => 'FooInterface', 'implementation_count' => 1]],
                    ],
                ],
            ],
            $this->workcell(),
            [],
        );

        $findingIds = array_column($result['findings'], 'id');
        $this->assertContains('overengineering_smell', $findingIds);
        $this->assertNotSame('block', $result['verdict']);
    }

    public function test_new_fixed_value_config_key_yields_overengineering_smell(): void
    {
        $result = $this->gate()->evaluate(
            [
                'files' => [
                    [
                        'path' => 'app/Services/Foo/Bar.php',
                        'hunks' => [['changed_lines' => 2]],
                        'new_config_keys' => [['key' => 'foo.bar', 'fixed_value' => true]],
                    ],
                ],
            ],
            $this->workcell(),
            [],
        );

        $findingIds = array_column($result['findings'], 'id');
        $this->assertContains('overengineering_smell', $findingIds);
    }

    // ── internal error fails open to warn ───────────────────────────────

    public function test_internal_error_fails_open_to_warn(): void
    {
        $result = $this->gate()->evaluate(
            [
                // 'files' intentionally not an array to force a TypeError-shaped internal fault.
                'files' => 'not-an-array-of-files-but-still-handled-defensively',
            ],
            $this->workcell(),
            [],
        );

        // Even malformed input must never crash the caller or block — this proves fail-open.
        $this->assertContains($result['verdict'], ['pass', 'warn']);
        $this->assertNotSame('block', $result['verdict']);
    }

    public function test_schema_present(): void
    {
        $result = $this->gate()->evaluate(['files' => []], $this->workcell(), []);

        $this->assertSame(DevDiffQualityAmplifierGate::SCHEMA, $result['schema']);
    }
}
