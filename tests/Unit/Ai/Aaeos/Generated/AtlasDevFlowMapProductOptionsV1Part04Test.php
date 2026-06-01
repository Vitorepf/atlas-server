<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapProductOptionsV1Part04Service;
use Tests\TestCase;

/**
 * Pins the documented routing tables of the Parte 4 flow-map recorte.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-04.md
 */
class AtlasDevFlowMapProductOptionsV1Part04Test extends TestCase
{
    private function service(): AtlasDevFlowMapProductOptionsV1Part04Service
    {
        return new AtlasDevFlowMapProductOptionsV1Part04Service();
    }

    public function test_task_flow_map_matches_doc_table(): void
    {
        $svc = $this->service();

        // dev | plan | direct -> programming.dev
        $this->assertSame('programming.dev', $svc->resolveFlowForTask('dev')['flow']);
        $this->assertSame('programming.dev', $svc->resolveFlowForTask('plan')['flow']);
        $this->assertSame('programming.dev', $svc->resolveFlowForTask('direct')['flow']);

        // review -> programming.review
        $this->assertSame('programming.review', $svc->resolveFlowForTask('review')['flow']);

        // debug | repair -> programming.repair
        $this->assertSame('programming.repair', $svc->resolveFlowForTask('debug')['flow']);
        $this->assertSame('programming.repair', $svc->resolveFlowForTask('REPAIR')['flow']); // case-insensitive
    }

    public function test_unknown_task_is_unsupported_with_no_silent_default(): void
    {
        $result = $this->service()->resolveFlowForTask('compile');

        $this->assertFalse($result['supported']);
        $this->assertNull($result['flow']); // must NOT silently fall back to programming.dev
    }

    public function test_surface_gate_excludes_atlas_code_and_requires_workspace(): void
    {
        $svc = $this->service();

        // atlas_code is owned by Forge -> never applies, even with mode+workspace
        $code = $svc->appliesToSurface('atlas_code', 'programming', true);
        $this->assertFalse($code['applies']);
        $this->assertSame('atlas_code_owned_by_forge', $code['reason']);

        // valid surface + programming + workspace -> applies
        $this->assertTrue($svc->appliesToSurface('atlas_app', 'programming', true)['applies']);

        // valid surface but no workspace -> blocked
        $noWs = $svc->appliesToSurface('atlas_app', 'programming', false);
        $this->assertFalse($noWs['applies']);
        $this->assertSame('workspace_missing', $noWs['reason']);

        // valid surface, wrong mode -> blocked
        $this->assertSame('mode_not_programming', $svc->appliesToSurface('atlas_app', 'chat', true)['reason']);

        // unknown surface -> blocked
        $this->assertSame('unknown_surface', $svc->appliesToSurface('atlas_unknown', 'programming', true)['reason']);
    }

    public function test_provider_alias_normalization_and_gemini_write_block(): void
    {
        $svc = $this->service();

        $this->assertSame('claude_cli', $svc->normalizeProvider('claude')['provider']);
        $this->assertSame('codex_cli', $svc->normalizeProvider('codex-cli')['provider']);
        $this->assertSame('claude_codex', $svc->normalizeProvider('council')['provider']);
        $this->assertSame('claude_codex', $svc->normalizeProvider('ambos')['provider']);

        // gemini normalizes but is read-only in dev mode
        $geminiDev = $svc->normalizeProvider('gemini', 'dev');
        $this->assertSame('gemini_cli', $geminiDev['provider']);
        $this->assertFalse($geminiDev['write_allowed']);
        $this->assertSame('gemini_read_only_in_dev', $geminiDev['write_block_reason']);

        // gemini in a non-dev mode is not write-blocked by this rule
        $this->assertTrue($svc->normalizeProvider('gemini', 'review')['write_allowed']);

        // unknown alias is not recognized
        $this->assertFalse($svc->normalizeProvider('mystery-cli')['recognized']);
    }

    public function test_fair_claude_lock_pins_claude_and_forbids_other_providers(): void
    {
        $svc = $this->service();

        $lock = $svc->fairClaudeLock(['--single-provider']);
        $this->assertTrue($lock['locked']);
        $this->assertSame('claude_cli', $lock['provider_lock']);
        $this->assertSame('opus', $lock['model_lock']);
        $this->assertFalse($lock['atlas_decide']);
        $this->assertFalse($lock['fallback_allowed']);
        $this->assertFalse($lock['council_allowed']);
        $this->assertContains('codex_cli', $lock['forbidden_providers']);
        $this->assertContains('gemini_cli', $lock['forbidden_providers']);
        $this->assertSame('passed', $lock['quality_gate_required']);

        // no Fair Claude flag -> no lock
        $this->assertFalse($svc->fairClaudeLock(['--json'])['locked']);
    }

    public function test_fair_claude_gate_only_accepts_exact_passed(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->fairClaudeGateSatisfied('passed'));
        $this->assertFalse($svc->fairClaudeGateSatisfied('unverified')); // unverified does NOT count as pass
        $this->assertFalse($svc->fairClaudeGateSatisfied('failed'));
    }
}
