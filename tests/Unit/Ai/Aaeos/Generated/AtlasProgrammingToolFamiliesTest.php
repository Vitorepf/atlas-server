<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingToolFamiliesService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Programming Tool Families contract: the nine-family
 * taxonomy from the Families table, the per-tool family/role assignment, the
 * "Authority Pattern" six required declarations, the duplicate suppression
 * rule, and the Registry Rule (the map is advisory, never runtime authority).
 *
 * @see docs/engineering-knowledge-base/tool-runtime/programming-tool-families.md
 */
class AtlasProgrammingToolFamiliesTest extends TestCase
{
    private function service(): AtlasProgrammingToolFamiliesService
    {
        return new AtlasProgrammingToolFamiliesService();
    }

    /**
     * Representative tools land in their documented family, and the family map
     * exposes all nine families in doc table order.
     */
    public function test_tools_map_to_documented_families_in_doc_order(): void
    {
        $svc = $this->service();

        // From the Families table rows.
        $this->assertSame('code_and_context', $svc->classify('ripgrep')['family']);
        $this->assertSame('php_quality', $svc->classify('phpstan')['family']);
        $this->assertSame('ts_frontend', $svc->classify('eslint')['family']);
        $this->assertSame('security_supply_chain', $svc->classify('gitleaks')['family']);
        $this->assertSame('containers_iac', $svc->classify('hadolint')['family']);
        $this->assertSame('api_contracts', $svc->classify('schemathesis')['family']);
        $this->assertSame('visual_a11y_perf', $svc->classify('lighthouse')['family']);
        $this->assertSame('architecture', $svc->classify('deptrac')['family']);
        $this->assertSame('external_agents', $svc->classify('aider')['family']);

        $this->assertSame(
            [
                'code_and_context', 'php_quality', 'ts_frontend', 'security_supply_chain',
                'containers_iac', 'api_contracts', 'visual_a11y_perf', 'architecture', 'external_agents',
            ],
            $svc->map()['family_order'],
        );
        $this->assertSame(9, $svc->map()['family_count']);
    }

    /**
     * The Authority Pattern requires every family to declare all six attributes
     * (primary; complementary/fallback; output normalizer; evidence shape; gate
     * thresholds; duplicate suppression). Every documented family is complete.
     */
    public function test_every_family_declares_the_six_authority_pattern_attributes(): void
    {
        $svc = $this->service();

        $this->assertSame(
            ['primary', 'complementary_fallback', 'output_normalizer', 'evidence_shape', 'gate_thresholds', 'duplicate_suppression'],
            AtlasProgrammingToolFamiliesService::REQUIRED_AUTHORITY_DECLARATIONS,
        );

        foreach ($svc->map()['family_order'] as $family) {
            $pattern = $svc->authorityPattern($family);
            $this->assertTrue($pattern['known'], $family);
            $this->assertSame([], $pattern['missing_declarations'], $family);
            $this->assertTrue($pattern['authority_complete'], $family);
        }

        $this->assertTrue($svc->map()['all_families_authority_complete']);

        // Code-and-context primary is the Atlas index, not raw grep.
        $codeCtx = $svc->authorityPattern('code_and_context');
        $this->assertSame('atlas_code_intelligence', $codeCtx['declarations']['primary']);
    }

    /**
     * Registry Rule: "This doc is not the registry." Every verdict is advisory —
     * is_registry and runtime_authority are always false, and the map echoes
     * that runtime truth lives in atlas_tool_definitions.
     */
    public function test_map_is_advisory_and_never_claims_to_be_the_registry(): void
    {
        $svc = $this->service();

        $verdict = $svc->classify('phpstan');
        $this->assertTrue($verdict['known']);
        $this->assertTrue($verdict['is_primary']);
        $this->assertFalse($verdict['is_registry']);
        $this->assertFalse($verdict['runtime_authority']);

        $map = $svc->map();
        $this->assertFalse($map['is_registry']);
        $this->assertSame(
            'runtime_truth_lives_in_atlas_tool_definitions_and_tool_runtime_services',
            $map['registry_rule'],
        );

        // Unknown tools are rejected, not invented into a family.
        $unknown = $svc->classify('totally-made-up-linter');
        $this->assertFalse($unknown['known']);
        $this->assertNull($unknown['family']);
        $this->assertSame('tool_not_in_programming_tool_families_map', $unknown['reason']);
    }

    /**
     * Duplicate suppression rule: a non-primary finding covered by the family
     * primary is suppressed; an uncovered complementary finding is kept; and
     * the primary's own finding is never suppressed.
     */
    public function test_duplicate_suppression_rule_uses_primary_authority(): void
    {
        $svc = $this->service();

        // psalm (complementary) covered by phpstan (primary) => suppressed.
        $covered = $svc->shouldSuppressFinding('psalm', coveredByPrimary: true);
        $this->assertSame('php_quality', $covered['family']);
        $this->assertSame('phpstan', $covered['primary_tool']);
        $this->assertTrue($covered['suppressed']);
        $this->assertSame('suppress', $covered['decision']);

        // psalm finding NOT covered by phpstan => kept.
        $uncovered = $svc->shouldSuppressFinding('psalm', coveredByPrimary: false);
        $this->assertFalse($uncovered['suppressed']);
        $this->assertSame('keep', $uncovered['decision']);

        // The primary (phpstan) is authoritative; never suppressed by coverage.
        $primary = $svc->shouldSuppressFinding('phpstan', coveredByPrimary: true);
        $this->assertFalse($primary['suppressed']);
        $this->assertSame('primary_tool_finding_is_authoritative', $primary['reason']);
    }

    /**
     * External agents (Aider, Continue, OpenHands, Claude Code, Codex CLI,
     * Cursor) are engines: they hold the executor role, never primary
     * authority. The control plane is the family's primary.
     */
    public function test_external_agents_are_executors_not_primary_authority(): void
    {
        $svc = $this->service();

        foreach (['aider', 'continue', 'openhands', 'claude_code', 'codex_cli', 'cursor'] as $agent) {
            $verdict = $svc->classify($agent);
            $this->assertSame('external_agents', $verdict['family'], $agent);
            $this->assertSame('executor', $verdict['authority_role'], $agent);
            $this->assertFalse($verdict['is_primary'], $agent);
            $this->assertFalse($verdict['runtime_authority'], $agent);
        }

        $this->assertSame('atlas_control_plane', $svc->authorityPattern('external_agents')['declarations']['primary']);
    }
}
